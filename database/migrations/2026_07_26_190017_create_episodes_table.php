<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('drama_id')->constrained('dramas')->cascadeOnDelete();

            $table->unsignedSmallInteger('episode_number');
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();

            // --- Sumber video ---
            $table->string('video_url')->nullable();
            $table->string('embed_url')->nullable();
            $table->string('thumbnail')->nullable();
            $table->unsignedInteger('duration')->default(0); // detik

            // --- Akses & statistik ---
            $table->boolean('is_vip')->default(false);
            $table->unsignedBigInteger('views')->default(0);

            $table->timestamp('air_date')->nullable();
            $table->timestamps();

            $table->unique(['drama_id', 'episode_number']);
            $table->index('is_vip');
            $table->index('air_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
