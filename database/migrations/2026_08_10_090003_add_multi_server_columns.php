<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->after('server_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('kills', function (Blueprint $table) {
            $table->foreignId('match_id')->nullable()->after('round_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('player_map_stats', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('player_id')->constrained()->cascadeOnDelete();
        });
        Schema::table('player_map_stats', function (Blueprint $table) {
            // MySQL uses the (player_id, map) unique index as the player_id foreign key's
            // supporting index, so it refuses to drop it until another index can take
            // over — add a plain player_id index first, then swap the unique index.
            $table->index('player_id', 'player_map_stats_player_id_index');
        });
        Schema::table('player_map_stats', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'map']);
            $table->unique(['player_id', 'server_id', 'map']);
        });

        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_match_id')->nullable()->after('current_round_id')->constrained('matches')->nullOnDelete();
        });
        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->dropUnique(['log_path']);
            $table->unique('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('log_parser_state', function (Blueprint $table) {
            $table->dropUnique(['server_id']);
            $table->unique('log_path');
            $table->dropConstrainedForeignId('server_id');
            $table->dropConstrainedForeignId('current_match_id');
        });

        Schema::table('player_map_stats', function (Blueprint $table) {
            $table->dropUnique(['player_id', 'server_id', 'map']);
            $table->unique(['player_id', 'map']);
            $table->dropIndex('player_map_stats_player_id_index');
            $table->dropConstrainedForeignId('server_id');
        });

        Schema::table('kills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('match_id');
        });

        Schema::table('rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
            $table->dropConstrainedForeignId('match_id');
        });
    }
};
