<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot drama <-> genre.
 * Satu drama bisa punya banyak genre (sebelumnya keliru dimodelkan sebagai belongsTo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drama_genre', function (Blueprint $table) {
            $table->id();

            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained('genres')->cascadeOnDelete();

            $table->unique(['drama_id', 'genre_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drama_genre');
    }
};
