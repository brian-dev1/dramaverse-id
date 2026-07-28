<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('drama_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');

            $table->text('review')->nullable();

            $table->boolean('is_spoiler')->default(false);

            $table->boolean('is_hidden')->default(false);

            $table->timestamps();

            $table->unique([
                'user_id',
                'drama_id'
            ]);

            $table->index('rating');
            $table->index('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};