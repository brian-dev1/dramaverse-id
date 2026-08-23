<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Memeriksa apakah environment layak dipakai produksi.
 *
 * ## Kenapa perintah ini ada
 *
 * Kesalahan konfigurasi produksi tidak menghasilkan galat. `APP_DEBUG=true`
 * berjalan mulus — sampai ada pengguna yang melihat seluruh stack trace
 * beserta isi `.env` di halaman galat. Cron yang lupa dipasang berjalan
 * mulus — sampai ada yang menyadari langganan tidak pernah berakhir.
 *
 * Ini satu-satunya alat yang bisa mengatakan "jangan luncurkan dulu"
 * sebelum ada yang dirugikan.
 *
 * Jalankan setelah setiap deploy:
 *
 *     php artisan env:check
 *     php artisan env:check --production
 *
 * Keluar dengan kode 1 bila ada yang FATAL, sehingga bisa dipakai di dalam
 * skrip deploy dan menghentikannya.
 */
class EnvCheck extends Command
{
    protected $signature = 'env:check
                            {--production : Terapkan juga aturan yang hanya berlaku di produksi}';

    protected $description = 'Periksa kesiapan environment sebelum dan sesudah deploy';

    /** @var array<int,array{level:string, pesan:string, saran:string}> */
    private array $temuan = [];

    public function handle(): int
    {
        $produksi = $this->option('production') || app()->environment('production');

        $this->components->info(
            'Memeriksa environment'.($produksi ? ' dengan aturan produksi' : '').'.'
        );

        $this->periksaDasar($produksi);
        $this->periksaBasisData();
        $this->periksaTelegram($produksi);
        $this->periksaPembayaran($produksi);
        $this->periksaAntrean($produksi);
        $this->periksaBerkas();

        return $this->laporkan();
    }

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan
    |--------------------------------------------------------------------------
    */

    private function periksaDasar(bool $produksi): void
    {
        $this->wajib(filled(config('app.key')), 'APP_KEY belum diisi.',
            'Jalankan `php artisan key:generate`. Tanpa ini seluruh kredensial '
            .'terenkripsi tidak bisa dibaca dan sesi tidak bisa dibuat.');

        if ($produksi) {

            $this->wajib(config('app.debug') === false, 'APP_DEBUG masih true di produksi.',
                'Setel APP_DEBUG=false. Halaman galat Laravel menampilkan stack '
                .'trace beserta nilai environment kepada siapa pun yang memicunya.');

            $this->wajib(config('app.env') === 'production', 'APP_ENV bukan `production`.',
                'Setel APP_ENV=production supaya penanganan galat dan cache memakai '
                .'jalur produksi.');

            $this->wajib(str_starts_with((string) config('app.url'), 'https://'),
                'APP_URL bukan https.',
                'Tautan masuk sekali pakai dan callback pembayaran dikirim ke alamat '
                .'ini; lewat http keduanya bisa dibaca di jalan.');
        }

        $this->sarankan(config('app.timezone') === 'Asia/Jakarta',
            'APP_TIMEZONE bukan Asia/Jakarta.',
            'Jadwal tayang part dan jatuh tempo tagihan memakai zona waktu ini.');
    }

    private function periksaBasisData(): void
    {
        try {
            DB::connection()->getPdo();

            $this->components->twoColumnDetail('Basis data', '<fg=green>tersambung</>');

        } catch (Throwable $e) {
            $this->wajib(false, 'Basis data tidak bisa dihubungi: '.$e->getMessage(),
                'Periksa DB_HOST, DB_DATABASE, DB_USERNAME, dan DB_PASSWORD.');

            return;
        }

        // Migration yang belum dijalankan adalah penyebab paling sering dari
        // "kolom tidak ditemukan" yang muncul setelah deploy.
        foreach (['users', 'invoices', 'payment_transactions', 'episode_videos',
                  'storage_providers', 'telegram_menus'] as $tabel) {
            $this->wajib(Schema::hasTable($tabel), "Tabel `{$tabel}` belum ada.",
                'Jalankan `php artisan migrate --force`.');
        }

        $this->wajib(Schema::hasColumn('users', 'is_premium'),
            'Kolom `users.is_premium` belum ada.',
            'Jalankan `php artisan migrate --force`. Tanpa kolom ini tidak ada '
            .'satu pun part premium yang bisa dibuka siapa pun.');
    }

