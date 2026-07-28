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
        Schema::create('media', function (Blueprint $table) {

            $table->id();

            $table->string('disk')->default('public');

            $table->string('directory');

            $table->string('filename');

            $table->string('original_name');

            $table->string('mime_type');

            $table->string('extension', 20);

            $table->unsignedBigInteger('size');

            $table->string('url');

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('directory');
            $table->index('uploaded_by');
            $table->index('mime_type');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};