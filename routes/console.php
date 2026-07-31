<?php

use App\Services\Monitoring\SystemHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Detak scheduler
|--------------------------------------------------------------------------
|
| Menandai bahwa cron benar-benar memanggil `schedule:run`.
|
| Tanpa penanda ini, scheduler yang TIDAK PERNAH berjalan sama sekali
| terlihat persis sama dengan scheduler yang berjalan normal: tidak ada
| galat, tidak ada log, tidak ada apa pun. Seluruh otomatisasi Telegram dan
| seluruh cadangan bergantung padanya, dan tidak satu pun akan mengeluh bila
| baris cron-nya lupa dipasang.
|
| Dashboard monitoring membaca nilai ini dan menandai merah bila umurnya
| lebih dari sepuluh menit.
|
*/
Schedule::call(function () {
    Cache::put(SystemHealthService::HEARTBEAT, now(), 3600);
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

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

/*
|--------------------------------------------------------------------------
| Cadangan
|--------------------------------------------------------------------------
|
| Harian pukul 02:30, jam yang paling sepi. `mysqldump --single-transaction`
| tidak mengunci tabel InnoDB, tetapi tetap membebani disk, dan itu tidak
| perlu bertabrakan dengan jam tonton.
|
| Verifikasi dan pemangkasan ikut di dalam `backup:run` — cadangan yang tidak
| diverifikasi tidak bisa dipercaya, dan yang tidak dipangkas akan memenuhi
| disk sampai aplikasinya sendiri berhenti bekerja.
|
| Kegagalannya mengirim peringatan KRITIS yang melewati penahan: cadangan
| yang gagal diam-diam adalah cadangan yang dikira ada sampai hari ia
| dibutuhkan.
|
*/
Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Pembayaran & Membership
|--------------------------------------------------------------------------
|
| verify tiap lima menit: callback yang hilang harus tertutup dalam hitungan
| menit, bukan jam. Pengguna yang sudah membayar dan belum aktif akan
| menghubungi dukungan jauh sebelum satu jam lewat.
|
| expire tiap jam: keterlambatan beberapa menit pada langganan yang habis
| tidak merugikan siapa pun -- `EpisodeAccessRepository` tetap membandingkan
| `premium_expired_at` sendiri di setiap pemeriksaan akses, jadi tidak ada
| yang bisa menonton melewati masa aktifnya meski scheduler terlambat.
|
*/
Schedule::command('payment:auto verify')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('payment:auto expire')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Memanaskan cache dashboard tiap sepuluh menit — sedikit lebih rapat
| daripada TTL cache-nya (lima menit) supaya jarang ada yang menemukannya
| dalam keadaan dingin. Yang membuka dashboard tepat setelah cache
| kedaluwarsa harus menunggu belasan query agregat berjalan; memanaskannya
| di latar memindahkan tunggu itu ke tempat yang tidak ada orangnya.
|
| Tidak `runInBackground()`: perintah ini singkat dan idempoten, dan
| menjalankannya di proses yang sama memastikan kegagalannya terlihat di
| keluaran `schedule:run`.
|
*/
Schedule::command('analytics:refresh')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Penerbitan episode terjadwal
|--------------------------------------------------------------------------
|
| DITEMUKAN SAAT AUDIT PHASE 12: `episodes:publish` sudah ada sejak Sprint 6
| tetapi tidak pernah dijadwalkan. Episode yang diberi tanggal tayang tidak
| pernah terbit dengan sendirinya — admin harus menekan sesuatu, atau tidak
| terbit sama sekali.
|
| Kegagalannya diam total: tidak ada galat, tidak ada log, hanya episode yang
| tidak muncul pada tanggal yang dijanjikan ke penonton.
|
| Tiap lima menit. Ketelitian sampai menit tidak diperlukan untuk jadwal
| tayang, dan lima menit menjaga beban query tetap kecil.
|
*/
Schedule::command('episodes:publish')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
