<?php

namespace App\Telegram\Keyboards;

use App\Services\TelegramMenuService;

/**
 * Menu utama bot.
 *
 * Susunannya tidak lagi ditulis di sini. Sejak menu bisa diatur dari panel
 * admin, kelas ini hanya jalan pintas ke TelegramMenuService — dipertahankan
 * supaya pemanggil lama (`HomeKeyboard::make()`) tidak perlu diubah.
 */
class HomeKeyboard
{
    public static function make(): array
    {
        return app(TelegramMenuService::class)->keyboard();
    }
}
