<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modulo de clanes (2026-09-03) -- ver docs/superpowers/specs/
        // 2026-09-03-clanes-design.md. created_at es la fecha de fundacion
        // real, sin campo editable aparte.
        Schema::create('clans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // Tag corto, tambien la clave de la URL publica (/clanes/{tag}) --
            // validado en el controller a solo alfanumerico/guion (URL-safe).
            $table->string('tag', 15)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->foreignId('founder_site_user_id')->constrained('site_users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clans');
    }
};
