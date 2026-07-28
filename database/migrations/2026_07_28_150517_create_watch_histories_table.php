<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('watch_histories', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('drama_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('episode_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('progress')->default(0);

            $table->unsignedInteger('duration')->default(0);

            $table->boolean('completed')->default(false);

            $table->timestamp('last_watched_at')->nullable();

            $table->timestamps();

            $table->unique([
                'user_id',
                'episode_id'
            ]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watch_histories');
    }
};