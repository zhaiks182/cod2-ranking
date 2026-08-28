<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\GameMatch;
use App\Models\Kill;
use App\Models\LogParserState;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\PlayerMapStat;
use App\Models\PlayerMatchExtra;
use App\Models\PlayerServerStat;
use App\Models\PlayerWeaponPick;
use App\Models\Round;
use App\Models\Season;
use App\Models\Server;
use App\Services\Cod2RconClient;
use App\Support\Cod2Colors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParseCod2Log extends Command
{
    protected $signature = 'cod2:parse-log {--server= : Only parse this server ID} {--from-start : Ignore the stored offset and reparse the whole file}';

    protected $description = 'Parse newly appended lines from each active server\'s games_mp.log into the stats database';

    public function handle(): int
    {
        $servers = Server::where('is_active', true)
            ->when($this->option('server'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($servers->isEmpty()) {
            $this->warn('No active servers configured.');

            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            $this->parseServer($server);
            $this->syncLiveNames($server);
        }

        return self::SUCCESS;
    }

    private function parseServer(Server $server): void
    {
        if (! is_readable($server->log_path)) {
            $this->error("[{$server->name}] Cannot read log file: {$server->log_path}");

            return;
        }

        // Schedule::withoutOverlapping() only guards the scheduler's own invocation --
        // it does nothing against a manually-run `php artisan cod2:parse-log` racing the
        // live cron. Confirmed happening for real (2026-08-24): repeated manual runs
        // during a live debugging session raced the per-minute cron, and the loser of
        // the race started from a stale current_round_id/current_match_id (overwritten
        // by the winner's earlier state) -- every kill it "parsed" then hit the
        // between-round discard in recordKill() (that round already had ended_at set),
        // silently dropping ~66% of a player's real kills for that match even though
        // the raw log lines were never lost. This lock makes a second concurrent run
        // (any source) skip instead of racing, whatever the trigger.
        $lock = Cache::lock("cod2:parse-log:server:{$server->id}", 120);

        if (! $lock->get()) {
            $this->warn("[{$server->name}] Another cod2:parse-log run is already in progress for this server, skipping.");

            return;
        }

        try {
            $this->parseServerLocked($server);
        } finally {
            $lock->release();
        }
    }

    private function parseServerLocked(Server $server): void
    {
        $state = LogParserState::firstOrCreate(
            ['server_id' => $server->id],
            ['log_path' => $server->log_path, 'byte_offset' => 0]
        );

        $offset = $this->option('from-start') ? 0 : $state->byte_offset;

        // The game server truncates/rotates the log on restart, not append-only forever —
        // if it's now smaller than our stored offset, start over from the top.
        if (filesize($server->log_path) < $offset) {
            $offset = 0;
        }

        $handle = fopen($server->log_path, 'r');
        fseek($handle, $offset);

        $currentRound = $state->current_round_id ? Round::find($state->current_round_id) : null;
        $currentMatch = $state->current_match_id ? GameMatch::find($state->current_match_id) : null;
        $pendingMap = $state->pending_map;
        $pendingGametype = $state->pending_gametype;
        $pendingMatchInfo = $state->pending_match_info;
        $linesProcessed = 0;

        DB::transaction(function () use ($handle, $server, &$currentRound, &$currentMatch, &$pendingMap, &$pendingGametype, &$pendingMatchInfo, &$linesProcessed) {
            while (($line = fgets($handle)) !== false) {
                // A concurrent writer may leave a partial final line; stop and pick it
                // up on the next run instead of parsing a truncated record.
                if (! str_ends_with($line, "\n") && ! feof($handle)) {
                    break;
                }

                $this->processLine(rtrim($line, "\r\n"), $server, $currentRound, $currentMatch, $pendingMap, $pendingGametype, $pendingMatchInfo);
                $linesProcessed++;
            }
        });

        $finalOffset = ftell($handle);
        fclose($handle);

        $state->update([
            'byte_offset' => $finalOffset,
            'current_round_id' => $currentRound?->id,
            'current_match_id' => $currentMatch?->id,
            'pending_map' => $pendingMap,
            'pending_gametype' => $pendingGametype,
            'pending_match_info' => $pendingMatchInfo,
        ]);

        $this->info("[{$server->name}] Processed {$linesProcessed} new line(s).");
    }

    private function processLine(string $line, Server $server, ?Round &$currentRound, ?GameMatch &$currentMatch, ?string &$pendingMap, ?string &$pendingGametype, ?string &$pendingMatchInfo): void
    {
        // CoD2 right-pads the elapsed-time field to a fixed width with leading spaces
        // for anything under 100 minutes (e.g. "  2:37" vs "247:56") — the anchor must
        // allow that or every short-uptime line gets silently dropped.
        if (! preg_match('/^\s*\d+:\d+(?::\d+)?\s+(.*)$/', $line, $m)) {
            return;
        }

        $rest = $m[1];

        if (str_starts_with($rest, 'InitGame:')) {
            $info = $this->parseInfoString(trim(substr($rest, strlen('InitGame:'))));
            $pendingMap = $info['mapname'] ?? 'unknown';
            $pendingGametype = $info['g_gametype'] ?? null;
            $pendingMatchInfo = $info['_match_info'] ?? null;

            // Whatever was previously running is over even if it never got a proper
            // RoundEnd (e.g. a round that stayed in the ready-up lobby and never started).
            if ($currentRound && ! $currentRound->ended_at) {
                $currentRound->update(['ended_at' => now()]);
            }
        } elseif (str_starts_with($rest, 'RoundStart;')) {
            // "strat" is zPAM's pre-round strategy/planning phase, not real gameplay —
            // confirmed a "strat" RoundStart; on Railyard created a 1-round "match" that
            // was just clutter, not an actual game played. Don't track it as a match at
            // all (unlike "dm", which IS a real, supported gametype — see recordKill()'s
            // fallback). $currentRound is cleared too, so any stray Kill;/Damage; lines
            // during the strat phase don't get misattributed to whatever real round was
            // open before it.
            if ($pendingGametype === 'strat') {
                $currentRound = null;

                return;
            }

            // "Round 0" in the InitGame:'s _match_info is zPAM's ready-up lobby, not
            // gameplay -- confirmed live (2026-08-24, match id 89): a RoundStart; fired
            // for "Round 0 | MR12 Ready-up" with BOTH teams empty (nobody connected yet),
            // and got recorded as a real 1-round, 0-kill match. Unlike the "strat" check
            // above, this isn't gametype-specific -- Round 0 precedes every gametype's
            // real Round 1, so RoundStart; during it must never open a match/round no
            // matter what g_gametype says. A real round's _match_info reads "Round 1 | ..."
            // (no "Round 0" prefix), so this only excludes the ready-up phase itself.
            if ($pendingMatchInfo !== null && str_starts_with($pendingMatchInfo, 'Round 0')) {
                $currentRound = null;

                return;
            }

            // Only a real RoundStart; creates a match/round — InitGame: alone just means
            // the lobby loaded, which happens on every ready-up cycle and would otherwise
            // spam a match per cycle even if nobody ever readies up.
            $currentRound = $this->openRound($server, $pendingMap ?? 'unknown', $pendingGametype, $currentRound, $currentMatch);
        } elseif (str_starts_with($rest, 'Kill;')) {
            $this->recordKill(substr($rest, strlen('Kill;')), $server, $currentRound, $currentMatch, $pendingMap, $pendingGametype);
        } elseif (str_starts_with($rest, 'Connected;')) {
            $this->recordConnect(substr($rest, strlen('Connected;')));
        } elseif (str_starts_with($rest, 'Disconnected;')) {
            $this->recordDisconnect(substr($rest, strlen('Disconnected;')), $server, $currentRound);
        } elseif (str_starts_with($rest, 'Bomb;')) {
            $this->recordBomb(substr($rest, strlen('Bomb;')), $server, $currentRound);
        } elseif (str_starts_with($rest, 'Damage;')) {
            $this->recordDamage(substr($rest, strlen('Damage;')), $server, $currentRound);
        } elseif (str_starts_with($rest, 'Weapon;')) {
            $this->recordWeaponPick(substr($rest, strlen('Weapon;')));
        } elseif (str_starts_with($rest, 'RoundInfo;')) {
            $this->recordRoundInfo(substr($rest, strlen('RoundInfo;')), $currentRound);
        } elseif (str_starts_with($rest, 'Score;')) {
            $this->recordScore(substr($rest, strlen('Score;')), $currentRound);
        } elseif (str_starts_with($rest, 'say;')) {
            $this->recordChat(substr($rest, strlen('say;')), $server, $currentMatch, 'public');
        } elseif (str_starts_with($rest, 'sayteam;')) {
            $this->recordChat(substr($rest, strlen('sayteam;')), $server, $currentMatch, 'team');
        } elseif (str_starts_with($rest, 'Winners;')) {
            // Fires right after RoundEnd; with the winning roster (side swaps at
            // halftime, but the roster's guids don't) — used to compute the match's
            // final score without needing to track which roster is currently which side.
            $this->recordRoundWinner(substr($rest, strlen('Winners;')), $currentRound);
        } elseif (str_starts_with($rest, 'RoundEnd;') || str_starts_with($rest, 'ShutdownGame:')) {
            if ($currentRound && ! $currentRound->ended_at) {
                $currentRound->update(['ended_at' => now()]);
            }
            if ($currentMatch && ! $currentMatch->ended_at) {
                $currentMatch->update(['ended_at' => now()]);
            }
        } elseif (str_starts_with($rest, 'HalfTime;')) {
            // Fires right after the round-12 RoundEnd;/Winners; (score already at
            // 12 rounds played) and before the InitGame:/RoundStart; of round 13 —
            // i.e. $currentRound here is still round 12, the LAST round of the first
            // half. Replaces the old "assume round 13 is halftime" heuristic
            // (CLAUDE.md) with the server's own authoritative signal.
            $this->recordMatchEvent($server, $currentMatch, $currentRound, 'halftime');
        } elseif (str_starts_with($rest, 'OverTime;')) {
            $this->recordMatchEvent($server, $currentMatch, $currentRound, 'overtime');
        } elseif (str_starts_with($rest, 'MatchEnd;')) {
            $this->recordMatchEvent($server, $currentMatch, $currentRound, 'match_end');
        } elseif (str_starts_with($rest, 'TO_CALL;')) {
            $this->recordSideEvent(substr($rest, strlen('TO_CALL;')), $server, $currentMatch, $currentRound, 'timeout_call');
        } elseif (str_starts_with($rest, 'TO_CANCEL;')) {
            $this->recordSideEvent(substr($rest, strlen('TO_CANCEL;')), $server, $currentMatch, $currentRound, 'timeout_cancel');
        } elseif (str_starts_with($rest, 'BASH_CALL;')) {
            // Only ever observed once in practice — exact meaning unconfirmed, but
            // it's a player-attributed "<side>;<name>" call just like TO_CALL;, so
            // it's captured the same way rather than built into its own feature.
            $this->recordSideEvent(substr($rest, strlen('BASH_CALL;')), $server, $currentMatch, $currentRound, 'bash_call');
        }
    }

    private function openRound(Server $server, string $map, ?string $gametype, ?Round $currentRound, ?GameMatch &$currentMatch): Round
    {
        if ($currentRound && ! $currentRound->ended_at) {
            $currentRound->update(['ended_at' => now()]);
        }

        // A new match starts whenever the map (or gametype) changes from the currently
        // open one — consecutive rounds on the same map belong to the same match, so a
        // day of play across 3 different maps produces 3 matches, not one giant blob.
        // Also split on a long gap even on the *same* map/gametype (e.g. players come
        // back to finish/practice on a map hours later) — otherwise that later session
        // silently glues onto the old match and its duration/score become nonsense
        // (a real "22h 6min" match happened this way on 2026-08-10).
        $gapTooLong = $currentMatch && $currentMatch->ended_at
            && $currentMatch->ended_at->diffInMinutes(now()) > 30;

        if (! $currentMatch || $currentMatch->map !== $map || $currentMatch->gametype !== $gametype || $gapTooLong) {
            if ($currentMatch && ! $currentMatch->ended_at) {
                $currentMatch->update(['ended_at' => now()]);
            }

            $currentMatch = GameMatch::create([
                'server_id' => $server->id,
                'season_id' => Season::current()->id,
                'map' => $map,
                'gametype' => $gametype,
                'started_at' => now(),
            ]);
        } elseif ($currentMatch->ended_at) {
            // Continuing the same match: a previous RoundEnd/ShutdownGame marked it
            // ended, but a new round just started, so it's clearly still going —
            // otherwise ended_at stays frozen at round 1's end for the whole match.
            $currentMatch->update(['ended_at' => null]);
        }

        return Round::create([
            'server_id' => $server->id,
            'match_id' => $currentMatch->id,
            'map' => $map,
            'gametype' => $gametype,
            'started_at' => now(),
        ]);
    }

    /**
     * Parses the Quake-style "\key\value\key\value" info string used in InitGame lines.
     */
    private function parseInfoString(string $s): array
    {
        $parts = explode('\\', ltrim($s, '\\'));
        $out = [];

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $out[$parts[$i]] = $parts[$i + 1];
        }

        return $out;
    }

    private function recordKill(string $payload, Server $server, ?Round &$currentRound, ?GameMatch &$currentMatch, ?string $pendingMap, ?string $pendingGametype): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 12) {
            Log::warning('cod2: unexpected Kill; field count', ['server_id' => $server->id, 'payload' => $payload]);

            return;
        }

        // Confirmed against two real matches (in-game final scoreboards) that the first
        // person block in a Kill; line is the victim and the second is the attacker —
        // opposite of what an early bot-kill test suggested. Bots may have been special-
        // cased differently by the mod; real player-vs-player kills use this order.
        [$vGuid, , $vName, $vTeam, $aGuid, , $aName, $aTeam, $weapon, $damage, $mod, $hitloc] = $data;

        $aGuid = (int) $aGuid;
        $vGuid = (int) $vGuid;
        $aName = $this->toUtf8($aName);
        $vName = $this->toUtf8($vName);

        if (! $currentRound || ! $currentMatch) {
            // A kill with no open round (e.g. parser started mid-round, or the gametype
            // allows kills before a formal RoundStart;, like DM) — open one from
            // whatever map info is known rather than dropping the kill.
            $currentRound = $this->openRound($server, $pendingMap ?? 'unknown', $pendingGametype, $currentRound, $currentMatch);
        } elseif ($currentRound->ended_at) {
            if ($pendingGametype === $currentRound->gametype) {
                // Same match's ready-up gap (or SD's aim-trainer warmup) between a round
                // ending and the next RoundStart; — a real Kill; line, but not part of any
                // actual match. Discard it instead of misattributing it to the round that
                // just ended.
                return;
            }

            // Bug found live 2026-08-28 (reported by players: DM kills counting toward
            // the SD ranking): the pendingGametype !== 'dm' check this used to have let a
            // real gametype switch fall through here silently. DM (and possibly other
            // modes) never send RoundStart; at all, so a Kill; is the ONLY signal that a
            // new session started — reusing the old, ended SD round attributed hours of
            // later DM kills to it (round_id=1555, match_id=107 in production: 86 DM
            // kills from a session 20 hours after the SD match's own started_at/ended_at
            // got counted as SD kills/deaths for hardoso and Shyne, confirmed against
            // games_mp.log). Opening a fresh round here — same call the RoundStart;
            // path already uses — makes openRound() create a new match with the actual
            // pending gametype instead.
            $currentRound = $this->openRound($server, $pendingMap ?? 'unknown', $pendingGametype, $currentRound, $currentMatch);
        }

        $isSuicide = $mod === 'MOD_SUICIDE' || ($aGuid === $vGuid && $aName === $vName);
        $isTeamkill = ! $isSuicide && $aTeam === $vTeam;
        $isHeadshot = str_contains($mod, 'HEAD');
        $isGrenade = str_starts_with($weapon, 'frag_grenade') || str_contains($mod, 'GRENADE');

        $attacker = $this->upsertPlayer($aGuid, $aName);
        $victim = $this->upsertPlayer($vGuid, $vName);

        Kill::create([
            'round_id' => $currentRound->id,
            'match_id' => $currentMatch->id,
            'attacker_player_id' => $attacker?->id,
            'attacker_guid' => $aGuid,
            'attacker_name' => $aName,
            'attacker_team' => $aTeam,
            'victim_player_id' => $victim?->id,
            'victim_guid' => $vGuid,
            'victim_name' => $vName,
            'victim_team' => $vTeam,
            'weapon' => $weapon,
            'damage' => (int) $damage,
            'mod' => $mod,
            'hitloc' => $hitloc,
            'is_headshot' => $isHeadshot,
            'is_grenade' => $isGrenade,
            'is_suicide' => $isSuicide,
            'is_teamkill' => $isTeamkill,
            'occurred_at' => now(),
        ]);

        // The ranking (players.*_total, player_map_stats, player_server_stats) only
        // makes sense as a competitive Search & Destroy leaderboard — a DM/HQ/CTF
        // session shouldn't inflate it. The raw `kills` row above is still saved
        // either way, so a non-SD match's own page still shows what happened in it.
        $countsTowardRanking = $currentRound->gametype === 'sd';

        if ($victim) {
            if ($countsTowardRanking) {
                $victim->increment('deaths_total');
                if ($isSuicide) {
                    $victim->increment('suicides_total');
                }
                $this->bumpMapStat($victim, $server->id, $currentRound->map, deaths: 1);
                $this->bumpServerStat($victim, $server->id, deaths: 1, suicides: $isSuicide ? 1 : 0);
            }
        }

        // NOTE: is_teamkill is tracked (see column) but NOT excluded from kills_total —
        // tested against a real match's final in-game scoreboard and a teamkill via a
        // direct weapon (rifle) *did* count toward the player's Score there, disproving
        // the original assumption that zPAM excludes all friendly fire. Whatever zPAM
        // actually excludes (possibly just MOD_GRENADE_SPLASH?) isn't confirmed yet —
        // see CLAUDE.md before re-attempting this.
        if ($attacker && ! $isSuicide && $countsTowardRanking) {
            $attacker->increment('kills_total');
            if ($isHeadshot) {
                $attacker->increment('headshots_total');
            }
            if ($isGrenade) {
                $attacker->increment('grenade_kills_total');
            }
            $this->bumpMapStat($attacker, $server->id, $currentRound->map, kills: 1, headshots: $isHeadshot ? 1 : 0, grenadeKills: $isGrenade ? 1 : 0, teamkills: $isTeamkill ? 1 : 0);
            $this->bumpServerStat($attacker, $server->id, kills: 1, headshots: $isHeadshot ? 1 : 0, grenadeKills: $isGrenade ? 1 : 0, teamkills: $isTeamkill ? 1 : 0);
        }
    }

    private function recordRoundWinner(string $payload, ?Round $currentRound): void
    {
        if (! $currentRound) {
            return;
        }

        // Format: "<side>;<guid>;<name>;<guid>;<name>;..." — side (axis/allies) is
        // discarded, only the roster's guids matter (see the field comment above).
        $fields = explode(';', $payload);
        $guids = [];

        for ($i = 1; $i < count($fields); $i += 2) {
            $guids[] = (int) $fields[$i];
        }

        if ($guids) {
            $currentRound->update(['winner_guids' => $guids]);
        }
    }

    private function recordConnect(string $payload): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 3) {
            return;
        }

        [$guid, , $name] = $data;
        $this->upsertPlayer((int) $guid, $name);
    }

    /**
     * "Rage quit" signal: disconnecting while the round they were playing is still
     * open (not yet ended). A disconnect between rounds (ready-up, map change) is
     * normal and not counted — only mid-round matters.
     */
    private function recordDisconnect(string $payload, Server $server, ?Round $currentRound): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 3 || ! $currentRound || $currentRound->ended_at || $currentRound->gametype !== 'sd') {
            return;
        }

        [$guid, , $name] = $data;
        $player = $this->upsertPlayer((int) $guid, $name);

        if ($player) {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, midRoundDisconnects: 1);
        }
    }

    /** Format: "<guid>;<slot>;<side>;<name>;bomb_plant|bomb_defuse". */
    private function recordBomb(string $payload, Server $server, ?Round $currentRound): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 5 || ! $currentRound || $currentRound->gametype !== 'sd') {
            return;
        }

        [$guid, , , $name, $action] = $data;
        $player = $this->upsertPlayer((int) $guid, $this->toUtf8($name));

        if (! $player) {
            return;
        }

        if ($action === 'bomb_plant') {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, bombPlants: 1);
        } elseif ($action === 'bomb_defuse') {
            $this->bumpServerStatExtra($player, $server->id, $currentRound->match_id, bombDefuses: 1);
        }
    }

    /** Same 12-field shape as Kill; — every hit, not just the killing blow. */
    private function recordDamage(string $payload, Server $server, ?Round $currentRound): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 12 || ! $currentRound || $currentRound->gametype !== 'sd') {
            return;
        }

        [$vGuid, , $vName, , $aGuid, , $aName, , , $damage] = $data;
        $aGuid = (int) $aGuid;
        $vGuid = (int) $vGuid;

        if ($aGuid === $vGuid || $aGuid === 0) {
            return; // self-damage / bot attacker — not attributable to a tracked player
        }

        $attacker = $this->upsertPlayer($aGuid, $this->toUtf8($aName));
        if ($attacker) {
            $this->bumpServerStatExtra($attacker, $server->id, $currentRound->match_id, damageDealt: (int) $damage);
        }

        $victim = $this->upsertPlayer($vGuid, $this->toUtf8($vName));
        if ($victim) {
            $this->bumpServerStatExtra($victim, $server->id, $currentRound->match_id, damageTaken: (int) $damage);
        }
    }

    /** Format: "<guid>;<slot>;<name>;<weapon>" — a pickup/switch, not a kill. */
    private function recordWeaponPick(string $payload): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 4) {
            return;
        }

        [$guid, , $name, $weapon] = $data;
        $player = $this->upsertPlayer((int) $guid, $this->toUtf8($name));

        if (! $player) {
            return;
        }

        $pick = PlayerWeaponPick::firstOrCreate([
            'player_id' => $player->id,
            'weapon' => $weapon,
            'season_id' => Season::current()->id,
        ]);
        $pick->increment('picks');
    }

    /** Format: "score:<allies>:<axis>;round:<n>" — fires right after RoundStart;. */
    private function recordRoundInfo(string $payload, ?Round $currentRound): void
    {
        if (! $currentRound || ! preg_match('/^score:(\d+):(\d+);round:(\d+)$/', $payload, $m)) {
            return;
        }

        $currentRound->update(['round_number' => (int) $m[3]]);
    }

    /** Format: "allies;<n>;axis;<n>" — fires right after RoundEnd;/Winners;, the running score as of the round that just ended. */
    private function recordScore(string $payload, ?Round $currentRound): void
    {
        $data = explode(';', $payload);

        if (count($data) !== 4 || ! $currentRound) {
            return;
        }

        [, $allies, , $axis] = $data;
        $currentRound->update(['score_after_allies' => (int) $allies, 'score_after_axis' => (int) $axis]);
    }

    /**
     * Format: "<guid>;<slot>;<name>;<message>" — same shape for both "say;" (public,
     * $channel='public') and "sayteam;" (team chat, $channel='team'); neither line
     * carries which side sent it, so team messages are bucketed into axis/allies at
     * display time using the match's current per-player side map (same one the
     * axis/allies leaderboard panels already use), not stored here. Limited to 4
     * parts (not exploded on every ";") so a message that itself contains a literal
     * ";" doesn't get truncated. Attached to $currentMatch if one is open — chat
     * during ready-up before the first RoundStart; (no match yet) is simply not
     * stored, since there's no match page for it to show up on.
     */
    private function recordChat(string $payload, Server $server, ?GameMatch $currentMatch, string $channel): void
    {
        $data = explode(';', $payload, 4);

        if (count($data) !== 4) {
            return;
        }

        [$guid, , $name, $message] = $data;
        $guid = (int) $guid;
        // CoD2 prefixes a chat line with a control byte (0x15, seen on real messages)
        // for the in-game "talk bubble" icon — not part of what the player typed, and
        // renders as an invisible/garbage glyph if left in.
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $this->toUtf8($message)));
        $name = $this->toUtf8($name);

        // Radio/quick-message shortcuts (bound to a key, mostly used in team chat) log
        // as their raw untranslated token — "QUICKMESSAGE_SORRY", "QUICKMESSAGE_YES_SIR"
        // — never the localized text a player actually sees in-game. Not real typed
        // chat, so it's noise here rather than content worth keeping.
        if (str_starts_with($message, 'QUICKMESSAGE')) {
            return;
        }

        if ($guid === 0 || $message === '' || ! $currentMatch) {
            return;
        }

        $player = $this->upsertPlayer($guid, $name);

        ChatMessage::create([
            'server_id' => $server->id,
            'match_id' => $currentMatch->id,
            'player_id' => $player?->id,
            'guid' => $guid,
            'name' => $name,
            'message' => $message,
            'channel' => $channel,
            'occurred_at' => now(),
        ]);
    }

    /** Server-wide markers with no associated player (halftime, overtime, match_end). */
    private function recordMatchEvent(Server $server, ?GameMatch $currentMatch, ?Round $currentRound, string $type): void
    {
        if (! $currentMatch) {
            return;
        }

        MatchEvent::create([
            'server_id' => $server->id,
            'match_id' => $currentMatch->id,
            'round_id' => $currentRound?->id,
            'event_type' => $type,
            'occurred_at' => now(),
        ]);
    }

    /** Format: "<side>;<name>" — timeout_call, timeout_cancel, bash_call. */
    private function recordSideEvent(string $payload, Server $server, ?GameMatch $currentMatch, ?Round $currentRound, string $type): void
    {
        if (! $currentMatch) {
            return;
        }

        $data = explode(';', $payload, 2);
        $side = $data[0] ?? null;
        $name = isset($data[1]) ? $this->toUtf8($data[1]) : null;

        MatchEvent::create([
            'server_id' => $server->id,
            'match_id' => $currentMatch->id,
            'round_id' => $currentRound?->id,
            'event_type' => $type,
            'side' => $side,
            'name' => $name,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The game client encodes accented/special characters in chat as Windows-1252,
     * not UTF-8 (confirmed against a real message — "á" arrived as the single byte
     * 0xE1, invalid standalone UTF-8), which MySQL's utf8mb4 column rejects outright
     * ("Incorrect string value"). Kill;/Connected; names haven't hit this in
     * practice (usually ASCII + color codes), but chat is free text people actually
     * type accents into — convert only when the bytes aren't already valid UTF-8, so
     * ASCII/already-UTF-8 text passes through untouched.
     */
    private function toUtf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /**
     * The engine only writes to games_mp.log on gameplay events (connect, kill, ...) —
     * a player who renames mid-session without reconnecting or getting a kill/death
     * produces no log line at all, so their new name would never be picked up from the
     * log alone. RCON's "status" reply always reflects whoever is connected *right now*,
     * so polling it here catches those renames too.
     */
    private function syncLiveNames(Server $server): void
    {
        $status = Cod2RconClient::forServer($server)->status();

        foreach ($status['players'] ?? [] as $p) {
            if ($p['guid'] !== 0) {
                $this->upsertPlayer($p['guid'], $p['name'], $p['ip'] ?? null);
            }
        }
    }

    /**
     * Bots always report guid=0 (no HWID) and are indistinguishable from each other,
     * so they're excluded from the players table entirely — see players migration.
     * Players are global (not per-server): the HWID is tied to real hardware, so the
     * same person is the same row no matter which of our servers they play on.
     */
    private function upsertPlayer(int $guid, string $name, ?string $ip = null): ?Player
    {
        if ($guid === 0) {
            return null;
        }

        // A malformed/truncated RCON "status" row (same family of bug as the
        // 2026-08-10 ZHAIKS incident below) can put an arbitrary numeric-looking
        // token where the guid should be. A real CoD2x guid is always a signed
        // 32-bit FNV-1a hash, so anything outside that range is garbage from a bad
        // parse, never a real player -- reject it here instead of letting MySQL's
        // "Out of range value for column 'guid'" crash the whole cod2:parse-log run.
        // Confirmed happening silently since 2026-08-14: syncLiveNames() throwing
        // only aborts that tail step, parseServer() already ran and committed the
        // real log data fine, so this was invisible on the site itself -- only
        // storage/logs/laravel.log showed it, once a minute, every minute.
        if ($guid < -2147483648 || $guid > 2147483647) {
            return null;
        }

        // Centralized here (not left to each caller) because several call sites —
        // recordKill's attacker/victim, recordConnect, recordDisconnect — were found
        // passing the raw log/RCON bytes straight through, which let a Windows-1252
        // name (e.g. one with ®/©-range bytes) reach MySQL unconverted and crash the
        // insert (utf8mb4 strict mode), wedging the parser on that exact log line.
        $name = $this->toUtf8($name);
        $plain = Cod2Colors::stripColors($name);

        // Same bug family as the guards above (2026-08-14 out-of-range, 2026-08-19
        // ping-missing, 2026-08-22 color-loss) -- one more digit of the guid lost or
        // altered in transit, but still landing inside the valid int32 range so
        // those guards miss it. Confirmed 2026-08-28: ~27 phantom zero-kill `players`
        // rows in production, each guid a 1-2 character edit away from a real,
        // currently-active player's guid, same stripped name, seen minutes apart.
        // guid is FNV-1a of the HWID2 string (see HwidHasher) -- a genuinely
        // different piece of hardware produces an avalanche-scrambled, unrelated
        // 32-bit number, never "the same digits minus one". Two different real
        // players coincidentally sharing both a name AND a near-identical guid
        // within minutes of each other isn't realistic -- treat the reading as the
        // known player instead of minting a junk row for it. Deliberately not
        // filtered by IP: shared/CGNAT/VPN connections make the same IP normal for
        // two different real people, so IP can't be used to narrow this down.
        if ($plain !== '') {
            $recent = Player::where('last_name_plain', $plain)
                ->where('guid', '!=', $guid)
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->get()
                ->first(fn (Player $p) => levenshtein((string) $p->guid, (string) $guid) <= 2);

            if ($recent) {
                $guid = $recent->guid;
            }
        }

        $player = Player::firstOrNew(['guid' => $guid]);
        $isNew = ! $player->exists;

        // Un nombre entrante SIN codigos de color (^N) que trae el mismo texto visible
        // que el que ya esta guardado CON colores es casi seguro un dato mal parseado
        // (misma familia que el incidente ZHAIKS del 2026-08-10 de abajo), no un cambio
        // real de nombre -- confirmado en vivo 2026-08-22: una fila de status/log le
        // piso a un jugador real su nombre bien coloreado con la version en texto
        // plano, y quedo asi hasta que se detecto a mano. Se ignora el nuevo valor en
        // ese caso puntual para no perder el formato.
        $incomingHasColors = $name !== $plain;
        $existingHasColors = $player->last_name && $player->last_name !== Cod2Colors::stripColors($player->last_name);
        $looksLikeColorLoss = ! $isNew && ! $incomingHasColors && $existingHasColors && $plain === $player->last_name_plain;

        // A name with zero visible characters (just color codes, e.g. "^7") is never a
        // real rename — it's what a truncated/misparsed RCON "status" row looks like
        // (see the 2026-08-10 ZHAIKS incident). Keep whatever good name is already on
        // file rather than clobbering it with garbage; still record that we saw them.
        if (($plain !== '' || $isNew) && ! $looksLikeColorLoss) {
            $player->last_name = $name;
            $player->last_name_plain = $plain ?: $name;
        }
        // Only known from live RCON "status" polls (syncLiveNames), not from log
        // lines (Kill;/Connected; don't carry it) — so most calls leave this null and
        // the previously-stored IP (if any) is left untouched.
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $player->ip = $ip;
        }
        $player->last_seen_at = now();
        if ($isNew) {
            $player->first_seen_at = now();
        }
        $player->save();

        // Keyed by the color-stripped name, not the raw one — the same visible name can
        // arrive with slightly different raw color codes (e.g. a stray trailing "^7"
        // reset) depending on whether it came from the log or a live RCON poll, which
        // otherwise created a duplicate "alias" for what a player sees as one name.
        if ($plain !== '' || $isNew) {
            PlayerAlias::updateOrCreate(
                ['player_id' => $player->id, 'name_plain' => $plain ?: $name],
                ['name' => $name, 'last_seen_at' => now()]
            );
        }

        return $player;
    }

    private function bumpMapStat(Player $player, int $serverId, string $map, int $kills = 0, int $deaths = 0, int $headshots = 0, int $grenadeKills = 0, int $teamkills = 0): void
    {
        $stat = PlayerMapStat::firstOrCreate(['player_id' => $player->id, 'server_id' => $serverId, 'map' => $map]);

        $stat->kills += $kills;
        $stat->deaths += $deaths;
        $stat->headshots += $headshots;
        $stat->grenade_kills += $grenadeKills;
        $stat->teamkills += $teamkills;
        $stat->save();
    }

    private function bumpServerStat(Player $player, int $serverId, int $kills = 0, int $deaths = 0, int $headshots = 0, int $grenadeKills = 0, int $suicides = 0, int $teamkills = 0): void
    {
        $stat = PlayerServerStat::firstOrCreate(['player_id' => $player->id, 'server_id' => $serverId]);

        $stat->kills += $kills;
        $stat->deaths += $deaths;
        $stat->headshots += $headshots;
        $stat->grenade_kills += $grenadeKills;
        $stat->suicides += $suicides;
        $stat->teamkills += $teamkills;
        $stat->save();
    }

    private function bumpServerStatExtra(
        Player $player,
        int $serverId,
        ?int $matchId = null,
        int $bombPlants = 0,
        int $bombDefuses = 0,
        int $damageDealt = 0,
        int $damageTaken = 0,
        int $midRoundDisconnects = 0,
    ): void {
        $stat = PlayerServerStat::firstOrCreate(['player_id' => $player->id, 'server_id' => $serverId]);

        $stat->bomb_plants += $bombPlants;
        $stat->bomb_defuses += $bombDefuses;
        $stat->damage_dealt += $damageDealt;
        $stat->damage_taken += $damageTaken;
        $stat->mid_round_disconnects += $midRoundDisconnects;
        $stat->save();

        if ($matchId === null) {
            return;
        }

        $extra = PlayerMatchExtra::firstOrCreate(['player_id' => $player->id, 'match_id' => $matchId]);
        $extra->bomb_plants += $bombPlants;
        $extra->bomb_defuses += $bombDefuses;
        $extra->damage_dealt += $damageDealt;
        $extra->damage_taken += $damageTaken;
        $extra->mid_round_disconnects += $midRoundDisconnects;
        $extra->save();
    }
}
