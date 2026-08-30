<?php

namespace App\Support;

use App\Models\Player;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fusiona uno o mas jugadores "fuente" dentro de un jugador "destino" -- caso de
 * uso real: el mismo humano con varios guid distintos (HWID que cambia entre
 * sesiones, ver CLAUDE.md "MOKOS"/"Dav1Ds", 2026-08-28), confirmado a mano por un
 * admin via alias/chat/IP, nunca automatico.
 *
 * Mueve kills/demos/bans/chat (sin unique constraint, repunta la FK y listo) y
 * SUMA en vez de repuntar en las tablas con unique(player_id, X) --
 * player_map_stats, player_server_stats, player_weapon_picks,
 * player_match_extras, player_aliases -- porque el destino casi siempre ya tiene
 * su propia fila para el mismo mapa/server/arma/partida/alias, y repuntar sin
 * mas violaria esa constraint.
 *
 * guid/name historicos en kills/chat_messages/bans NO se reescriben -- son el
 * registro fiel de lo que el log realmente dijo en su momento, no la identidad
 * "actual" del jugador.
 */
class PlayerMerger
{
    public static function merge(array $sourcePlayerIds, int $targetPlayerId): Player
    {
        $sourcePlayerIds = array_values(array_unique(array_diff(
            array_map('intval', $sourcePlayerIds),
            [(int) $targetPlayerId]
        )));

        if ($sourcePlayerIds === []) {
            throw new InvalidArgumentException('No hay jugadores fuente para fusionar.');
        }

        return DB::transaction(function () use ($sourcePlayerIds, $targetPlayerId) {
            $target = Player::whereKey($targetPlayerId)->lockForUpdate()->firstOrFail();
            $sources = Player::whereIn('id', $sourcePlayerIds)->lockForUpdate()->get();

            foreach ($sources as $source) {
                DB::table('kills')->where('attacker_player_id', $source->id)->update(['attacker_player_id' => $target->id]);
                DB::table('kills')->where('victim_player_id', $source->id)->update(['victim_player_id' => $target->id]);
                DB::table('demos')->where('player_id', $source->id)->update(['player_id' => $target->id]);
                DB::table('bans')->where('player_id', $source->id)->update(['player_id' => $target->id]);
                DB::table('chat_messages')->where('player_id', $source->id)->update(['player_id' => $target->id]);

                // unique es (player_id, server_id, map) desde 2026-08-10 (multi-server),
                // no solo (player_id, map) como en la migracion original de la tabla.
                self::mergeAggregateRows('player_map_stats', ['server_id', 'map'], $source->id, $target->id,
                    ['kills', 'teamkills', 'deaths', 'headshots', 'grenade_kills']);
                self::mergeAggregateRows('player_server_stats', ['server_id'], $source->id, $target->id,
                    ['kills', 'teamkills', 'deaths', 'headshots', 'grenade_kills', 'suicides', 'bomb_plants', 'bomb_defuses', 'damage_dealt', 'damage_taken', 'mid_round_disconnects']);
                self::mergeAggregateRows('player_weapon_picks', ['weapon'], $source->id, $target->id, ['picks']);
                self::mergeAggregateRows('player_match_extras', ['match_id'], $source->id, $target->id,
                    ['bomb_plants', 'bomb_defuses', 'damage_dealt', 'damage_taken', 'mid_round_disconnects']);

                self::mergeAliases($source->id, $target->id);

                $target->kills_total += $source->kills_total;
                $target->deaths_total += $source->deaths_total;
                $target->headshots_total += $source->headshots_total;
                $target->grenade_kills_total += $source->grenade_kills_total;
                $target->suicides_total += $source->suicides_total;

                if ($source->first_seen_at && (! $target->first_seen_at || $source->first_seen_at->lt($target->first_seen_at))) {
                    $target->first_seen_at = $source->first_seen_at;
                }
                if ($source->last_seen_at && (! $target->last_seen_at || $source->last_seen_at->gt($target->last_seen_at))) {
                    $target->last_seen_at = $source->last_seen_at;
                }
            }

            $target->save();

            Player::whereIn('id', $sources->pluck('id'))->delete();

            return $target->fresh();
        });
    }

    /**
     * @param  string[]  $groupColumns  Columnas (ademas de player_id) que forman el unique de la tabla.
     * @param  string[]  $sumColumns  Columnas numericas a sumar cuando ya existe una fila del destino para ese grupo.
     */
    private static function mergeAggregateRows(string $table, array $groupColumns, int $sourceId, int $targetId, array $sumColumns): void
    {
        $rows = DB::table($table)->where('player_id', $sourceId)->get();

        foreach ($rows as $row) {
            $match = DB::table($table)->where('player_id', $targetId);
            foreach ($groupColumns as $column) {
                $match->where($column, $row->$column);
            }
            $existing = $match->first();

            if ($existing) {
                $updates = [];
                foreach ($sumColumns as $column) {
                    $updates[$column] = $existing->$column + $row->$column;
                }
                DB::table($table)->where('id', $existing->id)->update($updates);
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                DB::table($table)->where('id', $row->id)->update(['player_id' => $targetId]);
            }
        }
    }

    /**
     * Unique de player_aliases es (player_id, name_plain) desde 2026-08-10 (ver
     * migracion dedupe_player_aliases), no (player_id, name) como en la creacion
     * original -- comparar por `name` deja pasar dos alias que solo difieren en
     * el codigo de color (^N) como si no fueran duplicados, y el intento de
     * repuntar el player_id del source termina violando la unique real.
     */
    private static function mergeAliases(int $sourceId, int $targetId): void
    {
        $aliases = DB::table('player_aliases')->where('player_id', $sourceId)->get();

        foreach ($aliases as $alias) {
            $existing = DB::table('player_aliases')->where('player_id', $targetId)->where('name_plain', $alias->name_plain)->first();

            if ($existing) {
                if ($alias->last_seen_at > $existing->last_seen_at) {
                    DB::table('player_aliases')->where('id', $existing->id)->update(['last_seen_at' => $alias->last_seen_at]);
                }
                DB::table('player_aliases')->where('id', $alias->id)->delete();
            } else {
                DB::table('player_aliases')->where('id', $alias->id)->update(['player_id' => $targetId]);
            }
        }
    }
}
