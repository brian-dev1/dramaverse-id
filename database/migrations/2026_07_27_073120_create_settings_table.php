<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {

            $table->id();

            $table->string('key')->unique();

            $table->longText('value')->nullable();

            $table->string('group')->default('general');

            $table->string('type')->default('text');

            $table->text('description')->nullable();

            $table->boolean('autoload')->default(true);

            $table->timestamps();

            $table->index('group');
            $table->index('autoload');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};