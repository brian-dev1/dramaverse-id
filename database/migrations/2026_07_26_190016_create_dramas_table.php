<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dramas', function (Blueprint $table) {
            $table->id();

            // --- Identitas ---
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('original_title')->nullable();
            $table->text('synopsis')->nullable();

            // --- Media ---
            $table->string('poster')->nullable();
            $table->string('cover')->nullable();
            $table->string('trailer_url')->nullable();
            // Gradien fallback (g1..g8) saat poster belum diunggah
            $table->string('gradient', 8)->default('g1');

            // --- Relasi ---
            $table->foreignId('country_id')
                ->nullable()
                ->constrained('countries')
                ->nullOnDelete();

            // --- Metadata ---
            $table->year('release_year')->nullable();
            $table->unsignedSmallInteger('total_episode')->default(0);
            $table->unsignedSmallInteger('duration')->nullable(); // menit per episode
            $table->enum('status', ['ongoing', 'completed', 'upcoming'])->default('ongoing');
            $table->decimal('rating', 3, 1)->default(0);
            $table->unsignedBigInteger('views')->default(0);

            // --- Kurasi & akses ---
            $table->boolean('is_vip')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_trending')->default(false);
            $table->unsignedInteger('trending_score')->default(0);

            // --- Publikasi ---
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // --- Index untuk halaman katalog ---
            $table->index('status');
            $table->index('is_vip');
            $table->index('is_featured');
            $table->index('is_trending');
            $table->index('release_year');
            $table->index('published_at');
            $table->index(['rating', 'views']);
            $table->index(['is_trending', 'trending_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dramas');
    }
};
