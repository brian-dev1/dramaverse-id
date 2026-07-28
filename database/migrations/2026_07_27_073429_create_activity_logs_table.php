<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('action');

            $table->string('module');

            $table->text('description')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->text('user_agent')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index('module');
            $table->index('action');
            $table->index('created_at');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};