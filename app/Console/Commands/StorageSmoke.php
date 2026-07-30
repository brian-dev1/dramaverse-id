<?php

namespace App\Console\Commands;

use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menjalankan satu siklus penuh Storage Engine terhadap provider sungguhan.
 *
 * Ini alat verifikasi, bukan fitur. Alasannya keberadaannya sederhana: tanpa
 * perintah ini, Storage Engine tidak bisa dibuktikan bekerja sampai modul
 * upload dibuat di sprint berikutnya — dan kalau ada yang salah di engine,
 * kesalahannya baru ketahuan bercampur dengan kesalahan modul barunya.
 *
 * Yang diuji: putContents, metadata, url, temporaryUrl, copy, rename, move,
 * exists, dan delete. Semuanya lewat StorageEngineInterface, tidak ada satu
 * pun yang menyentuh Storage secara langsung — jadi yang diuji memang jalur
 * yang akan dipakai modul lain.
 *
 * Berkas uji berukuran beberapa ratus byte, ditulis di direktori `_smoke`,
 * dan dihapus di akhir termasuk ketika ada langkah yang gagal.
 */
class StorageSmoke extends Command
{
    protected $signature = 'storage:smoke
                            {provider? : Id atau slug provider. Kosongkan untuk memakai mode Auto (provider default)}
                            {--keep : Jangan hapus berkas uji di akhir}';

    protected $description = 'Uji satu siklus penuh Storage Engine: tulis, baca, salin, pindah, hapus';

    private const DIRECTORY = '_smoke';

