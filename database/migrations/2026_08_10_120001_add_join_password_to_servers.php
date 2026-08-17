<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('join_password')->nullable()->after('connect_port');
        });

        // Undo the earlier workaround where the whole "ip; password xxx" string was
        // typed directly into connect_ip — split it back into connect_ip + join_password
        // now that there's a proper field for the password.
        foreach (DB::table('servers')->get() as $server) {
            if (str_contains($server->connect_ip, ';')) {
                [$ip, $rest] = array_map('trim', explode(';', $server->connect_ip, 2));
                $password = trim(preg_replace('/^password\s+/i', '', $rest));

                DB::table('servers')->where('id', $server->id)->update([
                    'connect_ip' => $ip,
                    'join_password' => $password,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('join_password');
        });
    }
};
