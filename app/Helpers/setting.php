<?php

use App\Services\Admin\SettingService;

if (! function_exists('setting')) {
    /**
     * Membaca satu pengaturan situs.
     *
     * Nilainya di-cache, jadi aman dipanggil berulang kali dari Blade.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingService::class)->get($key, $default);
    }
}
