<?php

namespace Database\Seeders;

use App\Models\TelegramMenu;
use App\Services\TelegramMenuService;
use Illuminate\Database\Seeder;

/**
 * Mengisi menu bot dengan susunan bawaan.
 *
 * Aman dijalankan berulang: `firstOrCreate` pada `action`, bukan
 * `updateOrCreate`. Label dan posisi yang sudah diubah admin TIDAK
 * dikembalikan ke bawaan — yang dikerjakan hanya menambahkan perbuatan yang
 * belum punya tombol. Seeder yang menimpa hasil kerja orang adalah seeder
 * yang tidak berani dijalankan lagi.
 *
 * Daftarnya dibaca dari TelegramMenuService::DEFAULTS supaya tidak ada dua
 * susunan bawaan yang harus dijaga tetap sama.
 */
class TelegramMenuSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TelegramMenuService::DEFAULTS as [$label, $action, $row, $position]) {

            TelegramMenu::firstOrCreate(
                ['action' => $action->value],
                [
                    'label'     => $label,
                    'row'       => $row,
                    'position'  => $position,
                    'is_active' => true,
                ]
            );
        }

        app(TelegramMenuService::class)->forget();
    }
}
