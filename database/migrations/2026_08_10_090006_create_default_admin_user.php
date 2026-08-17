<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->where('email', 'admin@cod2.4livepro.com')->exists()) {
            return;
        }

        $password = Str::random(16);

        DB::table('users')->insert([
            'name' => 'Admin',
            'email' => 'admin@cod2.4livepro.com',
            'password' => Hash::make($password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo "\n    Admin panel login created — email: admin@cod2.4livepro.com / password: {$password}\n";
        echo "    (Change it from /admin/password after logging in.)\n\n";
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'admin@cod2.4livepro.com')->delete();
    }
};
