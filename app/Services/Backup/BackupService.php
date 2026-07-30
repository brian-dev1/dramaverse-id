<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Cadangan basis data dan konfigurasi.
 *
 * ## Yang dicadangkan, dan yang tidak
 *
 * **Basis data** — seluruhnya, lewat `mysqldump`, dikompresi gzip.
 *
 * **Konfigurasi** — `.env`, karena tanpa itu basis data hasil restore tidak
 * bisa dibuka: `APP_KEY` yang berbeda membuat seluruh kredensial storage
 * provider yang terenkripsi jadi sampah, dan tidak ada cara memulihkannya.
 *
 * **Berkas video TIDAK dicadangkan.** Ini keputusan, bukan kelalaian. Video
 * ada di storage provider yang sudah punya ketahanannya sendiri, ukurannya
 * ratusan gigabyte, dan menyalinnya ke disk VPS yang sama justru menghabiskan
 * ruang yang dibutuhkan untuk operasi normal. Yang dicadangkan adalah
 * **peta**-nya — tabel `episode_videos` dan `drama_assets` berisi
 * `provider_id` dan `object_key`, dan itulah yang benar-benar tidak bisa
 * dibangun ulang kalau hilang.
 *
 * ## Peringatan yang tidak boleh dilewatkan
 *
 * Berkas cadangan memuat `.env` dalam bentuk teks polos — termasuk
 * `APP_KEY`, kredensial basis data, dan token bot. Foldernya HARUS berada di
 * luar `public/`, dan salinannya yang dipindahkan keluar server harus
 * diperlakukan seperti kata sandi.
 */
class BackupService
{
    /** Nama folder di dalam `storage/app`. */
    public const DIR = 'backups';

    /*
    |--------------------------------------------------------------------------
    | Membuat
    |--------------------------------------------------------------------------
    */

    /**
     * Buat satu cadangan. Mengembalikan path absolut berkasnya.
     *
     * @throws RuntimeException
     */
    public function create(): string
    {
        $dir = $this->directory();

        $nama = 'dramaverse-'.now()->format('Y-m-d_His');

        $sql = $dir.'/'.$nama.'.sql';

        try {
            $this->dumpDatabase($sql);

            $arsip = $dir.'/'.$nama.'.tar.gz';

            $this->pack($arsip, $sql, $nama);

            return $arsip;

        } finally {

            // Dump mentah selalu dibuang, berhasil maupun gagal. Ia berisi
            // seluruh isi basis data tanpa kompresi, dan meninggalkannya
            // berarti menaruh salinan telanjang di disk tanpa ada yang tahu.
            if (is_file($sql)) {
                @unlink($sql);
            }
        }
    }

