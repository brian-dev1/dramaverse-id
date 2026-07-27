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
        Schema::table('users', function (Blueprint $table) {

            // Kolom ini BELUM ada pada migration sebelumnya
            $table->string('telegram_language', 10)
                ->nullable()
                ->after('telegram_last_name');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('remember_token');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropColumn([
                'telegram_language',
                'last_login_at',
            ]);

        });
    }
};