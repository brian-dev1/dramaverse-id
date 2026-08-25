<?php

namespace App\Console\Commands;

use App\Models\EpisodeVideo;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Support\Bytes;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * Objek terbaru di bucket, diurutkan dari yang paling baru.
 *
 * ## Kenapa perintah ini ada
 *
 * `allFiles()` mengembalikan nama berkas saja, tanpa urutan yang berarti
 * dan tanpa waktu ubah. Untuk bucket berisi ratusan objek, mencari "mana
 * yang barusan masuk" jadi mustahil — dan itu justru pertanyaan yang paling
 * sering muncul sesudah menjalankan downloader.
 *
 * Perintah ini memakai `listContents()`, yang mengembalikan nama, ukuran,
 * DAN waktu ubah sekaligus dari jawaban LIST yang sama. Menanyakan waktu
 * ubah satu per satu lewat `lastModified()` berarti satu perjalanan
 * bolak-balik per objek; untuk bucket berisi ribuan berkas bedanya bukan
 * detik, melainkan menit.
 *
 * ```
 * php artisan storage:baru kilat                # 6 jam terakhir
 * php artisan storage:baru kilat --jam=24       # sehari terakhir
 * php artisan storage:baru kilat --semua        # semua, terbaru di atas
 * php artisan storage:baru kilat --db           # + status sinkron Telegram
 * php artisan storage:baru                      # semua provider
 * ```
 *
 * Opsi `--db` mencocokkan tiap objek dengan barisnya di `episode_videos`,
 * jadi terlihat sekaligus mana yang sudah tersinkron ke Telegram dan mana
 * yang objeknya ada di bucket tetapi tidak tercatat di database sama sekali.
 */
class StorageBaru extends Command
{
    protected $signature = 'storage:baru
                            {provider?* : Slug atau id provider. Kosongkan untuk semua}
                            {--jam=6 : Hanya objek yang lebih baru dari sekian jam}
                            {--semua : Abaikan --jam, tampilkan semuanya}
                            {--jumlah=50 : Maksimal baris yang ditampilkan}
                            {--db : Cocokkan dengan catatan episode_videos}';

    protected $description = 'Tampilkan objek terbaru di bucket, diurutkan dari yang paling baru';

    public function handle(StorageManagerInterface $storage): int
    {
        $jam = max(0, (int) $this->option('jam'));
        $semua = (bool) $this->option('semua');
        $jumlah = max(1, (int) $this->option('jumlah'));

        $diminta = (array) $this->argument('provider');

        $providers = $diminta === []
            ? $storage->usableProviders()->all()
            : $this->resolveProviders($storage, $diminta);

        if ($providers === []) {
            $this->error('Tidak ada provider yang bisa diperiksa.');

            return self::FAILURE;
        }

        $batas = $semua
            ? null
            : Carbon::now()->subHours($jam)->getTimestamp();

        $keluar = self::SUCCESS;

        foreach ($providers as $provider) {
            $this->line('');
            $this->line("=== {$provider->name} ({$provider->slug})");

            try {
                $berkas = $this->kumpulkan($storage, $provider, $batas);
            } catch (Throwable $galat) {
                $this->error("Gagal membaca bucket: {$galat->getMessage()}");

                $keluar = self::FAILURE;

                continue;
            }

            $this->tampilkan($berkas, $jumlah, $semua, $jam);
        }

        return $keluar;
    }

    /**
     * @return array<int, \App\Models\StorageProvider>
     */
    private function resolveProviders(StorageManagerInterface $storage, array $diminta): array
    {
        $hasil = [];

        foreach ($diminta as $sebutan) {
            try {
                $hasil[] = $storage->provider((string) $sebutan);
            } catch (Throwable $galat) {
                $this->error("Provider '{$sebutan}' tidak dikenal: {$galat->getMessage()}");
            }
        }

        return $hasil;
    }

