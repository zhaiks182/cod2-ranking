<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_item_id')->constrained('gallery_items')->cascadeOnDelete();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // Un like por usuario por item -- la unicidad la garantiza el
            // esquema, no solo el codigo (mismo criterio que
            // site_users.player_id).
            $table->unique(['gallery_item_id', 'site_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_likes');
    }
};
