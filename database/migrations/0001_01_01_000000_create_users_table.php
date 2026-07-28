<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel users — berbasis Telegram.
 *
 * DramaVerse ID tidak memakai login email untuk pengguna biasa.
 * Kolom email/password disediakan nullable semata-mata untuk akun admin
 * yang masuk lewat /admin/login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // --- Identitas Telegram (sumber utama autentikasi) ---
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->string('telegram_language', 10)->nullable();
            $table->string('telegram_photo_url')->nullable();

            // --- Identitas tampilan ---
            $table->string('name');

            // --- Khusus admin (pengguna biasa selalu null) ---
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_admin')->default(false);

            // --- Status akun ---
            $table->boolean('is_active')->default(true);
            $table->boolean('is_banned')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('is_admin');
            $table->index('is_active');
            $table->index('last_seen_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
