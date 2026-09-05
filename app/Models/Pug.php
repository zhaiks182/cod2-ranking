<?php

namespace App\Models;

use App\Support\TeamSideAnalyzer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una sesion de pug: equipos congelados + veto de mapas + las partidas que se
 * jugaron esa noche. Ver "Modulo de pugs" en CLAUDE.md.
 */
class Pug extends Model
{
    public const STATUS_AWAITING_CAPTAINS = 'awaiting_captains';
    public const STATUS_VETO = 'veto';
    public const STATUS_PLAYING = 'playing';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'server_id', 'status', 'teams',
        'team_a_captain_site_user_id', 'team_b_captain_site_user_id',
        'first_turn_team', 'veto_pool', 'veto_bans', 'maps', 'current_map_index',
        'started_at', 'ended_at',
    ];

    protected $casts = [
        'teams' => 'array',
        'veto_pool' => 'array',
        'veto_bans' => 'array',
        'maps' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'pug_id');
    }

    public function teamACaptain(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'team_a_captain_site_user_id');
    }

    public function teamBCaptain(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'team_b_captain_site_user_id');
    }

    /**
     * El pug que se esta jugando ahora en este servidor, si hay alguno. Lo consulta
     * el parser en cada creacion de partida, asi que se mantiene barato (un indice
     * sobre server_id+status).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_CLOSED);
    }

    public static function openFor(int $serverId): ?self
    {
        return self::open()->where('server_id', $serverId)->latest('id')->first();
    }

    /** @return array<int, int> guids del equipo pedido ('A' o 'B') */
    public function teamGuids(string $team): array
    {
        return array_map(
            fn ($p) => (int) $p['guid'],
            $this->teams[$team] ?? []
        );
    }

    /** @return array<int, string> codigos de mapa que siguen en pie */
    public function remainingMaps(): array
    {
        $banned = array_column($this->veto_bans ?? [], 'map');

        return array_values(array_diff($this->veto_pool ?? [], $banned));
    }

    /**
     * Cuantos mapas tienen que sobrevivir al veto. Se lee de la config del sitio,
     * pero nunca puede ser mayor que el pool con el que arranco este pug.
     */
    public function targetMapCount(): int
    {
        $poolSize = count($this->veto_pool ?? []);
        $target = min(max(1, (int) (Setting::current()->pug_maps_count ?? 3)), $poolSize);

        // Guarda de paridad. El panel de admin ya rechaza una config donde
        // (pool - mapas) sea impar, pero el pool POR DEFECTO (4 mapas) contra el
        // default de 3 mapas a jugar da 1 -- un capitan banearia una vez y el otro
        // ninguna. Bajar uno el objetivo es lo unico que mantiene el veto parejo
        // sin inventarle al admin una config que no eligio.
        if (($poolSize - $target) % 2 !== 0) {
            $target = max(1, $target - 1);
        }

        return $target;
    }

    public function vetoIsComplete(): bool
    {
        return count($this->remainingMaps()) <= $this->targetMapCount();
    }

    /**
     * A que equipo le toca banear. Alterna arrancando por `first_turn_team`, que se
     * sortea al abrir el veto -- fijar "siempre empieza A" le daria ventaja
     * sistematica a un lado.
     */
    public function currentTurnTeam(): ?string
    {
        if ($this->status !== self::STATUS_VETO || $this->vetoIsComplete()) {
            return null;
        }

        $bansSoFar = count($this->veto_bans ?? []);
        $other = $this->first_turn_team === 'A' ? 'B' : 'A';

        return $bansSoFar % 2 === 0 ? $this->first_turn_team : $other;
    }

    /** El equipo del que este site user es capitan, o null si no es capitan. */
    public function captainTeamFor(?SiteUser $siteUser): ?string
    {
        if (! $siteUser) {
            return null;
        }

        if ($this->team_a_captain_site_user_id === $siteUser->id) {
            return 'A';
        }

        return $this->team_b_captain_site_user_id === $siteUser->id ? 'B' : null;
    }

    /**
     * Si el jugador reclamado por esta cuenta esta en ese equipo. Es el requisito
     * para postularse de capitan: sin perfil reclamado no hay forma de saber en que
     * equipo juega.
     */
    public function siteUserPlaysIn(SiteUser $siteUser, string $team): bool
    {
        if (! $siteUser->player_id) {
            return false;
        }

        $guid = Player::whereKey($siteUser->player_id)->value('guid');

        return $guid !== null && in_array((int) $guid, $this->teamGuids($team), true);
    }

    public function currentMap(): ?string
    {
        return ($this->maps ?? [])[$this->current_map_index] ?? null;
    }

    public function nextMap(): ?string
    {
        return ($this->maps ?? [])[$this->current_map_index + 1] ?? null;
    }

    /**
     * Marcador de la sesion, DERIVADO de las partidas -- no hay contadores que
     * mantener sincronizados. Por cada partida del pug se cruzan los guids del
     * roster ganador contra el snapshot de equipos; una partida sin ganador
     * determinable (empate, rondas insuficientes) no cuenta para ninguno.
     *
     * Es a proposito el mismo criterio que WinRateCalculator usa por jugador. El
     * patron "acumulador + correccion retroactiva" ya causo tres bugs reales en
     * este proyecto (bitacora, entradas 13/15/16), y un pug tiene 2-6 partidas:
     * calcularlo al vuelo no cuesta nada y se autocorrige si se borra una partida.
     *
     * @return array{A: int, B: int}
     */
    public function scoreboard(): array
    {
        $score = ['A' => 0, 'B' => 0];
        $teamA = $this->teamGuids('A');
        $teamB = $this->teamGuids('B');

        foreach ($this->matches()->with('rounds')->get() as $match) {
            $winners = TeamSideAnalyzer::winningRosterGuids($match->rounds);

            if ($winners === null) {
                continue;
            }

            $winners = array_map('intval', $winners);

            if (array_intersect($winners, $teamA)) {
                $score['A']++;
            } elseif (array_intersect($winners, $teamB)) {
                $score['B']++;
            }
        }

        return $score;
    }
}
