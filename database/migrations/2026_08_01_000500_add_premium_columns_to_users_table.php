<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom yang sudah dibaca kode sejak lama tetapi tidak pernah ada.
 *
 * ## Bug yang ditemukan saat audit Phase 10
 *
 * `EpisodeAccessRepository::canWatch()` membaca `$user->is_premium` dan
 * `$user->premium_expired_at`. Keduanya TIDAK PERNAH ADA di migration mana
 * pun. Eloquent mengembalikan null untuk atribut yang tidak ada — tanpa galat,
 * tanpa peringatan — sehingga pemeriksaannya selalu bernilai salah.
 *
 * Akibatnya: tidak ada satu pun pengguna yang pernah bisa membuka episode
 * premium, apa pun langganannya. Kegagalannya diam sepenuhnya.
 *
 * Kolom-kolom ini adalah ringkasan dari tabel `subscriptions`, bukan sumber
 * kebenarannya. Disimpan di sini karena dibaca pada setiap pemeriksaan akses —
 * di pemutar web maupun di bot Telegram — dan melakukan join ke subscriptions
 * setiap kali orang menekan play adalah biaya yang tidak perlu.
 *
 * Yang menjaganya tetap sinkron: `MembershipService::syncUserFlags()`, satu
 * tempat, dipanggil setiap kali langganan berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->boolean('is_premium')->default(false)->after('is_banned');

            $table->timestamp('premium_expired_at')->nullable()->after('is_premium');

            // Dipakai menyaring pengguna premium di panel admin dan segmen
            // broadcast Telegram.
            $table->index('is_premium');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_premium']);
            $table->dropColumn(['is_premium', 'premium_expired_at']);
        });
    }
};
