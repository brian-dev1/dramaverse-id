<?php

use App\Services\Admin\SettingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbarui template caption channel yang masih memakai bentuk lama.
 *
 * ## Kenapa perlu migrasi, bukan cukup mengganti nilai bawaan
 *
 * Nilai bawaan di `SettingService::SCHEMA` hanya berlaku selama kuncinya
 * BELUM ada di tabel `settings`. Begitu admin menekan Simpan sekali di
 * halaman Pengaturan, seluruh kunci ikut tertulis ke database — termasuk yang
 * tidak ia sentuh. Sejak saat itu, mengubah nilai bawaan di kode tidak
 * berpengaruh apa pun.
 *
 * ## Kenapa hanya yang persis sama dengan bawaan lama
 *
 * Template adalah tulisan milik admin. Menimpanya begitu saja berarti
 * menghapus susunan yang mungkin sudah ia rapikan sendiri, tanpa peringatan
 * dan tanpa cara memulihkannya.
 *
 * Karena itu yang diperbarui hanya baris yang isinya PERSIS sama dengan
 * bawaan lama — tanda bahwa tidak ada yang pernah menyuntingnya. Yang sudah
 * berbeda satu karakter pun dibiarkan apa adanya.
 */
return new class extends Migration
{
    /** Bentuk bawaan sebelum pembaruan ini. */
    private const TEMPLATE_LAMA = "『 {judul} 』\n\n{daftar}";

    private const BARIS_LAMA = '➤ Part {nomor} | {tanda} → {tautan}';

    private const BARIS_BARU = '➤ <b>Part {nomor}</b> | {tanda} → {tautan}';

    public function up(): void
    {
        $this->ganti('channel_template', self::TEMPLATE_LAMA, SettingService::TEMPLATE_BAWAAN);

        $this->ganti('channel_line', self::BARIS_LAMA, self::BARIS_BARU);

        // Bersihkan cache pengaturan; kalau tidak, template lama masih terbaca
        // sampai ada yang menyimpan pengaturan lagi.
        app(SettingService::class)->flush();
    }

    public function down(): void
    {
        $this->ganti('channel_template', SettingService::TEMPLATE_BAWAAN, self::TEMPLATE_LAMA);

        $this->ganti('channel_line', self::BARIS_BARU, self::BARIS_LAMA);

        app(SettingService::class)->flush();
    }

    private function ganti(string $kunci, string $dari, string $ke): void
    {
        DB::table('settings')
            ->where('key', $kunci)
            ->where('value', $dari)
            ->update(['value' => $ke]);
    }
};
