<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('is_backfilled')->default(false)->after('gametype');
        });

        // Every match that existed before this migration came from the one-shot
        // --from-start backfill, whose started_at/ended_at are all clustered at the
        // moment the backfill ran (the log has no real timestamp to recover) — flag them
        // so the UI can stop presenting that as a real date.
        DB::table('matches')->update(['is_backfilled' => true]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('is_backfilled');
        });
    }
};
