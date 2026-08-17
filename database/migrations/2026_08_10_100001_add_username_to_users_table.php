<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
        });

        $password = Str::random(16);

        DB::table('users')->where('email', 'admin@cod2.4livepro.com')->update([
            'username' => 'adm_cod2',
            'password' => Hash::make($password),
        ]);

        echo "\n    Admin panel login updated — username: adm_cod2 / password: {$password}\n";
        echo "    (Change it from /adm_cod2/password after logging in.)\n\n";
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
