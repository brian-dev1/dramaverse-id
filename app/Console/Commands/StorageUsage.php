<?php

namespace App\Console\Commands;

use App\Models\StorageProvider;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Services\Storage\StorageMonitorService;
use App\Support\Bytes;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * Menghitung isi bucket tiap storage provider, langsung dari bucketnya.
 *
 * ## Kenapa perintah ini ada
 *
 * Halaman monitoring storage menampilkan ukuran berkas per provider, tetapi
 * angka itu berasal dari kolom `size` di tabel `episode_videos` dan
 * `drama_assets` — yaitu catatan aplikasi tentang apa yang PERNAH diunggah,
 * bukan isi bucket saat ini.
 *
 * Selama semua penghapusan lewat aplikasi, keduanya sama. Begitu ada berkas
 * yang dihapus langsung dari bucket — lewat VPS, lewat konsol penyedia, lewat
 * lifecycle rule — kedua angka itu berpisah, dan yang menentukan tagihan
 * adalah isi bucketnya, bukan catatan database.
 *
 * Perintah ini membaca isi bucket apa adanya lalu menaruh kedua angka itu
 * bersebelahan. Selisihnya bukan galat; justru selisih itulah yang paling
 * berguna dilihat.
 *
 * ```
 * php artisan storage:usage
 * php artisan storage:usage kilat
 * php artisan storage:usage kilat --rincian --dalam=2
 * php artisan storage:usage --besar=15
 * php artisan storage:usage --db
 * ```
 *
 * ## Cara menghitungnya
 *
 * Ukuran diambil dari hasil LIST, bukan dari HEAD per berkas. Penyedia S3
 * mengembalikan ukuran tiap objek di dalam jawaban LIST-nya, dan LIST melayani
 * 1000 objek sekali jalan. Menanyakan ukuran satu per satu — yang dilakukan
 * `Storage::size()` — berarti seribu kali perjalanan bolak-balik untuk
 * informasi yang sudah ada di tangan. Untuk bucket berisi ribuan video,
 * bedanya bukan detik, melainkan menit.
 */
class StorageUsage extends Command
{
    protected $signature = 'storage:usage
                            {provider?* : Slug atau id provider. Kosongkan untuk memeriksa semuanya}
                            {--rincian : Tampilkan rincian ukuran per folder}
                            {--dalam=1 : Kedalaman folder pada rincian}
                            {--besar=0 : Tampilkan sekian berkas terbesar}
                            {--db : Jangan hubungi bucket, tampilkan catatan database saja}';

    protected $description = 'Hitung isi bucket tiap storage provider dan bandingkan dengan catatan database';

    /** Satu titik kemajuan dicetak tiap sekian objek. */
    private const DENYUT = 500;

