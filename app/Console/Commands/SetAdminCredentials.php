<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetAdminCredentials extends Command
{
    protected $signature = 'cod2:admin {username} {password}';

    protected $description = 'Create or update the admin panel login (used by install.sh to let the installer choose credentials instead of the random default)';

    public function handle(): int
    {
        $username = $this->argument('username');

        // Migrations seed the very first admin row keyed by a fixed email — reuse
        // that row if it's still there, otherwise fall back to whichever admin
        // row already has this username (a second run of install.sh, or someone
        // renaming it by hand).
        $user = User::where('email', 'admin@cod2.4livepro.com')
            ->orWhere('username', $username)
            ->first() ?? new User(['name' => 'Admin', 'email' => 'admin@cod2.4livepro.com']);

        $user->username = $username;
        $user->password = Hash::make($this->argument('password'));
        $user->save();

        $this->info("Admin panel login set — username: {$username}");

        return self::SUCCESS;
    }
}
