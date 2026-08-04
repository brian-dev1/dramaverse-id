<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_inboxes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('storage_provider_id')
                ->constrained('storage_providers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('telegram_message_id')->nullable();

            $table->string('original_filename');
            $table->string('object_key', 700);

            $table->string('mime_type', 150)->default('video/mp4');
            $table->unsignedBigInteger('size')->default(0);

            $table->string('checksum', 64)->nullable();
            $table->string('public_url', 1000)->nullable();

            $table->string('status', 30)->default('available');

            $table->foreignId('episode_id')
                ->nullable()
                ->constrained('episodes')
                ->nullOnDelete();

            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['storage_provider_id', 'object_key'],
                'video_inbox_provider_object_unique'
            );

            $table->index('telegram_message_id');
            $table->index('status');
            $table->index('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_inboxes');
    }
};