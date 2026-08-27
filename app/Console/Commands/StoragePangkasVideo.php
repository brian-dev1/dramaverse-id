<?php

namespace App\Console\Commands;

use App\Models\EpisodeVideo;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Support\Bytes;
use BackedEnum;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menghapus objek video dari bucket setelah videonya tersinkron ke Telegram.
 *
 * ## Kenapa ini aman
 *
 * Bucket bukan tempat penonton mengambil video. Tidak ada satu pun jalur
 * bucket -> penonton di aplikasi ini: halaman episode hanya merender deep
 * link ke bot, dan bot mengirim `telegram_file_id` yang dibaca langsung dari
 * database oleh `TelegramCacheService::readFileId()`. Bucket dibaca tepat
 * sekali, saat `TelegramVideoSyncService::pullToTempFile()` mengunggah
 * videonya ke Telegram pertama kali.
 *
 * Kontrak `StorageEngineInterface::readStream()` bahkan sudah menyebut
 * keadaan ini sebagai hal yang wajar: "Berkas yang barisnya masih tercatat
 * tetapi objeknya sudah hilang dari bucket adalah keadaan yang wajar setelah
 * ada yang membersihkan bucket secara manual."
 *
 * ## Kenapa TIDAK lewat File Manager
 *
 * `/admin/files` punya tombol Hapus, dan tombol itu memanggil
 * `FileManagerService::delete()` yang menghapus objek bucket LALU menjalankan
 * `$file->delete()`. Baris `episode_videos` ikut hilang, dan bersamanya
 * `telegram_file_id`. Videonya mati permanen.
 *
 * Perintah ini tidak pernah menyentuh baris database. Yang berubah hanya isi
 * bucket. `object_key` tetap tercatat dan akan menunjuk objek yang sudah
 * tidak ada — itu memang konsekuensinya, dan hanya berdampak pada re-sync.
 *
 * ## Yang Anda lepaskan
 *
 * Ini pintu satu arah. Sesudah objeknya hilang, `pullToTempFile()` akan
 * selalu gagal, jadi sinkronisasi ulang mustahil selamanya. Satu-satunya
 * salinan byte video yang tersisa ada di channel `TELEGRAM_STORAGE_CHAT_ID`.
 * Karena itu `telegram_message_id` diperlakukan sebagai syarat: tanpa nomor
 * pesan itu, video di channel penyimpanan tidak bisa ditemukan lagi kalau
 * suatu saat `file_id`-nya ditolak.
 *
 * Perlu diketahui juga: `VerifyTelegramFileId` MELEWATI verifikasi untuk
 * berkas di atas 20 MB, karena `getFile` Bot API memang tidak melayaninya.
 * Artinya untuk hampir semua episode, kesehatan `file_id` tidak bisa
 * dibuktikan lebih dulu. Yang bisa dilakukan perintah ini adalah memastikan
 * catatannya lengkap, bukan memastikan Telegram masih mau melayaninya.
 *
 * ```
 * php artisan storage:pangkas-video kilat                    # laporan saja
 * php artisan storage:pangkas-video kilat --umur=30          # yang sudah lewat 30 hari
 * php artisan storage:pangkas-video kilat --umur=30 --hapus  # benar-benar hapus
 * php artisan storage:pangkas-video kilat --batas=50 --hapus # sedikit dulu
 * ```
 */
class StoragePangkasVideo extends Command
{
    protected $signature = 'storage:pangkas-video
                            {provider : Slug atau id storage provider, mis. kilat}
                            {--umur=0 : Hanya video yang tersinkron lebih dari sekian hari lalu}
                            {--batas=0 : Maksimal sekian video sekali jalan, 0 = tanpa batas}
                            {--termasuk-tanpa-pesan : Ikut sertakan video tanpa telegram_message_id}
                            {--hapus : Benar-benar hapus objeknya. Tanpa ini hanya laporan}
                            {--laporan= : Tulis daftar object_key yang dihapus ke berkas ini}';

    protected $description = 'Hapus objek video dari bucket setelah tersinkron ke Telegram, tanpa menyentuh baris database';

