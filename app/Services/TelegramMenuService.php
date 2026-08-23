<?php

namespace App\Services;

use App\Enums\TelegramMenuAction;
use App\Models\TelegramMenu;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Menyusun keyboard bot dari basis data, dan menjaga bot tetap punya menu
 * dalam keadaan apa pun.
 *
 * ## Kenapa ada bawaan yang dipatok di kode
 *
 * Menu adalah satu-satunya cara pengguna memakai bot ini. Kalau tabelnya
 * kosong — migration sudah jalan tapi seeder belum, atau semua baris
 * dinonaktifkan admin — bot akan mengirim pesan sambutan tanpa satu tombol
 * pun, dan pengguna tidak punya jalan ke mana-mana. Karena itu daftar bawaan
 * di bawah dipakai sebagai jaring pengaman, bukan sebagai sumber utama.
 *
 * Bawaan ini juga yang dipakai TelegramMenuSeeder, jadi tidak ada dua daftar
 * yang harus dijaga tetap sama.
 */
class TelegramMenuService
{
    private const CACHE_KEY = 'telegram.menu.keyboard';

    private const CACHE_TTL = 300;

    /**
     * Susunan bawaan: [label, action, row, position].
     *
     * Sama persis dengan menu sebelum Sprint 8.1 — ditambah Cari Drama, yang
     * handler-nya sudah ada sejak awal tapi tidak pernah punya tombol.
     */
    public const DEFAULTS = [
        ['Cari Drama',        TelegramMenuAction::SEARCH,   1, 1],
        ['Continue Watching', TelegramMenuAction::CONTINUE, 2, 1],
        ['Favorit',           TelegramMenuAction::FAVORITE, 3, 1],
        ['Riwayat',           TelegramMenuAction::HISTORY,  3, 2],
        ['Buka Aplikasi',     TelegramMenuAction::WEBSITE,  4, 1],
        ['Premium',           TelegramMenuAction::PREMIUM,  5, 1],
        ['Profil',            TelegramMenuAction::PROFILE,  5, 2],
        ['Bantuan',           TelegramMenuAction::HELP,     6, 1],
    ];

    /**
     * inline_keyboard siap kirim.
     *
     * Di-cache karena dipanggil pada setiap `/start` dan setiap kali menu
     * ditampilkan ulang — pembacaan yang sama, berulang-ulang, untuk data
     * yang berubah beberapa kali setahun.
     */
    public function keyboard(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => $this->build()
        );
    }

    /** Panggil setiap kali menu disunting dari panel. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Semua baris untuk panel admin, urut tampilan. */
    public function all(): Collection
    {
        return TelegramMenu::query()
            ->orderBy('row')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Pembangunan
    |--------------------------------------------------------------------------
    */

    private function build(): array
    {
        $baris = $this->rowsFromDatabase() ?? $this->rowsFromDefaults();

        return ['inline_keyboard' => $baris];
    }

    /**
     * Baris dari basis data, atau null bila tidak ada yang bisa dipakai.
     *
     * Kegagalan basis data ditangkap dan diperlakukan sama dengan tabel
     * kosong. Alasannya: yang memanggil method ini adalah bot yang sedang
     * membalas pengguna. Membiarkan exception naik berarti pengguna menekan
     * Start dan tidak mendapat apa-apa; jatuh ke bawaan berarti dia tetap
     * mendapat menu yang berfungsi sementara masalahnya ditelusuri.
     */
    private function rowsFromDatabase(): ?array
    {
        try {
            if (! Schema::hasTable('telegram_menus')) {
                return null;
            }

            $menus = TelegramMenu::query()
                ->where('is_active', true)
                ->orderBy('row')
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return null;
        }

        $baris = [];

        foreach ($menus as $menu) {

            // Tombol tautan tanpa alamat membuat Telegram menolak SELURUH
            // keyboard. Satu baris yang belum lengkap tidak boleh
            // menghilangkan seluruh menu, jadi ia dilewati saja.
            if ($menu->action->isLink() && blank($menu->url)) {
                continue;
            }

            $baris[$menu->row][] = $menu->toButton();
        }

        if ($baris === []) {
            return null;
        }

        // Nomor baris boleh bolong (1, 3, 7) setelah admin menghapus sesuatu.
        // Telegram butuh array berurutan, bukan array berkunci.
        ksort($baris);

        return array_values($baris);
    }

    private function rowsFromDefaults(): array
    {
        $baris = [];

        foreach (self::DEFAULTS as [$label, $action, $row, $position]) {
            $baris[$row][$position] = [
                'text'          => $label,
                'callback_data' => $action->value,
            ];
        }

        ksort($baris);

        return array_values(array_map(function (array $kolom): array {
            ksort($kolom);

            return array_values($kolom);
        }, $baris));
    }
}
