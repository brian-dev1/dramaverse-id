<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Perawatan Telegram
|--------------------------------------------------------------------------
|
| Seluruhnya WAJIB `withoutOverlapping()`. Tanpa itu, jalan yang lambat akan
| bertumpuk dengan jalan berikutnya, dan dua proses yang sama-sama
| mengantrekan ulang video yang gagal akan mengirim berkas yang sama dua kali.
|
| `runInBackground()` supaya satu perintah yang lambat tidak menunda perintah
| terjadwal lain di menit yang sama.
|
| Scheduler ini hanya berjalan bila cron memanggilnya. Di VPS:
|
|     * * * * * cd /var/www/dramaverse && php artisan schedule:run >> /dev/null 2>&1
|
| Tanpa baris cron itu, tidak ada satu pun jadwal di bawah yang pernah
| dijalankan — dan tidak akan ada galat apa pun yang memberitahukannya.
|
*/

// Ulangi sinkronisasi yang gagal. Tiap 15 menit: cukup cepat supaya gangguan
// sesaat pulih sendiri, cukup jarang supaya kegagalan permanen tidak
// memenuhi antrean.
Schedule::command('telegram:auto retry')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Periksa bot dan antrean. Tiap 30 menit — peringatannya sendiri sudah
// ditahan TelegramAlertService, jadi memeriksa lebih sering tidak membuat
// operator dibanjiri pesan.
Schedule::command('telegram:auto health')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Lepaskan baris tersangkut dan buang berkas sementara. Tiap jam: berkas
// sementara berukuran sebesar videonya, dan menundanya sampai harian berarti
// disk VPS bisa penuh sebelum ada yang membersihkannya.
Schedule::command('telegram:auto cleanup')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