    private function periksaTelegram(bool $produksi): void
    {
        $this->wajib(filled(config('telegram.bot_token')), 'TELEGRAM_BOT_TOKEN belum diisi.',
            'Telegram adalah satu-satunya cara pengguna masuk. Tanpa token, '
            .'tidak ada yang bisa mendaftar maupun login.');

        $this->sarankan(filled(config('telegram.bot_username')),
            'TELEGRAM_BOT_USERNAME belum diisi.',
            'Tanpa ini seluruh deep link `t.me/...` tidak dirender.');

        $this->sarankan(filled(config('telegram.storage_chat_id')),
            'TELEGRAM_STORAGE_CHAT_ID belum diisi.',
            'Video tidak bisa disinkronkan ke Telegram sampai channel '
            .'penyimpanannya diisi.');

        if ($produksi) {
            $this->wajib(filled(config('telegram.webhook_secret')),
                'TELEGRAM_WEBHOOK_SECRET belum diisi di produksi.',
                'Tanpa rahasia ini, endpoint webhook menerima permintaan dari '
                .'siapa pun yang tahu alamatnya.');
        }

        $this->periksaFileId();
    }

    /**
     * Jaga tiga hal yang mematikan seluruh `telegram_file_id` sekaligus.
     *
     * ## Kenapa penjagaan ini ada
     *
     * Video di bot tidak diputar dari storage provider. Ia diputar dari
     * salinan milik Telegram, yang ditunjuk `episode_videos.telegram_file_id`.
     * Setelah berkas aslinya dihapus dari storage — dan menghapusnya adalah
     * hal yang wajar dilakukan untuk menghemat biaya — id itu menjadi
     * SATU-SATUNYA jalan menuju video tersebut.
     *
     * Id itu tidak pernah kedaluwarsa dengan sendirinya. Ia mati hanya karena
     * tiga tindakan, dan ketiganya terlihat tidak berbahaya saat dikerjakan:
     *
     * 1. `TELEGRAM_API_URL` dipindah ke `api.telegram.org` — id terbitan Local
     *    Bot API Server tidak dikenal di sana, dan sebaliknya.
     * 2. `TELEGRAM_BOT_TOKEN` diganti — file id terikat pada bot penerbitnya.
     * 3. Folder `--dir` Local Bot API Server hilang — terhapus, disk rusak,
     *    atau container dibuat ulang tanpa volume.
     *
     * Ketiganya tidak menimbulkan galat apa pun saat terjadi. Yang terlihat
     * baru muncul belakangan, saat seseorang menekan tombol tonton dan
     * Telegram menjawab "file tidak ditemukan" — dan pada saat itu tidak ada
     * lagi yang bisa dipulihkan.
     *
     * Karena itu diperiksa di sini, di perintah yang memang dijalankan sebelum
     * dan sesudah deploy.
     */
    private function periksaFileId(): void
    {
        if (! Schema::hasTable('episode_videos')) {
            return;
        }

        $jumlah = (int) \App\Models\EpisodeVideo::query()
            ->whereNotNull('telegram_file_id')
            ->count();

        // Tanpa file id tersimpan, tidak ada yang bisa hilang.
        if ($jumlah === 0) {
            return;
        }

        $apiUrl = (string) config('telegram.api_url');

        $lokal = $apiUrl !== '' && ! str_contains($apiUrl, 'api.telegram.org');

        /*
        |----------------------------------------------------------------------
        | 1. Pindah ke API cloud
        |----------------------------------------------------------------------
        |
        | FATAL, bukan peringatan. Perpindahan ini mematikan seluruh id
        | sekaligus, dan tidak ada satu pun gejala sampai pengguna pertama
        | mencoba menonton.
        |
        */
        $this->wajib(
            $lokal,
            sprintf(
                'TELEGRAM_API_URL menunjuk API cloud, padahal ada %d video '
                .'yang file id-nya diterbitkan Local Bot API Server.',
                $jumlah
            ),
            'File id dari server lokal TIDAK berlaku di api.telegram.org. '
            .'Mengubah ini mematikan seluruh video di bot sekaligus, dan tidak '
            .'bisa dipulihkan kecuali berkas aslinya masih ada di storage '
            .'provider untuk disinkronkan ulang. Kembalikan TELEGRAM_API_URL ke '
            .'alamat server lokal Anda.'
        );

        if (! $lokal) {
            return;
        }

        /*
        |----------------------------------------------------------------------
        | 2. Token berganti
        |----------------------------------------------------------------------
        |
        | Yang dibandingkan hanya bagian id bot — angka sebelum titik dua.
        | Bagian rahasianya tidak pernah disimpan di mana pun, termasuk di
        | tabel setting yang bisa dibaca admin mana pun.
        |
        | Sidik jarinya ditulis saat pertama kali perintah ini berjalan.
        | Pemasangan yang sudah berjalan lama karena itu tidak mendadak
        | dilaporkan bermasalah — yang dijaga adalah PERUBAHAN sesudahnya.
        |
        */
        $token = (string) config('telegram.bot_token');

        $botId = trim(explode(':', $token)[0] ?? '');

        if ($botId !== '' && Schema::hasTable('settings')) {

            $tersimpan = \App\Models\Setting::query()
                ->where('key', 'telegram_bot_id_fileid')
                ->value('value');

            if (blank($tersimpan)) {

                \App\Models\Setting::updateOrCreate(
                    ['key' => 'telegram_bot_id_fileid'],
                    ['value' => $botId]
                );

            } else {

                $this->wajib(
                    (string) $tersimpan === $botId,
                    sprintf(
                        'TELEGRAM_BOT_TOKEN berganti bot (dulu %s, sekarang %s), '
                        .'padahal ada %d video yang file id-nya milik bot lama.',
                        $tersimpan,
                        $botId,
                        $jumlah
                    ),
                    'File id terikat pada bot yang menerbitkannya. Bot baru tidak '
                    .'bisa mengirim video milik bot lama. Kembalikan tokennya, atau '
                    .'sinkronkan ulang seluruh video dari storage provider dengan '
                    .'bot yang baru.'
                );
            }
        }

        /*
        |----------------------------------------------------------------------
        | 3. Folder data server lokal
        |----------------------------------------------------------------------
        |
        | Di folder inilah Telegram versi lokal menyimpan berkasnya. Setelah
        | storage provider dikosongkan, folder ini menjadi satu-satunya tempat
        | video Anda benar-benar berada.
        |
        | PERHATIAN, bukan FATAL: `TELEGRAM_API_DIR` boleh saja tidak diisi
        | pada pemasangan yang tidak perlu membaca berkas dari disk, dan
        | folder yang tidak terbaca dari sisi PHP belum tentu tidak ada.
        |
        */
        $dir = (string) config('telegram.api_dir');

        if ($dir === '') {

            $this->sarankan(
                false,
                'TELEGRAM_API_DIR belum diisi, jadi folder data Local Bot API '
                .'Server tidak diketahui aplikasi.',
                'Isi dengan argumen --dir server itu. Setelah berkas di storage '
                .'provider dihapus, folder tersebut satu-satunya tempat '
                .$jumlah.' video Anda tersimpan — dan folder yang tidak '
                .'diketahui tidak akan pernah masuk rencana cadangan.'
            );

            return;
        }

        $this->sarankan(
            is_dir($dir),
            'Folder TELEGRAM_API_DIR ('.$dir.') tidak ditemukan.',
            'Di folder itulah '.$jumlah.' video Anda tersimpan. Kalau ia benar-'
            .'benar hilang, seluruh file id ikut mati. Periksa apakah server '
            .'Bot API lokal masih memakai --dir yang sama, dan pastikan '
            .'foldernya ikut dicadangkan.'
        );
    }

