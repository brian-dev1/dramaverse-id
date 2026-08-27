<?php

namespace App\Console\Commands;

use App\Models\DramaAsset;
use App\Models\EpisodeVideo;
use App\Models\StorageProvider;
use App\Models\VideoInbox;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Contracts\StorageManagerInterface;
use App\Support\Bytes;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * Daftar isi bucket bernomor, lalu hapus dengan menyebut nomornya.
 *
 * ```
 * php artisan storage:objects kilat                  # lihat daftarnya
 * php artisan storage:objects kilat --hapus=1-50     # hapus nomor 1 sampai 50
 * php artisan storage:objects kilat --hapus=1,3,7-9
 * ```
 *
 * ## Yang dihapus hanya objek di bucket
 *
 * Baris `episode_videos` tidak disentuh sama sekali, termasuk
 * `telegram_file_id`-nya. Bot memutar video dari salinan milik Telegram, bukan
 * dari bucket — jadi video tetap bisa dibuka sesudah berkasnya hilang dari
 * sini. Itu justru tujuan perintah ini.
 *
 * ## Kenapa nomornya disimpan, bukan dihitung ulang
 *
 * Perintah ini dijalankan dua kali: sekali untuk melihat, sekali untuk
 * menghapus. Kalau nomor dihitung ulang pada jalan kedua, satu berkas yang
 * masuk di antara keduanya akan menggeser SELURUH nomor sesudahnya — dan
 * "hapus 1-50" diam-diam menghapus 50 berkas yang berbeda dari yang barusan
 * dilihat. Tidak ada galat yang muncul; hasilnya tetap terlihat wajar.
 *
 * Karena itu daftar yang ditampilkan disimpan apa adanya ke
 * `storage/app/private/daftar-objek/{slug}.json`, dan `--hapus` membaca dari
 * sana. Nomor selalu menunjuk baris yang benar-benar Anda lihat.
 *
 * Setelah penghapusan berhasil, berkas daftarnya dibuang. Nomor lama tidak
 * berlaku lagi untuk isi bucket yang sudah berubah, dan membiarkannya
 * tergeletak adalah mengundang pemakaian ulang yang salah.
 */
class StorageObjects extends Command
{
    protected $signature = 'storage:objects
                            {provider : Slug atau id provider, misalnya kilat}
                            {--cari= : Hanya tampilkan yang namanya mengandung ini}
                            {--hapus= : Nomor yang dihapus, misalnya 1-50 atau 1,3,7-9}
                            {--paksa : Ikutkan yang belum sinkron dan aset web}';

    protected $description = 'Tampilkan isi bucket bernomor, lalu hapus berdasarkan nomornya';

    private const DISK = 'local';

    private const FOLDER = 'daftar-objek';

    /** Berapa baris yang ditampilkan sebagai contoh sebelum menghapus. */
    private const CONTOH = 25;

    public function __construct(
        protected StorageManagerInterface $manager,
        protected StorageEngineInterface $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = $this->provider();

        if ($provider === null) {
            return self::FAILURE;
        }

        return filled($this->option('hapus'))
            ? $this->hapus($provider)
            : $this->daftar($provider);
    }

    /*
    |--------------------------------------------------------------------------
    | Menampilkan daftar
    |--------------------------------------------------------------------------
    */

