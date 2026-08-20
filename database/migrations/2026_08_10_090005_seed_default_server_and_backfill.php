<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serverId = DB::table('servers')->insertGetId([
            'name' => 'Pug Latam',
            'slug' => 'pug-latam',
            'log_path' => config('cod2.log_path'),
            'rcon_host' => config('cod2.rcon.host'),
            'rcon_port' => config('cod2.rcon.port'),
            // Server::$casts treats this column as 'encrypted' — insertGetId() is a
            // raw query, so it bypasses that cast and would otherwise store the
            // password in plain text, which then fails to decrypt (DecryptException)
            // the moment anything reads it back through the Eloquent model (e.g.
            // Cod2RconClient::forServer(), called every minute by cod2:parse-log).
            'rcon_password' => Crypt::encryptString(config('cod2.rcon.password')),
            'connect_ip' => config('cod2.connect_ip'),
            'connect_port' => config('cod2.connect_port'),
            'max_clients' => config('cod2.max_clients'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rounds')->update(['server_id' => $serverId]);
        DB::table('player_map_stats')->update(['server_id' => $serverId]);
        DB::table('log_parser_state')->update(['server_id' => $serverId]);

        // Backfill matches: group consecutive same-map rounds into one match per
        // map streak, matching the "new match on map change" rule the parser uses.
        $rounds = DB::table('rounds')->orderBy('id')->get();
        $currentMatchId = null;
        $currentMap = null;

        foreach ($rounds as $round) {
            if ($currentMatchId === null || $round->map !== $currentMap) {
                $currentMatchId = DB::table('matches')->insertGetId([
                    'server_id' => $serverId,
                    'map' => $round->map,
                    'gametype' => $round->gametype,
                    'started_at' => $round->started_at,
                    'ended_at' => $round->ended_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $currentMap = $round->map;
            } else {
                DB::table('matches')->where('id', $currentMatchId)->update(['ended_at' => $round->ended_at]);
            }

            DB::table('rounds')->where('id', $round->id)->update(['match_id' => $currentMatchId]);
            DB::table('kills')->where('round_id', $round->id)->update(['match_id' => $currentMatchId]);
        }

        // Seed per-server aggregate stats from the existing global player totals
        // (only one server existed until now, so global totals == this server's totals).
        foreach (DB::table('players')->get() as $player) {
            DB::table('player_server_stats')->insert([
                'player_id' => $player->id,
                'server_id' => $serverId,
                'kills' => $player->kills_total,
                'deaths' => $player->deaths_total,
                'headshots' => $player->headshots_total,
                'grenade_kills' => $player->grenade_kills_total,
                'suicides' => $player->suicides_total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('player_server_stats')->truncate();
        DB::table('matches')->truncate();
        DB::table('servers')->truncate();
    }
};