    /**
     * Jalankan mysqldump ke berkas.
     *
     * Kata sandi dilewatkan lewat **environment**, bukan argumen baris
     * perintah. Argumen terlihat di `ps aux` oleh setiap pengguna di server —
     * itu cara paling mudah membocorkan kata sandi basis data tanpa
     * menyadarinya.
     */
    private function dumpDatabase(string $tujuan): void
    {
        $db = config('database.connections.'.config('database.default'));

        if (($db['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException(
                'Cadangan otomatis hanya mendukung MySQL. Driver saat ini: '
                .($db['driver'] ?? 'tidak diketahui').'.'
            );
        }

        $perintah = [
            'mysqldump',
            '--host='.($db['host'] ?? '127.0.0.1'),
            '--port='.($db['port'] ?? 3306),
            '--user='.($db['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            $db['database'] ?? '',
        ];

        $process = new Process($perintah, base_path(), [
            'MYSQL_PWD' => (string) ($db['password'] ?? ''),
        ], null, 1800);

        $handle = fopen($tujuan, 'w');

        if ($handle === false) {
            throw new RuntimeException("Tidak bisa menulis ke {$tujuan}.");
        }

        try {
            $process->run(function ($jenis, $data) use ($handle) {
                if ($jenis === Process::OUT) {
                    fwrite($handle, $data);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'mysqldump gagal: '.trim($process->getErrorOutput() ?: 'tanpa keterangan')
                .' Pastikan `mysqldump` terpasang di server dan pengguna basis data '
                .'punya izin SELECT, LOCK TABLES, dan SHOW VIEW.'
            );
        }

        if ((filesize($tujuan) ?: 0) < 1024) {
            throw new RuntimeException(
                'Hasil mysqldump terlalu kecil untuk masuk akal. Cadangan dibatalkan '
                .'daripada menyimpan berkas kosong yang terlihat seperti cadangan sah.'
            );
        }
    }

    /** Bungkus dump + .env jadi satu arsip. */
    private function pack(string $arsip, string $sql, string $nama): void
    {
        $daftar = [basename($sql)];

        // .env disalin ke folder cadangan dengan nama tetap supaya isinya
        // dikenali saat restore. Salinannya dibuang setelah dibungkus.
        $envSalinan = dirname($sql).'/env.backup';

        $envAda = is_file(base_path('.env'))
            && @copy(base_path('.env'), $envSalinan);

        if ($envAda) {
            $daftar[] = basename($envSalinan);
        }

        $process = new Process(
            array_merge(['tar', '-czf', $arsip, '-C', dirname($sql)], $daftar),
            base_path(),
            null,
            null,
            600
        );

        $process->run();

        if (is_file($envSalinan)) {
            @unlink($envSalinan);
        }

        if (! $process->isSuccessful() || ! is_file($arsip)) {
            throw new RuntimeException(
                'Pengarsipan gagal: '.trim($process->getErrorOutput() ?: 'tanpa keterangan')
                .' Pastikan `tar` tersedia di server.'
            );
        }

        // Hanya pemilik yang boleh membaca. Berkas ini memuat .env.
        @chmod($arsip, 0600);
    }

    /*
    |--------------------------------------------------------------------------
    | Memeriksa
    |--------------------------------------------------------------------------
    */

    /**
     * Periksa satu berkas cadangan benar-benar bisa dibuka.
     *
     * Cadangan yang tidak pernah diperiksa bukan cadangan — ia baru diketahui
     * rusak pada saat satu-satunya kali ia dibutuhkan.
     *
     * @return array{ok:bool, pesan:string}
     */
    public function verify(string $path): array
    {
        if (! is_file($path)) {
            return ['ok' => false, 'pesan' => 'Berkas tidak ditemukan.'];
        }

        if ((filesize($path) ?: 0) < 1024) {
            return ['ok' => false, 'pesan' => 'Ukuran berkas tidak masuk akal untuk sebuah cadangan.'];
        }

        try {
            // `tar -tzf` membongkar seluruh isi tanpa menuliskannya. Kalau
            // gzip-nya rusak atau arsipnya terpotong, ini yang menangkapnya.
            $daftar = new Process(['tar', '-tzf', $path], base_path(), null, null, 600);

            $daftar->run();

            if (! $daftar->isSuccessful()) {
                return [
                    'ok'    => false,
                    'pesan' => 'Arsip rusak atau terpotong: '
                        .trim($daftar->getErrorOutput() ?: 'tanpa keterangan'),
                ];
            }

            $isi = $daftar->getOutput();

            if (! str_contains($isi, '.sql')) {
                return ['ok' => false, 'pesan' => 'Arsip tidak memuat dump basis data.'];
            }

            return [
                'ok'    => true,
                'pesan' => str_contains($isi, 'env.backup')
                    ? 'Arsip utuh, memuat dump basis data dan .env.'
                    : 'Arsip utuh, memuat dump basis data. Tidak ada .env di dalamnya — '
                        .'restore akan memerlukan APP_KEY dari tempat lain.',
            ];

        } catch (Throwable $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Mendaftar dan memangkas
    |--------------------------------------------------------------------------
    */

    /**
     * Cadangan yang ada, terbaru lebih dulu.
     *
     * @return Collection<int,array{nama:string, path:string, size:int, waktu:Carbon}>
     */
    public function all(): Collection
    {
        $berkas = glob($this->directory().'/*.tar.gz') ?: [];

        return collect($berkas)
            ->map(fn (string $path) => [
                'nama'  => basename($path),
                'path'  => $path,
                'size'  => (int) (filesize($path) ?: 0),
                'waktu' => Carbon::createFromTimestamp(filemtime($path) ?: 0),
            ])
            ->sortByDesc('waktu')
            ->values();
    }

    /**
     * Sisakan sejumlah cadangan terbaru, buang sisanya.
     *
     * @return int jumlah yang dihapus
     */
    public function prune(?int $simpan = null): int
    {
        $simpan = max(1, $simpan ?? (int) config('backup.keep', 7));

        $dihapus = 0;

        foreach ($this->all()->slice($simpan) as $item) {
            if (@unlink($item['path'])) {
                $dihapus++;
            }
        }

        return $dihapus;
    }

    /** Ruang yang terpakai seluruh cadangan, dalam byte. */
    public function totalSize(): int
    {
        return (int) $this->all()->sum('size');
    }

    /** Cadangan terbaru, atau null. */
    public function latest(): ?array
    {
        return $this->all()->first();
    }

    /**
     * Umur cadangan terbaru dalam jam, atau null bila belum ada satu pun.
     *
     * Dipakai dashboard monitoring: cadangan yang berhenti berjalan tidak
     * memberi galat apa pun — yang terlihat hanya angka ini berhenti naik.
     */
    public function ageInHours(): ?int
    {
        $terbaru = $this->latest();

        return $terbaru === null ? null : (int) $terbaru['waktu']->diffInHours(now());
    }

    /*
    |--------------------------------------------------------------------------
    | Folder
    |--------------------------------------------------------------------------
    */

    /** Folder cadangan, dibuat bila belum ada. */
    public function directory(): string
    {
        $dir = storage_path('app/'.self::DIR);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }
}