    private function daftar(StorageProvider $provider): int
    {
        $objek = $this->pindai($provider);

        if ($objek === null) {
            return self::FAILURE;
        }

        $cari = trim((string) $this->option('cari'));

        if ($cari !== '') {
            $objek = array_values(array_filter(
                $objek,
                fn (array $o) => mb_stripos($o['key'], $cari) !== false
            ));
        }

        if ($objek === []) {
            $this->components->warn(
                $cari === ''
                    ? 'Bucket ini kosong.'
                    : 'Tidak ada objek yang namanya mengandung "'.$cari.'".'
            );

            return self::SUCCESS;
        }

        $objek = $this->tandai($provider, $objek);

        $this->simpan($provider, $objek, $cari);

        $baris = [];
        $total = 0;

        foreach ($objek as $o) {

            $total += $o['bytes'];

            $baris[] = [
                $o['no'],
                $this->lencana($o['status']),
                Bytes::forHumans($o['bytes']),
                $o['key'],
            ];
        }

        $this->newLine();

        $this->table(['No', 'Keadaan', 'Ukuran', 'Object key'], $baris);

        $this->components->twoColumnDetail(
            '<options=bold>'.$provider->name.'</> <fg=gray>('.$provider->slug.')</>',
            '<options=bold>'.number_format(count($objek), 0, ',', '.').' objek · '
                .Bytes::forHumans($total).'</>'
        );

        $this->keterangan();

        $this->newLine();
        $this->components->info('Untuk menghapus, sebutkan nomornya:');
        $this->line('  php artisan storage:objects '.$provider->slug.' --hapus=1-50');
        $this->line('  php artisan storage:objects '.$provider->slug.' --hapus=1,3,7-9');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Telusuri isi bucket, urut nama.
     *
     * Urutannya WAJIB sama tiap kali dijalankan — nomor yang tidak stabil
     * lebih berbahaya daripada tidak ada nomor sama sekali. `strnatcasecmp`
     * dipakai supaya `part-2` datang sebelum `part-10`, bukan sesudahnya
     * seperti pada urutan abjad biasa.
     *
     * @return array<int, array{key: string, bytes: int}>|null
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

        $objek = [];

        try {
            foreach ($disk->getDriver()->listContents('', true) as $item) {

                /** @var StorageAttributes $item */
                if (! $item->isFile()) {
                    continue;
                }

                $objek[] = [
                    'key'   => $item->path(),
                    'bytes' => (int) ($item->fileSize() ?? 0),
                ];
            }

        } catch (Throwable $e) {

            $this->components->error('Gagal membaca isi bucket: '.$e->getMessage());

            return null;
        }

        usort($objek, fn (array $a, array $b) => strnatcasecmp($a['key'], $b['key']));

        return $objek;
    }

    /**
     * Tempelkan nomor dan keadaan tiap objek.
     *
     * Keadaan inilah yang membedakan "aman dihapus" dari "jangan disentuh",
     * dan menampilkannya di daftar jauh lebih berguna daripada menolaknya
     * belakangan saat penghapusan.
     *
     * @param  array<int, array{key: string, bytes: int}>  $objek
     * @return array<int, array{no: int, key: string, bytes: int, status: string}>
     */
    private function tandai(StorageProvider $provider, array $objek): array
    {
        $id = (int) $provider->getKey();

        $video = EpisodeVideo::query()
            ->where('storage_provider_id', $id)
            ->pluck('telegram_file_id', 'object_key')
            ->all();

        $aset = DramaAsset::query()
            ->where('storage_provider_id', $id)
            ->pluck('asset_type', 'object_key')
            ->all();

        /*
        | Video Inbox adalah tabel ketiga yang menunjuk objek di bucket, dan
        | paling mudah terlupakan karena isinya berkas yang BELUM jadi apa-apa
        | — belum ditempelkan ke episode mana pun.
        |
        | Tanpa peta ini, berkas antrean tampil sebagai "tak tercatat" alias
        | sisa sampah, lalu terhapus. Barisnya tetap ada di panel dengan label
        | TERSEDIA, mengarah ke berkas yang tidak ada lagi, dan baru ketahuan
        | ketika seseorang mencoba memasangkannya ke episode.
        */
        $antrean = VideoInbox::query()
            ->where('storage_provider_id', $id)
            ->pluck('status', 'object_key')
            ->all();

        $hasil = [];

        foreach ($objek as $i => $o) {

            $key = $o['key'];

            $status = match (true) {
                array_key_exists($key, $video)   => blank($video[$key]) ? 'belum' : 'aman',
                array_key_exists($key, $aset)    => 'aset',
                array_key_exists($key, $antrean) => 'antrean',
                default                          => 'lepas',
            };

            $hasil[] = [
                'no'     => $i + 1,
                'key'    => $key,
                'bytes'  => $o['bytes'],
                'status' => $status,
            ];
        }

        return $hasil;
    }

