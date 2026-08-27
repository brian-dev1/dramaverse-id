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

// Isi ulang antrean sinkronisasi Telegram supaya tunggakan habis sendiri.
//
// Tiap menit, tetapi hampir selalu langsung keluar tanpa melakukan apa pun:
// selama antrean masih penuh tidak ada slot yang perlu diisi. Memeriksanya
// tiap lima menit berarti worker menganggur sampai empat menit setiap kali
// rombongan terakhir habis lebih cepat dari perkiraan.
Schedule::command('telegram:sinkron-lanjut')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

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

// Pengumuman channel yang jadwalnya sudah tiba. Tiap menit, karena admin
// memilih jam tayangnya sampai ke menit — memeriksanya tiap lima menit
// berarti pengumuman jam 19.00 bisa tayang 19.04, dan yang menunggu di
// channel tidak tahu kenapa. Perintahnya keluar diam-diam bila tidak ada
// yang jatuh tempo, jadi 1.440 panggilan sehari ini hampir seluruhnya
// hanya satu query berindeks.
Schedule::command('channel:announce-due')
    ->everyMinute()
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

// stale tiap 15 menit: tagihan tanpa transaksi (payment.guard.stale_after,
// bawaannya 2 jam) tidak perlu menunggu jadwal per jam untuk hilang dari
// panel dan dari obrolan bot -- makin lama tombol "Bayar sekarang" yang
// basi menempel, makin besar peluang pengguna menekannya dan bingung
// kenapa tagihannya sudah tidak berlaku.
Schedule::command('payment:auto stale')
    ->everyFifteenMinutes()
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

/*
|--------------------------------------------------------------------------
| Siklus hidup membership
|--------------------------------------------------------------------------
|
| Tiga pekerjaan yang harus berjalan berurutan dan tidak boleh saling
| mendahului:
|
| 1. Kunci  — langganan yang lewat tanggalnya jadi EXPIRED, `is_premium`
|             jadi false. Ini yang benar-benar menutup episode VIP.
| 2. Beri tahu — pesan "paket sudah berakhir" ke Telegram pengguna, sekali.
| 3. Tarik  — video premium yang sudah terlanjur dikirim dihapus dari chat.
|
| Digabung dalam satu `sweep` supaya urutannya dijamin. Menjadwalkannya
| sebagai tiga baris terpisah membuka jendela waktu di mana pengguna sudah
| menerima pesan "akses dicabut" padahal masih bisa membuka episode VIP —
| dan yang sebaliknya, video ditarik sebelum ada penjelasan apa pun.
|
| Tiap sepuluh menit. Lebih rapat tidak berguna: `EpisodeAccessRepository`
| tetap membandingkan `premium_expired_at` sendiri pada setiap pemutaran,
| jadi keterlambatan scheduler tidak pernah berarti akses yang bocor.
|
| PENTING: penarikan video bergantung pada batas 48 jam milik Telegram.
| Bila scheduler mati lebih dari dua hari, video yang menumpuk tidak akan
| bisa ditarik lagi saat scheduler hidup kembali. Heartbeat di atas ada
| justru untuk membuat scheduler yang mati terlihat.
|
*/
Schedule::command('membership:auto sweep')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Affiliate — lepas komisi yang masa tahannya habis
|--------------------------------------------------------------------------
|
| Hanya berguna bila `referral_hold_days` di panel admin diisi lebih dari 0.
| Dengan nilai 0 perintah ini tidak menemukan apa pun dan selesai seketika,
| jadi tetap aman dijadwalkan.
|
*/
Artisan::command('referral:release', function () {
    $jumlah = app(\App\Services\ReferralService::class)->releaseHeld();

    $this->info("Komisi dilepas: {$jumlah}");
})->purpose('Lepaskan komisi affiliate yang masa tahannya sudah lewat');

Schedule::command('referral:release')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
/*
|--------------------------------------------------------------------------
| Affiliate — perpanjang kode referral yang masih pendek
|--------------------------------------------------------------------------
|
| Kode lama dibuat 11 karakter dan bisa ditebak dengan percobaan berulang.
| Perintah ini menggantinya dengan kode 26 karakter. Tautan lama otomatis
| mati — itu memang tujuannya, dan ikatan referral yang sudah terjadi tidak
| ikut hilang karena yang disimpan pada pengguna adalah id pengundang, bukan
| kodenya.
|
*/
Artisan::command('referral:rotate-codes', function () {
    $service = app(\App\Services\ReferralService::class);
    $jumlah  = 0;

    \App\Models\User::whereNotNull('referral_code')
        ->get(['id', 'referral_code'])
        ->each(function ($user) use ($service, &$jumlah) {
            if (strlen((string) $user->referral_code) >= 20) {
                return;
            }

            $service->rotateCode($user);
            $jumlah++;
        });

    $this->info("Kode referral diperbarui: {$jumlah}");
})->purpose('Ganti kode referral pendek dengan kode panjang yang acak');