    public function handle(StorageEngineInterface $engine): int
    {
        try {
            $provider = $engine->resolveProvider($this->argument('provider'));
        } catch (Throwable $galat) {
            $this->error("Provider tidak dikenal: {$galat->getMessage()}");
            $this->line('Lihat daftar provider: php artisan storage:usage');

            return self::FAILURE;
        }

        $umur = max(0, (int) $this->option('umur'));
        $batas = max(0, (int) $this->option('batas'));
        $ikutTanpaPesan = (bool) $this->option('termasuk-tanpa-pesan');

        $this->line('');
        $this->line("Provider : {$provider->name} ({$provider->slug})");
        $this->line('Mode     : '.($this->option('hapus') ? 'HAPUS' : 'laporan saja (dry-run)'));
        $this->line('');

        $semua = EpisodeVideo::query()
            ->where('storage_provider_id', $provider->id)
            ->orderBy('id')
            ->get();

        if ($semua->isEmpty()) {
            $this->warn('Tidak ada video tercatat di provider ini.');

            return self::SUCCESS;
        }

        $aman = [];
        $ditahan = [];

        foreach ($semua as $video) {
            $alasan = $this->alasanDitahan($video, $umur, $ikutTanpaPesan);

            if ($alasan === null) {
                $aman[] = $video;

                continue;
            }

            $ditahan[$alasan] = ($ditahan[$alasan] ?? 0) + 1;
        }

        if ($batas > 0) {
            $aman = array_slice($aman, 0, $batas);
        }

        $this->laporkan($semua->count(), $aman, $ditahan, $batas);

        if ($aman === []) {
            $this->line('');
            $this->warn('Tidak ada yang memenuhi syarat. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('hapus')) {
            $this->line('');
            $this->info('Ini baru laporan — belum ada satu byte pun yang dihapus.');
            $this->line('Tambahkan --hapus kalau angka di atas sudah sesuai.');

            return self::SUCCESS;
        }

        if (! $this->konfirmasi($provider->slug, count($aman))) {
            $this->line('');
            $this->warn('Dibatalkan. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        return $this->hapus($engine, $provider, $aman);
    }

    /**
     * Kenapa satu video TIDAK boleh dihapus objeknya.
     *
     * Mengembalikan null bila video itu memenuhi semua syarat.
     */
    private function alasanDitahan(
        EpisodeVideo $video,
        int $umur,
        bool $ikutTanpaPesan
    ): ?string {
        if (! $video->isSyncedToTelegram()) {
            $status = $video->sync_status instanceof BackedEnum
                ? $video->sync_status->value
                : (string) $video->sync_status;

            return "belum tersinkron ke Telegram (sync_status = {$status})";
        }

        if ($video->hasActiveIssue()) {
            return 'ada masalah aktif yang belum diselesaikan';
        }

        if (! $ikutTanpaPesan && blank($video->telegram_message_id)) {
            return 'tidak ada telegram_message_id — tidak ada jalur pulih';
        }

        if ($umur > 0) {
            $tanggal = $video->synced_at;

            // Sengaja tidak cukup `=== null`. Kolom yang tidak ter-cast,
            // hasil query mentah, atau nilai kosong bentuk lain akan lolos
            // pemeriksaan itu lalu meledak di ->gt(). Perintah yang tugasnya
            // menghapus harus MENAHAN apa pun yang tidak bisa dipastikan,
            // bukan berhenti dengan galat di tengah jalan.
            if (blank($tanggal) || ! is_object($tanggal) || ! method_exists($tanggal, 'gt')) {
                return 'synced_at tidak terbaca, umurnya tidak bisa dipastikan';
            }

            if ($tanggal->gt(now()->subDays($umur))) {
                return "baru tersinkron, belum lewat {$umur} hari";
            }
        }

        return null;
    }

    /**
     * @param  array<int, EpisodeVideo>  $aman
     * @param  array<string, int>  $ditahan
     */
    private function laporkan(
        int $total,
        array $aman,
        array $ditahan,
        int $batas
    ): void {
        $ukuran = array_sum(array_map(
            static fn (EpisodeVideo $video): int => (int) $video->size,
            $aman
        ));

        $this->table(
            ['Keterangan', 'Jumlah'],
            [
                ['Video tercatat di provider ini', number_format($total)],
                ['Memenuhi syarat hapus', number_format(count($aman))],
                ['Ditahan', number_format($total - count($aman))],
                ['Ruang yang akan dibebaskan', Bytes::forHumans($ukuran)],
            ]
        );

        if ($batas > 0) {
            $this->line("Dibatasi --batas={$batas} video sekali jalan.");
            $this->line('');
        }

        if ($ditahan !== []) {
            $this->line('Alasan ditahan:');

            arsort($ditahan);

            foreach ($ditahan as $alasan => $jumlah) {
                $this->line(sprintf('  %5s  %s', number_format($jumlah), $alasan));
            }

            $this->line('');
        }

        $contoh = array_slice($aman, 0, 10);

        if ($contoh !== []) {
            $this->line('Contoh yang akan dihapus:');

            $this->table(
                ['ID', 'Episode', 'Object key', 'Ukuran'],
                array_map(static fn (EpisodeVideo $video): array => [
                    $video->id,
                    $video->episode_id,
                    strlen((string) $video->object_key) > 58
                        ? '...'.substr((string) $video->object_key, -55)
                        : $video->object_key,
                    Bytes::forHumans((int) $video->size),
                ], $contoh)
            );

            if (count($aman) > count($contoh)) {
                $this->line('  ... dan '.number_format(count($aman) - count($contoh)).' lainnya.');
            }
        }
    }

    private function konfirmasi(string $slug, int $jumlah): bool
    {
        if (! $this->input->isInteractive()) {
            $this->error(
                'Penghapusan menuntut konfirmasi langsung, jadi perintah ini '
                .'tidak bisa dijalankan tanpa terminal (cron, --no-interaction).'
            );

            return false;
        }

        $this->line('');
        $this->warn('Ini tidak bisa dibatalkan.');
        $this->line(
            'Sesudah objeknya hilang, sinkronisasi ulang dari bucket mustahil '
            .'selamanya. Satu-satunya salinan video yang tersisa ada di channel '
            .'penyimpanan Telegram.'
        );
        $this->line('');

        $jawab = $this->ask(
            "Ketik slug provider ({$slug}) untuk menghapus {$jumlah} objek"
        );

        return is_string($jawab) && trim($jawab) === $slug;
    }

    /**
     * @param  array<int, EpisodeVideo>  $aman
     */
    private function hapus(
        StorageEngineInterface $engine,
        $provider,
        array $aman
    ): int {
        $terhapus = 0;
        $sudahTidakAda = 0;
        $gagal = 0;
        $dibebaskan = 0;

        $catatan = [];

        $bar = $this->output->createProgressBar(count($aman));
        $bar->start();

        foreach ($aman as $video) {
            $kunci = (string) $video->object_key;

            try {
                // delete() bersifat idempoten: false berarti objeknya
                // memang sudah tidak ada, dan itu bukan kegagalan.
                //
                // Perhatikan bahwa TIDAK ADA $video->delete() di sini, dan
                // tidak boleh pernah ada. Baris database memegang
                // telegram_file_id, satu-satunya cara video ini sampai ke
                // penonton.
                if ($engine->delete($provider->id, $kunci)) {
                    $terhapus++;
                    $dibebaskan += (int) $video->size;
                    $catatan[] = "HAPUS  {$video->id}  {$kunci}";
                } else {
                    $sudahTidakAda++;
                    $catatan[] = "LEWAT  {$video->id}  {$kunci}  (sudah tidak ada)";
                }
            } catch (Throwable $galat) {
                $gagal++;
                $catatan[] = "GAGAL  {$video->id}  {$kunci}  ({$galat->getMessage()})";
            }

            $bar->advance();
        }

        $bar->finish();

        $this->line('');
        $this->line('');

        $this->table(
            ['Hasil', 'Jumlah'],
            [
                ['Objek dihapus', number_format($terhapus)],
                ['Sudah tidak ada sebelumnya', number_format($sudahTidakAda)],
                ['Gagal', number_format($gagal)],
                ['Ruang dibebaskan', Bytes::forHumans($dibebaskan)],
            ]
        );

        $berkas = $this->tulisLaporan($provider->slug, $catatan);

        if ($berkas !== null) {
            $this->line("Catatan lengkap: {$berkas}");
        }

        $this->line('');
        $this->info('Baris database tidak disentuh sama sekali — telegram_file_id utuh.');
        $this->line("Periksa hasilnya: php artisan storage:usage {$provider->slug}");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $catatan
     */
    private function tulisLaporan(string $slug, array $catatan): ?string
    {
        if ($catatan === []) {
            return null;
        }

        $berkas = $this->option('laporan');

        if (! is_string($berkas) || trim($berkas) === '') {
            $berkas = storage_path(
                'logs/pangkas-video-'.$slug.'-'.now()->format('Ymd-His').'.txt'
            );
        }

        try {
            $direktori = dirname($berkas);

            if (! is_dir($direktori)) {
                mkdir($direktori, 0775, true);
            }

            file_put_contents($berkas, implode(PHP_EOL, $catatan).PHP_EOL);

            return $berkas;
        } catch (Throwable $galat) {
            $this->warn("Laporan gagal ditulis: {$galat->getMessage()}");

            return null;
        }
    }
}