    private function lencana(string $status): string
    {
        return match ($status) {
            'aman'    => '<fg=green>aman</>',
            'belum'   => '<fg=red>belum sinkron</>',
            'aset'    => '<fg=yellow>aset web</>',
            'antrean' => '<fg=yellow>antrean inbox</>',
            default   => '<fg=gray>tak tercatat</>',
        };
    }

    private function keterangan(): void
    {
        $this->newLine();

        $this->line('  <fg=green>aman</>          <fg=gray>video sudah punya telegram_file_id — tetap bisa diputar di bot</>');
        $this->line('  <fg=red>belum sinkron</> <fg=gray>berkas di bucket satu-satunya salinan. JANGAN dihapus</>');
        $this->line('  <fg=yellow>aset web</>      <fg=gray>poster/cover yang dipakai website. Menghapusnya membuat gambar hilang</>');
        $this->line('  <fg=yellow>antrean inbox</> <fg=gray>menunggu dipasang ke episode di /admin/video-inbox</>');
        $this->line('  <fg=gray>tak tercatat   tidak ada di database — sisa unggahan lama atau berkas uji</>');
    }

    /*
    |--------------------------------------------------------------------------
    | Menghapus berdasarkan nomor
    |--------------------------------------------------------------------------
    */

    private function hapus(StorageProvider $provider): int
    {
        $simpanan = $this->baca($provider);

        if ($simpanan === null) {
            return self::FAILURE;
        }

        $nomor = $this->uraiNomor((string) $this->option('hapus'), count($simpanan));

        if ($nomor === null) {
            return self::FAILURE;
        }

        $terpilih = [];

        foreach ($simpanan as $o) {
            if (in_array($o['no'], $nomor, true)) {
                $terpilih[] = $o;
            }
        }

        /*
        | Yang ditolak dipisahkan, bukan dibuang diam-diam. Orang yang
        | mengetik 1-50 berhak tahu kalau yang benar-benar dihapus cuma 47,
        | dan kenapa tiga sisanya tidak.
        */
        $boleh = [];
        $tolak = [];

        foreach ($terpilih as $o) {

            $terlarang = in_array($o['status'], ['belum', 'aset', 'antrean'], true);

            if ($terlarang && ! $this->option('paksa')) {
                $tolak[] = $o;

                continue;
            }

            $boleh[] = $o;
        }

        $this->tampilkanPilihan($provider, $boleh, $tolak);

        if ($boleh === []) {
            $this->components->warn('Tidak ada yang bisa dihapus dari pilihan itu.');

            return self::SUCCESS;
        }

        $bytes = array_sum(array_column($boleh, 'bytes'));

        $ya = $this->confirm(
            'Hapus '.count($boleh).' objek ('.Bytes::forHumans($bytes).') dari bucket '
                .$provider->slug.'?',
            false
        );

        if (! $ya) {
            $this->components->warn('Dibatalkan. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        return $this->jalankan($provider, $boleh);
    }

    /**
     * Ubah "1-50" atau "1,3,7-9" jadi daftar nomor.
     *
     * Nomor di luar jangkauan daftar dianggap galat, bukan diabaikan. Mengetik
     * `1-500` pada daftar berisi 80 objek hampir selalu berarti orangnya
     * mengira daftarnya lebih panjang — dan melanjutkan tanpa berkata apa-apa
     * membuat salah paham itu tidak pernah terkoreksi.
     *
     * @return array<int, int>|null
     */
    private function uraiNomor(string $masukan, int $maksimum): ?array
    {
        $hasil = [];

        foreach (explode(',', $masukan) as $potong) {

            $potong = trim($potong);

            if ($potong === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $potong, $cocok)) {

                $awal = (int) $cocok[1];
                $akhir = (int) $cocok[2];

                if ($awal > $akhir) {
                    [$awal, $akhir] = [$akhir, $awal];
                }

                foreach (range($awal, $akhir) as $n) {
                    $hasil[] = $n;
                }

                continue;
            }

            if (! ctype_digit($potong)) {

                $this->components->error('"'.$potong.'" bukan nomor atau jangkauan yang sah.');

                $this->line('        <fg=gray>Bentuk yang diterima: 5, atau 1-50, atau 1,3,7-9</>');

                return null;
            }

            $hasil[] = (int) $potong;
        }

        $hasil = array_values(array_unique($hasil));

        sort($hasil);

        if ($hasil === []) {
            $this->components->error('Tidak ada nomor yang disebutkan.');

            return null;
        }

        $luar = array_filter($hasil, fn (int $n) => $n < 1 || $n > $maksimum);

        if ($luar !== []) {

            $this->components->error(
                'Nomor di luar daftar: '.implode(', ', $luar)
                .'. Daftarnya cuma sampai '.$maksimum.'.'
            );

            return null;
        }

        return $hasil;
    }

    /**
     * @param  array<int, array{no: int, key: string, bytes: int, status: string}>  $boleh
     * @param  array<int, array{no: int, key: string, bytes: int, status: string}>  $tolak
     */
    private function tampilkanPilihan(StorageProvider $provider, array $boleh, array $tolak): void
    {
        $this->newLine();

        $this->components->twoColumnDetail(
            '<options=bold>'.$provider->name.'</> <fg=gray>('.$provider->slug.')</>',
            '<options=bold>'.count($boleh).' akan dihapus · '
                .Bytes::forHumans((int) array_sum(array_column($boleh, 'bytes'))).'</>'
        );

        $this->components->twoColumnDetail(
            'Database',
            '<fg=green>tidak disentuh</> <fg=gray>— telegram_file_id tetap utuh</>'
        );

        if ($tolak !== []) {

            $this->newLine();

            $this->components->warn(
                count($tolak).' objek dilewati karena tidak aman dihapus:'
            );

            $this->table(
                ['No', 'Alasan', 'Object key'],
                array_map(fn (array $o) => [
                    $o['no'],
                    match ($o['status']) {
                        'belum'   => 'belum punya file_id',
                        'aset'    => 'aset web (poster/cover)',
                        'antrean' => 'masih di antrean Video Inbox',
                        default   => $o['status'],
                    },
                    $o['key'],
                ], array_slice($tolak, 0, self::CONTOH))
            );

            $this->line(
                '  <fg=gray>Kalau memang disengaja, ulangi dengan --paksa. '
                .'Untuk yang belum sinkron, itu berarti videonya hilang selamanya.</>'
            );

            $this->line(
                '  <fg=gray>Untuk yang masih di antrean, hapus juga barisnya — kalau tidak, '
                .'panel tetap menampilkannya sebagai TERSEDIA padahal berkasnya sudah tidak ada:</>'
            );

            $this->line(
                '  <fg=gray>  php artisan tinker --execute=\'App\Models\VideoInbox::where("object_key","KEY")->delete();\'</>'
            );
        }

        if ($boleh === []) {
            return;
        }

        $this->newLine();

        $this->table(
            ['No', 'Ukuran', 'Object key'],
            array_map(fn (array $o) => [
                $o['no'],
                Bytes::forHumans($o['bytes']),
                $o['key'],
            ], array_slice($boleh, 0, self::CONTOH))
        );

        if (count($boleh) > self::CONTOH) {
            $this->line(
                '  <fg=gray>… dan '.(count($boleh) - self::CONTOH)
                .' objek lain yang tidak ditampilkan.</>'
            );
        }
    }

    /**
     * @param  array<int, array{no: int, key: string, bytes: int, status: string}>  $boleh
     */
    private function jalankan(StorageProvider $provider, array $boleh): int
    {
        $id = (int) $provider->getKey();

        $ok = 0;
        $hilang = 0;
        $gagal = 0;
        $bytes = 0;

        $bar = $this->output->createProgressBar(count($boleh));

        $bar->start();

        foreach ($boleh as $o) {

            try {
                if ($this->engine->delete($id, $o['key'])) {
                    $ok++;
                    $bytes += $o['bytes'];
                } else {
                    $hilang++;
                }

            } catch (Throwable $e) {

                $gagal++;

                $this->newLine();
                $this->components->warn(
                    '#'.$o['no'].' gagal: '.mb_substr($e->getMessage(), 0, 90)
                );
            }

            $bar->advance();
        }

        $bar->finish();

        $this->newLine(2);

        $this->components->twoColumnDetail('Dihapus', $ok.' <fg=gray>('.Bytes::forHumans($bytes).')</>');
        $this->components->twoColumnDetail(
            'Sudah tidak ada',
            $hilang.' <fg=gray>(bukan kegagalan)</>'
        );
        $this->components->twoColumnDetail('Gagal', $gagal > 0 ? '<fg=red>'.$gagal.'</>' : '0');

        // Nomor lama sudah tidak menunjuk isi bucket yang sekarang.
        $this->buang($provider);

        $this->newLine();
        $this->line('  <fg=gray>Daftar nomor dibuang. Jalankan lagi tanpa --hapus untuk daftar baru.</>');
        $this->line('  <fg=gray>Lalu buka bot dan putar salah satu video yang berkasnya baru dihapus.</>');
        $this->newLine();

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Simpanan daftar
    |--------------------------------------------------------------------------
    */

    private function berkas(StorageProvider $provider): string
    {
        return self::FOLDER.'/'.$provider->slug.'.json';
    }

    /**
     * @param  array<int, array{no: int, key: string, bytes: int, status: string}>  $objek
     */
    private function simpan(StorageProvider $provider, array $objek, string $cari): void
    {
        try {
            Storage::disk(self::DISK)->put(
                $this->berkas($provider),
                (string) json_encode([
                    'provider' => $provider->slug,
                    'cari'     => $cari,
                    'dibuat'   => now()->toIso8601String(),
                    'objek'    => $objek,
                ], JSON_UNESCAPED_SLASHES)
            );

        } catch (Throwable $e) {

            // Bukan alasan menggagalkan penampilan daftar — yang hilang cuma
            // kemampuan menghapus lewat nomor, dan itu terlihat jelas nanti.
            $this->components->warn(
                'Daftar nomor tidak bisa disimpan: '.$e->getMessage()
            );
        }
    }

    /**
     * @return array<int, array{no: int, key: string, bytes: int, status: string}>|null
     */
    private function baca(StorageProvider $provider): ?array
    {
        $disk = Storage::disk(self::DISK);

        $path = $this->berkas($provider);

        if (! $disk->exists($path)) {

            $this->components->error('Belum ada daftar nomor untuk provider ini.');

            $this->newLine();
            $this->line('  Tampilkan daftarnya dulu:');
            $this->line('  <options=bold>php artisan storage:objects '.$provider->slug.'</>');
            $this->newLine();
            $this->line(
                '  <fg=gray>Nomor harus menunjuk daftar yang benar-benar Anda lihat. '
                .'Menghitungnya ulang di sini berisiko menghapus berkas yang berbeda.</>'
            );
            $this->newLine();

            return null;
        }

        $isi = json_decode((string) $disk->get($path), true);

        $objek = $isi['objek'] ?? null;

        if (! is_array($objek) || $objek === []) {

            $this->components->error('Daftar nomornya rusak. Tampilkan ulang daftarnya.');

            return null;
        }

        $dibuat = (string) ($isi['dibuat'] ?? '');

        if ($dibuat !== '') {
            $this->line('  <fg=gray>Memakai daftar yang dibuat '.$dibuat.'</>');
        }

        if (filled($isi['cari'] ?? null)) {
            $this->line('  <fg=gray>Daftar itu tersaring oleh --cari='.$isi['cari'].'</>');
        }

        return $objek;
    }

    private function buang(StorageProvider $provider): void
    {
        try {
            Storage::disk(self::DISK)->delete($this->berkas($provider));

        } catch (Throwable $e) {
            // Tidak penting: daftar basi paling buruk cuma membuat perintah
            // berikutnya menolak nomor yang sudah tidak ada.
        }
    }

    private function provider(): ?StorageProvider
    {
        $kunci = (string) $this->argument('provider');

        $provider = StorageProvider::query()
            ->where('slug', $kunci)
            ->when(ctype_digit($kunci), fn ($q) => $q->orWhere('id', (int) $kunci))
            ->first();

        if ($provider === null) {
            $this->components->error('Provider "'.$kunci.'" tidak ditemukan.');

            $this->line('        <fg=gray>Lihat daftarnya: php artisan storage:usage --db</>');
        }

        return $provider;
    }
}
