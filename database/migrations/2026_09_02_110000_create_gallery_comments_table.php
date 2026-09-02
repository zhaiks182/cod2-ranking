<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_item_id')->constrained('gallery_items')->cascadeOnDelete();
            $table->foreignId('site_user_id')->constrained('site_users')->cascadeOnDelete();
            $table->string('body', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_comments');
    }
};
