<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_servers', function (Blueprint $table) {
            $table->id();
            $table->string('hostname', 32);
            $table->unsignedTinyInteger('slots');
            $table->string('map', 64);
            $table->string('join_password', 32)->nullable();
            $table->text('rcon_password');
            $table->boolean('cracked')->default(false);
            // Nullable + unique (no "port assignment is deleted", it's set to NULL on
            // expiry) — MySQL/MariaDB allow multiple NULLs in a unique index, so freed
            // instances don't block each other, but two ACTIVE instances can never end
            // up on the same port. See HostedServerPortAllocator for how this is used
            // as the actual race guard (retry-on-duplicate-key-insert), not a SELECT+lock.
            $table->unsignedInteger('port')->nullable()->unique();
            // Random, unguessable — this is the creator's only "credential" since there's
            // no login. Never render it in a listing, only accept it as a route param
            // to gate show()/stop().
            $table->string('management_token', 64)->unique();
            $table->string('status', 16)->default('starting');
            $table->unsignedTinyInteger('player_count')->nullable();
            $table->timestamp('last_seen_players_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('stopped_at')->nullable();
            $table->string('creator_ip', 45);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_servers');
    }
};