    public function __construct(
        protected StorageManagerInterface $manager,
        protected StorageMonitorService $monitor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $providers = $this->providers();

        if ($providers === []) {
            $this->components->error('Tidak ada storage provider yang cocok.');

            return self::FAILURE;
        }

        // Satu kali baca untuk semua provider. Dipanggil di luar perulangan
        // karena isinya sudah dikelompokkan per provider id.
        $catatan = $this->monitor->filesPerProvider();

        $totalBucket = 0;
        $totalBerkas = 0;
        $adaKegagalan = false;

        foreach ($providers as $provider) {

            $id = (int) $provider->getKey();

            $db = $catatan[$id] ?? ['jumlah' => 0, 'ukuran' => 0];

            $this->judul($provider);

            $this->components->twoColumnDetail(
                'Catatan database',
                number_format((int) $db['jumlah'], 0, ',', '.').' berkas · '
                    .Bytes::forHumans((int) $db['ukuran'])
            );

            if ($this->option('db')) {
                continue;
            }

            $hasil = $this->pindai($provider);

            if ($hasil === null) {
                $adaKegagalan = true;

                continue;
            }

            $this->components->twoColumnDetail(
                '<options=bold>Isi bucket sekarang</>',
                '<options=bold>'.number_format($hasil['jumlah'], 0, ',', '.').' objek · '
                    .Bytes::forHumans($hasil['bytes']).'</> '
                    .'<fg=gray>('.$hasil['detik'].' dtk)</>'
            );

            $totalBucket += $hasil['bytes'];
            $totalBerkas += $hasil['jumlah'];

            $this->selisih($hasil['bytes'], (int) $db['ukuran']);

            if ($this->option('rincian')) {
                $this->tabelFolder($hasil['folder']);
            }

            if ((int) $this->option('besar') > 0) {
                $this->tabelTerbesar($hasil['besar']);
            }
        }

        if (! $this->option('db') && count($providers) > 1) {

            $this->newLine();
            $this->components->twoColumnDetail(
                '<options=bold>SELURUH PROVIDER</>',
                '<options=bold>'.number_format($totalBerkas, 0, ',', '.').' objek · '
                    .Bytes::forHumans($totalBucket).'</>'
            );
        }

        $this->catatanKaki();

        return $adaKegagalan ? self::FAILURE : self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Pemilihan provider
    |--------------------------------------------------------------------------
    */

    /**
     * Provider yang akan diperiksa.
     *
     * Yang nonaktif ikut ditampilkan. Provider dinonaktifkan bukan berarti
     * bucketnya kosong — justru provider yang baru saja dimatikan adalah yang
     * paling sering perlu diperiksa isinya sebelum bucketnya ditutup.
     *
     * @return array<int, StorageProvider>
     */
    private function providers(): array
    {
        $diminta = (array) $this->argument('provider');

        if ($diminta === []) {
            return StorageProvider::query()->byPriority()->get()->all();
        }

        $hasil = [];

        foreach ($diminta as $kunci) {

            $provider = StorageProvider::query()
                ->where('slug', $kunci)
                ->when(ctype_digit((string) $kunci), fn ($q) => $q->orWhere('id', (int) $kunci))
                ->first();

            if ($provider === null) {
                $this->components->warn('Provider "'.$kunci.'" tidak ditemukan, dilewati.');

                continue;
            }

            $hasil[] = $provider;
        }

        return $hasil;
    }

    private function judul(StorageProvider $provider): void
    {
        $this->newLine();

        $keadaan = $provider->isActive()
            ? '<fg=green>aktif</>'
            : '<fg=yellow>nonaktif</>';

        if ($provider->is_default) {
            $keadaan .= ' <fg=gray>· default</>';
        }

        $this->components->twoColumnDetail(
            '<options=bold>'.$provider->name.'</> <fg=gray>('.$provider->slug.')</>',
            $keadaan
        );

        $this->components->twoColumnDetail('Driver', $provider->driver->label());

        $tujuan = filled($provider->bucket)
            ? $provider->bucket.(filled($provider->root) ? '/'.trim((string) $provider->root, '/') : '')
            : (string) ($provider->root ?: '-');

        $this->components->twoColumnDetail('Bucket', $tujuan);
    }

    /*
    |--------------------------------------------------------------------------
    | Pemindaian
    |--------------------------------------------------------------------------
    */

    /**
     * Telusuri seluruh isi disk provider ini.
     *
     * Kegagalan satu provider TIDAK menghentikan yang lain. Kredensial yang
     * kedaluwarsa pada satu bucket adalah alasan paling sering perintah ini
     * dijalankan, dan berhenti di provider pertama yang bermasalah berarti
     * angka provider lain — yang mungkin baik-baik saja — tidak pernah
     * terlihat.
     *
     * @return array{jumlah: int, bytes: int, folder: array<string, array{jumlah: int, bytes: int}>, besar: array<int, array{path: string, bytes: int}>, detik: string}|null
     */
    private function pindai(StorageProvider $provider): ?array
    {
        try {
            $disk = $this->manager->build($provider);

        } catch (Throwable $e) {

            $this->components->error('Disk tidak bisa dibangun: '.$e->getMessage());

            return null;
        }

        if (! $disk instanceof FilesystemAdapter) {

            $this->components->error('Disk ini tidak mendukung penelusuran isi.');

            return null;
        }

        $dalam = max(1, (int) $this->option('dalam'));

        $simpanTerbesar = (int) $this->option('besar') > 0;

        $jumlah = 0;
        $bytes = 0;
        $folder = [];
        $besar = [];

        $mulai = microtime(true);

        $this->output->write('  <fg=gray>memindai</> ');

        try {
            /*
            |------------------------------------------------------------------
            | listContents, bukan allFiles
            |------------------------------------------------------------------
            |
            | `allFiles()` mengembalikan daftar nama saja, sehingga ukurannya
            | harus ditanyakan ulang satu per satu. `listContents()` memberi
            | objek atributnya sekaligus — nama DAN ukuran — dari jawaban LIST
            | yang sama.
            |
            | Ia juga generator: objek dibaca per halaman sambil berjalan,
            | jadi bucket berisi ratusan ribu objek tidak perlu muat seluruhnya
            | di memori lebih dulu.
            |
            */
            foreach ($disk->getDriver()->listContents('', true) as $item) {

                /** @var StorageAttributes $item */
                if (! $item->isFile()) {
                    continue;
                }

                $ukuran = (int) ($item->fileSize() ?? 0);

                $jumlah++;
                $bytes += $ukuran;

                $kunci = $this->folderKey($item->path(), $dalam);

                $folder[$kunci]['jumlah'] = ($folder[$kunci]['jumlah'] ?? 0) + 1;
                $folder[$kunci]['bytes'] = ($folder[$kunci]['bytes'] ?? 0) + $ukuran;

                if ($simpanTerbesar) {
                    $besar[] = ['path' => $item->path(), 'bytes' => $ukuran];
                }

                if ($jumlah % self::DENYUT === 0) {
                    $this->output->write('<fg=gray>.</>');
                }
            }

        } catch (Throwable $e) {

            $this->newLine();
            $this->components->error('Gagal membaca isi bucket: '.$e->getMessage());

            return null;
        }

        $this->output->write("\r".str_repeat(' ', 60)."\r");

        // uasort, bukan arsort. `arsort` membandingkan array pembanding
        // elemen demi elemen — yang pertama dibandingkannya adalah `jumlah`,
        // sehingga folder berisi banyak berkas kecil naik ke atas mendahului
        // folder berisi sedikit berkas besar. Yang dicari di sini yang memakan
        // ruang, bukan yang paling ramai.
        uasort($folder, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        usort($besar, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return [
            'jumlah' => $jumlah,
            'bytes'  => $bytes,
            'folder' => $folder,
            'besar'  => array_slice($besar, 0, (int) $this->option('besar')),
            'detik'  => number_format(microtime(true) - $mulai, 1),
        ];
    }

    /**
     * Nama folder pengelompokan untuk sebuah path.
     *
     * Berkas yang berada langsung di akar tidak punya folder, dan menaruhnya
     * di bawah namanya sendiri akan membuat satu baris per berkas — persis
     * kebalikan dari gunanya rincian ini.
     */
    private function folderKey(string $path, int $dalam): string
    {
        $bagian = explode('/', trim($path, '/'));

        if (count($bagian) <= $dalam) {
            return '(akar)';
        }

        return implode('/', array_slice($bagian, 0, $dalam));
    }

    /*
    |--------------------------------------------------------------------------
    | Tampilan
    |--------------------------------------------------------------------------
    */

    /**
     * Jelaskan arti selisih antara bucket dan database.
     *
     * Dua arah selisih punya sebab dan akibat yang sama sekali berbeda, dan
     * menyebut keduanya "tidak sinkron" saja tidak membantu siapa pun
     * memutuskan apa yang harus dilakukan.
     */
    private function selisih(int $bucket, int $db): void
    {
        // Selisih kecil selalu ada: berkas uji, poster turunan, sisa multipart.
        // Menandai selisih 0,3% sebagai masalah membuat peringatannya diabaikan
        // justru ketika selisihnya besar.
        $ambang = max(1024 * 1024 * 64, (int) ($db * 0.01));

        $beda = $bucket - $db;

        if (abs($beda) <= $ambang) {
            return;
        }

        if ($beda > 0) {

            $this->components->twoColumnDetail(
                '<fg=yellow>Lebih besar dari catatan</>',
                '<fg=yellow>+'.Bytes::forHumans($beda).'</>'
            );

            $this->line(
                '        <fg=gray>Ada isi bucket yang tidak tercatat di database: '
                .'sisa unggahan yang gagal, berkas uji, atau berkas yang barisnya '
                .'sudah dihapus tetapi objeknya tertinggal. Ini yang tetap ditagih '
                .'penyedia meski aplikasi tidak lagi memakainya.</>'
            );

            return;
        }

        $this->components->twoColumnDetail(
            '<fg=yellow>Lebih kecil dari catatan</>',
            '<fg=yellow>-'.Bytes::forHumans(abs($beda)).'</>'
        );

        $this->line(
            '        <fg=gray>Database mencatat berkas yang sudah tidak ada di bucket — '
            .'biasanya karena dihapus langsung dari bucket. Video yang sudah punya '
            .'telegram_file_id tetap bisa diputar di bot; yang belum punya, berkasnya '
            .'benar-benar hilang.</>'
        );
    }

    /**
     * @param  array<string, array{jumlah: int, bytes: int}>  $folder
     */
    private function tabelFolder(array $folder): void
    {
        if ($folder === []) {
            return;
        }

        $this->newLine();

        $this->table(
            ['Folder', 'Objek', 'Ukuran'],
            array_map(
                fn (string $nama, array $angka) => [
                    $nama,
                    number_format($angka['jumlah'], 0, ',', '.'),
                    Bytes::forHumans($angka['bytes']),
                ],
                array_keys($folder),
                array_values($folder)
            )
        );
    }

    /**
     * @param  array<int, array{path: string, bytes: int}>  $besar
     */
    private function tabelTerbesar(array $besar): void
    {
        if ($besar === []) {
            return;
        }

        $this->newLine();

        $this->table(
            ['Berkas terbesar', 'Ukuran'],
            array_map(
                fn (array $b) => [$b['path'], Bytes::forHumans($b['bytes'])],
                $besar
            )
        );
    }

    /**
     * Batas yang perlu diketahui sebelum angkanya dipakai untuk menebak tagihan.
     *
     * Keduanya membuat angka di atas lebih KECIL daripada yang ditagih, dan
     * arah itu penting: orang yang mengira sudah menghapus semuanya lalu
     * menerima tagihan penuh biasanya berhenti di salah satu dari dua hal ini.
     */
    private function catatanKaki(): void
    {
        $this->newLine();

        $this->line('  <fg=gray>Yang dihitung adalah objek yang terlihat oleh LIST.</>');
        $this->line('  <fg=gray>Tidak termasuk: versi lama bila bucket memakai versioning, dan</>');
        $this->line('  <fg=gray>potongan multipart upload yang tidak selesai — keduanya tetap</>');
        $this->line('  <fg=gray>memakan ruang dan tetap ditagih.</>');
        $this->newLine();
    }
}
