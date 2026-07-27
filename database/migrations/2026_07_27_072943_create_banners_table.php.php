<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {

            $table->id();

            $table->string('title');

            $table->string('subtitle')->nullable();

            $table->string('image');

            $table->string('link')->nullable();

            $table->string('button_text')->nullable();

            $table->string('position')->default('hero');

            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamp('start_at')->nullable();

            $table->timestamp('end_at')->nullable();

            $table->timestamps();

            $table->index('position');
            $table->index('sort_order');
            $table->index('is_active');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};