    private function periksaPembayaran(bool $produksi): void
    {
        if (! Schema::hasTable('payment_providers')) {
            return;
        }

        $aktif = DB::table('payment_providers')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->count();

        $this->sarankan($aktif > 0, 'Belum ada metode pembayaran yang aktif.',
            'Tambahkan provider di /admin/payment/provider. Tanpa itu tombol '
            .'berlangganan tidak bisa dipakai siapa pun.');

        if ($produksi && $aktif > 0) {

            $sandbox = DB::table('payment_providers')
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('mode', '!=', 'live')
                ->count();

            $this->wajib($sandbox === 0,
                "{$sandbox} provider pembayaran masih mode sandbox di produksi.",
                'Pembayaran sungguhan tidak akan pernah masuk lewat provider '
                .'sandbox, dan tidak ada galat yang memberitahukannya.');
        }
    }

    private function periksaAntrean(bool $produksi): void
    {
        $koneksi = (string) config('queue.default');

        $this->wajib(! ($produksi && $koneksi === 'sync'),
            'QUEUE_CONNECTION=sync di produksi.',
            'Driver sync menjalankan job di dalam request yang sama, sehingga '
            .'unggahan video dan broadcast memblokir halaman sampai selesai.');

        if ($koneksi === 'database' && Schema::hasTable('failed_jobs')) {

            $gagal = DB::table('failed_jobs')->count();

            $this->sarankan($gagal === 0, "{$gagal} pekerjaan ada di failed_jobs.",
                'Periksa dengan `php artisan queue:failed`.');
        }

        // Detak scheduler ditulis setiap menit oleh routes/console.php.
        // Kosong berarti cron tidak pernah memanggil `schedule:run`.
        $detak = cache()->get(\App\Services\Monitoring\SystemHealthService::HEARTBEAT);

        $this->wajib($detak !== null && now()->diffInMinutes($detak) < 10,
            'Scheduler tidak berdetak dalam sepuluh menit terakhir.',
            'Pasang cron: `* * * * * cd '.base_path()
            .' && php artisan schedule:run >> /dev/null 2>&1`. Tanpa itu tidak '
            .'ada satu pun otomatisasi yang berjalan, dan tidak ada galat yang '
            .'memberitahukannya.');
    }