    /**
     * Baca isi bucket sekali jalan.
     *
     * @return array<int, array{path: string, bytes: int, waktu: int|null}>
     */
    private function kumpulkan(
        StorageManagerInterface $storage,
        $provider,
        ?int $batas
    ): array {
        $disk = $storage->build($provider);

        $berkas = [];

        foreach ($disk->getDriver()->listContents('', true) as $item) {
            /** @var StorageAttributes $item */
            if (! $item->isFile()) {
                continue;
            }

            $waktu = $item->lastModified();

            $waktu = $waktu === null ? null : (int) $waktu;

            // Objek tanpa waktu ubah tidak boleh disaring diam-diam:
            // menyembunyikannya akan terbaca sebagai "tidak ada berkas
            // baru", padahal yang benar adalah "tidak diketahui".
            if ($batas !== null && $waktu !== null && $waktu < $batas) {
                continue;
            }

            $berkas[] = [
                'path' => $item->path(),
                'bytes' => (int) ($item->fileSize() ?? 0),
                'waktu' => $waktu,
            ];
        }

        // Terbaru di atas. Yang waktunya tidak diketahui ditaruh paling
        // bawah, bukan dianggap paling tua.
        usort($berkas, static function (array $a, array $b): int {
            if ($a['waktu'] === $b['waktu']) {
                return strcmp($a['path'], $b['path']);
            }

            if ($a['waktu'] === null) {
                return 1;
            }

            if ($b['waktu'] === null) {
                return -1;
            }

            return $b['waktu'] <=> $a['waktu'];
        });

        return $berkas;
    }

    /**
     * @param  array<int, array{path: string, bytes: int, waktu: int|null}>  $berkas
     */
    private function tampilkan(
        array $berkas,
        int $jumlah,
        bool $semua,
        int $jam
    ): void {
        if ($berkas === []) {
            $this->warn(
                $semua
                    ? 'Bucket kosong.'
                    : "Tidak ada objek baru dalam {$jam} jam terakhir."
            );

            return;
        }

        $catatan = $this->option('db')
            ? $this->catatanDatabase($berkas)
            : [];

        $zona = config('app.display_timezone') ?: config('app.timezone', 'UTC');

        $baris = [];

        foreach (array_slice($berkas, 0, $jumlah) as $item) {
            $kolom = [
                $item['waktu'] === null
                    ? '-'
                    : Carbon::createFromTimestamp($item['waktu'])
                        ->timezone($zona)
                        ->format('d M H:i'),
                $this->umur($item['waktu']),
                Bytes::forHumans($item['bytes']),
                $this->pendekkan($item['path']),
            ];

            if ($this->option('db')) {
                $kolom[] = $catatan[$item['path']] ?? 'tidak tercatat';
            }

            $baris[] = $kolom;
        }

        $judul = ['Waktu', 'Umur', 'Ukuran', 'Object key'];

        if ($this->option('db')) {
            $judul[] = 'Database';
        }

        $this->table($judul, $baris);

        $total = array_sum(array_column($berkas, 'bytes'));

        $ringkas = sprintf(
            '%s objek, %s',
            number_format(count($berkas)),
            Bytes::forHumans($total)
        );

        if (count($berkas) > $jumlah) {
            $ringkas .= sprintf(
                ' (%s teratas ditampilkan, pakai --jumlah untuk lebih banyak)',
                number_format($jumlah)
            );
        }

        $this->line($ringkas);

        $tanpaWaktu = count(array_filter(
            $berkas,
            static fn (array $item): bool => $item['waktu'] === null
        ));

        if ($tanpaWaktu > 0) {
            $this->warn(
                "{$tanpaWaktu} objek tidak menyertakan waktu ubah; "
                .'semuanya tetap ditampilkan di bagian bawah.'
            );
        }
    }

    /**
     * Status tiap object_key menurut database.
     *
     * @param  array<int, array{path: string, bytes: int, waktu: int|null}>  $berkas
     * @return array<string, string>
     */
    private function catatanDatabase(array $berkas): array
    {
        $kunci = array_column($berkas, 'path');

        $hasil = [];

        foreach (array_chunk($kunci, 500) as $bagian) {
            EpisodeVideo::query()
                ->whereIn('object_key', $bagian)
                ->get(['object_key', 'episode_id', 'sync_status', 'telegram_file_id'])
                ->each(function (EpisodeVideo $video) use (&$hasil): void {
                    $hasil[$video->object_key] = $video->isSyncedToTelegram()
                        ? "ep {$video->episode_id} · tersinkron"
                        : "ep {$video->episode_id} · belum sinkron";
                });
        }

        return $hasil;
    }

    private function umur(?int $waktu): string
    {
        if ($waktu === null) {
            return '-';
        }

        $detik = max(0, Carbon::now()->getTimestamp() - $waktu);

        if ($detik < 3600) {
            return max(1, intdiv($detik, 60)).' menit';
        }

        if ($detik < 86400) {
            return round($detik / 3600, 1).' jam';
        }

        return round($detik / 86400, 1).' hari';
    }

    private function pendekkan(string $path): string
    {
        if (strlen($path) <= 60) {
            return $path;
        }

        return '...'.substr($path, -57);
    }
}
