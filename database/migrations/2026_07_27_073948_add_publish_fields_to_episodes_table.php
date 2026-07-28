<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {

            $table->string('status')
                ->default('draft')
                ->after('video_url');

            $table->timestamp('published_at')
                ->nullable()
                ->after('status');

            $table->timestamp('expired_at')
                ->nullable()
                ->after('published_at');

        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {

            $table->dropColumn([
                'status',
                'published_at',
                'expired_at',
            ]);

        });
    }
};