    private function periksaBerkas(): void
    {
        foreach (['storage/logs', 'storage/framework', 'bootstrap/cache'] as $dir) {
            $this->wajib(is_writable(base_path($dir)), "{$dir} tidak bisa ditulis.",
                'Perbaiki kepemilikannya: `chown -R www-data:www-data '.base_path($dir).'`.');
        }

        $this->sarankan(is_link(public_path('storage')),
            'public/storage belum jadi symlink.',
            'Jalankan `php artisan storage:link` supaya gambar unggahan tampil.');
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /** Gagal berarti jangan diluncurkan. */
    private function wajib(bool $lolos, string $pesan, string $saran): void
    {
        if (! $lolos) {
            $this->temuan[] = ['level' => 'FATAL', 'pesan' => $pesan, 'saran' => $saran];
        }
    }

    /** Gagal berarti ada yang tidak berfungsi, tapi bukan bahaya. */
    private function sarankan(bool $lolos, string $pesan, string $saran): void
    {
        if (! $lolos) {
            $this->temuan[] = ['level' => 'PERHATIAN', 'pesan' => $pesan, 'saran' => $saran];
        }
    }

    private function laporkan(): int
    {
        $this->newLine();

        if ($this->temuan === []) {
            $this->components->info('Semua pemeriksaan lolos. Environment siap.');

            return self::SUCCESS;
        }

        $fatal = 0;

        foreach ($this->temuan as $t) {

            if ($t['level'] === 'FATAL') {
                $fatal++;

                $this->components->error($t['pesan']);
            } else {
                $this->components->warn($t['pesan']);
            }

            $this->line('        '.$t['saran']);
            $this->newLine();
        }

        $this->components->twoColumnDetail(
            'Ringkasan',
            sprintf('%d fatal, %d perhatian', $fatal, count($this->temuan) - $fatal)
        );

        return $fatal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