    public function __construct(
        protected StorageEngineInterface $engine
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = $this->argument('provider');

        try {
            $provider = $this->engine->resolveProvider($target);
        } catch (StorageEngineException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $mode = $target === null ? 'Auto (default)' : 'Manual';

        $this->components->info(sprintf(
            'Provider: %s (%s) — mode %s',
            $provider->slug,
            $provider->driver->label(),
            $mode
        ));

        $providerId = (int) $provider->getKey();

        $isi = 'dramaverse storage engine smoke test '.now()->toIso8601String()
            .str_repeat(' .', 64);

        $rows = [];

        // Key yang perlu dibersihkan, dikumpulkan sambil jalan supaya blok
        // finally tahu apa saja yang sudah sempat dibuat.
        $sisa = [];

        $gagal = 0;

        $langkah = function (string $nama, callable $fn) use (&$rows, &$gagal) {
            try {
                $hasil = $fn();

                $rows[] = [$nama, 'OK', is_string($hasil) ? $hasil : '-'];

                return $hasil;
            } catch (Throwable $e) {
                $gagal++;

                $rows[] = [$nama, 'GAGAL', $e->getMessage()];

                return null;
            }
        };

        try {
            // --- Tulis ---
            $file = $langkah('putContents', function () use ($isi, $providerId, &$sisa) {
                $stored = $this->engine->putContents(
                    $isi,
                    self::DIRECTORY,
                    'smoke-'.now()->format('Ymd-His').'.txt',
                    $providerId
                );

                $sisa[] = $stored->objectKey;

                return $stored->objectKey.' ('.$stored->sizeForHumans().')';
            });

            if ($file === null) {
                $this->tampilkan($rows);
                $this->components->error(
                    'Langkah pertama gagal, sisanya dilewati. Perbaiki ini dulu.'
                );

                return self::FAILURE;
            }

            $key = $sisa[0];

            // --- Baca ---
            $langkah('exists', function () use ($providerId, $key) {
                $ada = $this->engine->exists($providerId, $key);

                if (! $ada) {
                    throw new \RuntimeException(
                        'Berkas baru ditulis tetapi exists() melaporkan tidak ada. '
                        .'Pada bucket di balik CDN ini bisa berarti pembacaannya '
                        .'dilayani cache.'
                    );
                }

                return 'ada';
            });

            $langkah('metadata', function () use ($providerId, $key, $isi) {
                $meta = $this->engine->metadata($providerId, $key);

                $catatan = [];

                if ($meta->size !== null && $meta->size !== strlen($isi)) {
                    throw new \RuntimeException(sprintf(
                        'Ukuran tidak cocok: ditulis %d byte, dilaporkan %d byte.',
                        strlen($isi),
                        $meta->size
                    ));
                }

                $catatan[] = 'ukuran '.($meta->sizeForHumans() ?? 'tidak dilaporkan');
                $catatan[] = 'mime '.($meta->mimeType ?? 'tidak dilaporkan');
                $catatan[] = 'visibility '.($meta->visibility ?? 'tidak dilaporkan');

                return implode(', ', $catatan);
            });

            $langkah('url', function () use ($providerId, $key) {
                $url = $this->engine->url($providerId, $key);

                // null bukan kegagalan: provider tanpa public_url memang tidak
                // punya alamat publik yang bisa ditebak engine.
                return $url ?? 'tidak tersedia (public_url belum diisi, atau berkas privat)';
            });

            $langkah('temporaryUrl', function () use ($providerId, $key) {
                $url = $this->engine->temporaryUrl($providerId, $key, 5, strict: false);

                return $url === null
                    ? 'tidak didukung provider ini'
                    : 'berhasil dibuat, berlaku 5 menit';
            });

            // --- Salin, ganti nama, pindah ---
            $langkah('copy', function () use ($providerId, $key, &$sisa) {
                $salinan = $this->engine->copy($providerId, $key, self::DIRECTORY.'/salinan');

                $sisa[] = $salinan->objectKey;

                return $salinan->objectKey;
            });

            $langkah('rename', function () use ($providerId, $key, &$sisa) {
                $baru = $this->engine->rename($providerId, $key, 'sudah-diganti-nama.txt');

                // Key lama sudah tidak ada; ganti catatannya.
                $sisa[0] = $baru->objectKey;

                return $baru->objectKey;
            });

            $langkah('move', function () use ($providerId, &$sisa) {
                $baru = $this->engine->move($providerId, $sisa[0], self::DIRECTORY.'/dipindah');

                $sisa[0] = $baru->objectKey;

                return $baru->objectKey;
            });

            // --- Penjagaan: path traversal harus ditolak ---
            $langkah('tolak path traversal', function () use ($providerId) {
                try {
                    $this->engine->putContents('x', '../../etc', 'jahat.txt', $providerId);
                } catch (StorageEngineException $e) {
                    return 'ditolak sebagaimana mestinya';
                }

                throw new \RuntimeException(
                    'BAHAYA: direktori "../../etc" DITERIMA. Ini lubang keamanan.'
                );
            });

            $langkah('tolak ekstensi yang dieksekusi', function () use ($providerId) {
                try {
                    // Nama ini melewati pemeriksaan gambar yang naif karena
                    // memuat ".jpg", padahal ekstensi sebenarnya .php.
                    $this->engine->putContents(
                        '<?php echo 1;',
                        self::DIRECTORY,
                        'shell.jpg.php',
                        $providerId
                    );
                } catch (StorageEngineException $e) {
                    return 'ditolak sebagaimana mestinya';
                }

                throw new \RuntimeException(
                    'BAHAYA: berkas .php DITERIMA. Pada provider lokal berkas '
                    .'itu bisa dieksekusi lewat /storage.'
                );
            });

            // --- Hapus ---
            if (! $this->option('keep')) {
                foreach ($sisa as $i => $k) {
                    $langkah('delete #'.($i + 1), function () use ($providerId, $k) {
                        return $this->engine->delete($providerId, $k)
                            ? 'terhapus: '.$k
                            : 'sudah tidak ada: '.$k;
                    });
                }

                $langkah('delete idempoten', function () use ($providerId, $sisa) {
                    $hasil = $this->engine->delete($providerId, $sisa[0]);

                    if ($hasil !== false) {
                        throw new \RuntimeException(
                            'Menghapus berkas yang sudah tidak ada seharusnya '
                            .'mengembalikan false, bukan true.'
                        );
                    }

                    return 'mengembalikan false, benar';
                });

                $sisa = [];
            }

        } finally {

            // Pembersihan darurat: kalau ada langkah yang gagal di tengah,
            // berkas uji tidak boleh tertinggal di bucket sungguhan.
            if (! $this->option('keep') && $sisa !== []) {
                foreach ($sisa as $k) {
                    try {
                        $this->engine->delete($providerId, $k);
                    } catch (Throwable) {
                        $this->components->warn(
                            "Berkas uji tertinggal dan perlu dihapus manual: {$k}"
                        );
                    }
                }
            }
        }

        $this->tampilkan($rows);

        if ($gagal > 0) {
            $this->components->error("{$gagal} langkah gagal.");

            return self::FAILURE;
        }

        if ($this->option('keep')) {
            $this->components->warn(
                'Berkas uji SENGAJA dibiarkan di direktori '.self::DIRECTORY.'.'
            );
        }

        $this->components->info('Storage Engine berfungsi utuh pada provider ini.');

        return self::SUCCESS;
    }

    private function tampilkan(array $rows): void
    {
        $this->newLine();

        $this->table(['Langkah', 'Hasil', 'Keterangan'], $rows);
    }
}
