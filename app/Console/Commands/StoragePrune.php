<?php

namespace App\Console\Commands;

use App\Models\Drama;
use App\Models\EpisodeVideo;
use App\Models\StorageProvider;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Support\Bytes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Menghapus SEBAGIAN berkas video dari satu storage provider.
 *
 * ## Bedanya dengan menghapus lewat panel
 *
 * `FileManagerService::delete()` memanggil `$file->delete()`, dan
 * `EpisodeVideo` tidak memakai soft delete — barisnya hilang permanen
 * beserta `telegram_file_id`-nya. Videonya masih ada di server Telegram,
 * tetapi tidak ada lagi yang tahu id-nya, jadi praktis hilang selamanya.
 *
 * Perintah ini TIDAK PERNAH menyentuh database. Yang dihapus hanya objek di
 * bucket. Baris, `telegram_file_id`, dan riwayatnya tetap utuh, sehingga bot
 * tetap bisa memutar videonya sesudahnya.
 *
 * Akibat yang disengaja: setelah ini, catatan database akan lebih besar
 * daripada isi bucket. Itu bukan kerusakan — `storage:usage` menampilkan
 * selisihnya dan menjelaskan arahnya.
 *
 * ## Dua penjagaan yang tidak bisa dimatikan tanpa disadari
 *
 * 1. **Harus ada penyaring.** Menjalankan perintah ini tanpa satu pun opsi
 *    pemilih akan berhenti dengan galat, bukan menghapus seluruh isi
 *    provider. Untuk itu ada `--semua`, yang harus diketik sendiri.
 * 2. **Video tanpa `telegram_file_id` dilewati.** Untuk video seperti itu,
 *    berkas di bucket adalah satu-satunya salinan yang ada di dunia.
 *
 * ## Pemakaian
 *
 * ```
 * php artisan storage:prune kilat --drama=cinta-sialan          # simulasi
 * php artisan storage:prune kilat --drama=cinta-sialan --hapus  # sungguhan
 * php artisan storage:prune kilat --episode=12 --episode=13
 * php artisan storage:prune kilat --video=301 --video=302
 * php artisan storage:prune kilat --pola='2026/06/*'
 * php artisan storage:prune kilat --sebelum=2026-07-01
 * php artisan storage:prune kilat --semua
 * ```
 *
 * Tanpa `--hapus`, tidak ada satu berkas pun yang disentuh. Itu bawaannya,
 * dan memang seharusnya begitu untuk perintah yang tidak bisa dibatalkan.
 */
class StoragePrune extends Command
{
    protected $signature = 'storage:prune
                            {provider : Slug atau id provider, misalnya kilat}
                            {--drama=* : Slug atau id drama}
                            {--episode=* : Id episode}
                            {--video=* : Id baris episode_videos}
                            {--pola= : Cocokkan object_key, boleh memakai *}
                            {--sebelum= : Hanya yang diunggah sebelum tanggal ini (YYYY-MM-DD)}
                            {--semua : Seluruh video di provider ini}
                            {--termasuk-belum-sinkron : Ikutkan video tanpa telegram_file_id}
                            {--hapus : Benar-benar hapus. Tanpa ini hanya simulasi}';

    protected $description = 'Hapus sebagian berkas video dari satu storage provider, tanpa menyentuh database';

    /** Berapa baris contoh yang ditampilkan saat simulasi. */
    private const CONTOH = 20;

    public function __construct(
        protected StorageEngineInterface $engine
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = $this->provider();

        if ($provider === null) {
            return self::FAILURE;
        }

        if (! $this->adaPenyaring()) {

            $this->components->error('Sebutkan apa yang mau dihapus.');

            $this->newLine();
            $this->components->bulletList([
                '--drama=slug-drama        satu atau beberapa drama',
                '--episode=12              satu atau beberapa episode',
                '--video=301               baris episode_videos tertentu',
                "--pola='2026/06/*'        cocokkan object_key",
                '--sebelum=2026-07-01      yang diunggah sebelum tanggal itu',
                '--semua                   seluruh video di provider ini',
            ]);

            $this->newLine();
            $this->line(
                '  <fg=gray>Tanpa penyaring, perintah ini akan mengosongkan seluruh provider. '
                .'Itu terlalu besar untuk terjadi karena satu opsi yang lupa diketik.</>'
            );

            return self::FAILURE;
        }

        $query = $this->query($provider);

        if ($query === null) {
            return self::FAILURE;
        }

        /*
        |----------------------------------------------------------------------
        | Hitung dulu, baru putuskan
        |----------------------------------------------------------------------
        |
        | Dua angka dihitung terpisah: yang akan dihapus, dan yang dilewati
        | karena belum punya file_id. Yang kedua tidak boleh cuma hilang
        | diam-diam dari hitungan — kalau angkanya besar, itu justru pertanda
        | sinkronisasi belum selesai dan penghapusan ini kepagian.
        */
        $jumlah = (clone $query)->count();

        $bytes = (int) (clone $query)->sum('size');

        /*
        | Yang dilewati dihitung dari penyaring yang SAMA, hanya tanpa
        | penjagaan file_id — bukan dari seluruh isi provider. Bedanya
        | penting: "3 dilewati" pada drama yang sedang dipilih adalah
        | keterangan; "3 dilewati" yang ternyata milik drama lain cuma
        | membingungkan.
        */
        $dilewati = 0;

        if (! $this->option('termasuk-belum-sinkron')) {

            $tanpaJaga = $this->query($provider, jaga: false);

            $dilewati = $tanpaJaga === null
                ? 0
                : max(0, $tanpaJaga->count() - $jumlah);
        }

        $this->ringkasan($provider, $jumlah, $bytes, $dilewati);

        if ($jumlah === 0) {
            $this->components->warn('Tidak ada yang cocok dengan penyaring itu.');

            return self::SUCCESS;
        }

        if (! $this->option('hapus')) {

            $this->contoh($query, $jumlah);

            $this->newLine();
            $this->components->info('SIMULASI — belum ada satu berkas pun yang dihapus.');
            $this->line('  <fg=gray>Tambahkan --hapus kalau angkanya sudah cocok.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $ya = $this->confirm(
            'Hapus '.number_format($jumlah, 0, ',', '.').' objek ('
                .Bytes::forHumans($bytes).') dari bucket '.$provider->slug.'?',
            false
        );

        if (! $ya) {
            $this->components->warn('Dibatalkan. Tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        return $this->jalankan($provider, $query, $jumlah);
    }

    /*
    |--------------------------------------------------------------------------
    | Pemilihan
    |--------------------------------------------------------------------------
    */

    private function provider(): ?StorageProvider
    {
        $kunci = (string) $this->argument('provider');

        $provider = StorageProvider::query()
            ->where('slug', $kunci)
            ->when(ctype_digit($kunci), fn ($q) => $q->orWhere('id', (int) $kunci))
            ->first();

        if ($provider === null) {
            $this->components->error('Provider "'.$kunci.'" tidak ditemukan.');

            $this->line(
                '        <fg=gray>Lihat daftarnya dengan: php artisan storage:usage --db</>'
            );
        }

        return $provider;
    }

    private function adaPenyaring(): bool
    {
        return $this->option('semua')
            || $this->option('drama') !== []
            || $this->option('episode') !== []
            || $this->option('video') !== []
            || filled($this->option('pola'))
            || filled($this->option('sebelum'));
    }

    /**
     * Baris dasar: seluruh video milik provider ini, tanpa penyaring pemilih.
     */
    private function dasar(StorageProvider $provider): Builder
    {
        return EpisodeVideo::query()
            ->where('storage_provider_id', $provider->getKey());
    }

    /**
     * Query yang sudah menerapkan seluruh penyaring, atau null bila salah satu
     * penyaringnya tidak bisa dipahami.
     */
    private function query(StorageProvider $provider, bool $jaga = true): ?Builder
    {
        $query = $this->dasar($provider);

        /*
        | Penjagaan utama, dan sengaja diterapkan lebih dulu dari yang lain.
        |
        | Video tanpa file_id belum punya salinan di Telegram. Menghapus
        | berkasnya berarti menghapus video itu sendiri — bukan sekadar
        | membebaskan ruang.
        |
        | `$jaga` hanya dimatikan untuk MENGHITUNG berapa yang terlewat;
        | jalur yang benar-benar menghapus selalu memanggilnya dengan
        | penjagaan menyala kecuali operator mengetik opsinya sendiri.
        */
        if ($jaga && ! $this->option('termasuk-belum-sinkron')) {
            $query->whereNotNull('telegram_file_id');
        }

        if ($ids = $this->option('video')) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        if ($ids = $this->option('episode')) {
            $query->whereIn('episode_id', array_map('intval', $ids));
        }

        if ($drama = $this->option('drama')) {

            $dramaIds = $this->dramaIds($drama);

            if ($dramaIds === null) {
                return null;
            }

            $query->whereHas('episode', fn ($q) => $q->whereIn('drama_id', $dramaIds));
        }

        if (filled($pola = $this->option('pola'))) {
            $query->where('object_key', 'like', $this->like((string) $pola));
        }

        if (filled($tanggal = $this->option('sebelum'))) {

            try {
                $batas = Carbon::parse((string) $tanggal)->startOfDay();

            } catch (Throwable $e) {

                $this->components->error(
                    'Tanggal "'.$tanggal.'" tidak dikenali. Pakai bentuk YYYY-MM-DD.'
                );

                return null;
            }

            $query->where('uploaded_at', '<', $batas);
        }

        return $query;
    }

    /**
     * Ubah daftar slug/id drama jadi daftar id.
     *
     * Slug yang tidak ditemukan menghentikan perintah, bukan diabaikan. Salah
     * ketik satu slug pada perintah yang menghapus berarti yang terhapus
     * bukan yang dimaksud — dan diam-diam melewatinya membuat hasilnya
     * terlihat wajar.
     *
     * @param  array<int, string>  $keys
     * @return array<int, int>|null
     */
    private function dramaIds(array $keys): ?array
    {
        $ids = [];

        foreach ($keys as $kunci) {

            $drama = Drama::query()
                ->where('slug', $kunci)
                ->when(ctype_digit((string) $kunci), fn ($q) => $q->orWhere('id', (int) $kunci))
                ->first();

            if ($drama === null) {

                $this->components->error('Drama "'.$kunci.'" tidak ditemukan.');

                return null;
            }

            $ids[] = (int) $drama->getKey();
        }

        return $ids;
    }

    /**
     * Ubah pola bergaya glob jadi pola LIKE.
     *
     * Karakter khusus LIKE di dalam masukan pengguna di-escape lebih dulu.
     * Tanpa itu, `--pola='video_2026'` akan cocok dengan `video-2026` juga,
     * karena `_` berarti "satu karakter apa saja" — dan pada perintah yang
     * menghapus, cocok terlalu banyak adalah kegagalan yang mahal.
     */
    private function like(string $pola): string
    {
        $aman = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $pola
        );

        // Tanpa bintang, artinya "mengandung" — itu yang diharapkan orang
        // saat mengetik sepotong nama folder.
        if (! str_contains($pola, '*')) {
            return '%'.$aman.'%';
        }

        return str_replace('*', '%', $aman);
    }

    /*
    |--------------------------------------------------------------------------
    | Tampilan
    |--------------------------------------------------------------------------
    */

    private function ringkasan(
        StorageProvider $provider,
        int $jumlah,
        int $bytes,
        int $dilewati
    ): void {
        $this->newLine();

        $this->components->twoColumnDetail(
            '<options=bold>'.$provider->name.'</> <fg=gray>('.$provider->slug.')</>',
            (string) ($provider->bucket ?: '-')
        );

        $this->components->twoColumnDetail(
            '<options=bold>Akan dihapus</>',
            '<options=bold>'.number_format($jumlah, 0, ',', '.').' objek · '
                .Bytes::forHumans($bytes).'</>'
        );

        if ($dilewati > 0) {

            $this->components->twoColumnDetail(
                '<fg=yellow>Dilewati (belum ada file_id)</>',
                '<fg=yellow>'.number_format($dilewati, 0, ',', '.').' objek</>'
            );

            $this->line(
                '        <fg=gray>Untuk video ini, berkas di bucket adalah satu-satunya '
                .'salinan. Selesaikan sinkronisasi ke Telegram dulu.</>'
            );
        }

        $this->components->twoColumnDetail(
            'Database',
            '<fg=green>tidak disentuh</> <fg=gray>— baris dan telegram_file_id tetap utuh</>'
        );
    }

    /**
     * Beberapa baris contoh, supaya penyaringnya bisa diperiksa mata sendiri.
     *
     * Angka saja tidak cukup: "42 objek" terlihat sama meyakinkannya baik
     * ketika yang terpilih benar maupun ketika penyaringnya meleset ke drama
     * sebelah.
     */
    private function contoh(Builder $query, int $jumlah): void
    {
        $baris = (clone $query)
            ->with('episode.drama')
            ->orderBy('id')
            ->limit(self::CONTOH)
            ->get();

        if ($baris->isEmpty()) {
            return;
        }

        $this->newLine();

        $this->table(
            ['#', 'Drama', 'Eps', 'Ukuran', 'Object key'],
            $baris->map(fn (EpisodeVideo $v) => [
                $v->id,
                mb_substr((string) ($v->episode?->drama?->title ?? '-'), 0, 22),
                $v->episode?->episode_number ?? '-',
                Bytes::forHumans((int) $v->size),
                mb_substr((string) $v->object_key, 0, 46),
            ])->all()
        );

        if ($jumlah > self::CONTOH) {
            $this->line(
                '  <fg=gray>… dan '.number_format($jumlah - self::CONTOH, 0, ',', '.')
                .' baris lain yang tidak ditampilkan.</>'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Penghapusan
    |--------------------------------------------------------------------------
    */

    private function jalankan(StorageProvider $provider, Builder $query, int $jumlah): int
    {
        $providerId = (int) $provider->getKey();

        $ok = 0;
        $hilang = 0;
        $gagal = 0;

        $bar = $this->output->createProgressBar($jumlah);

        $bar->start();

        /*
        | chunkById, bukan get(). Baris tidak diubah oleh perulangan ini, jadi
        | halamannya stabil — dan seluruh daftar tidak perlu muat di memori
        | sekaligus, yang pernah jadi masalah nyata pada tabel sebesar ini.
        */
        $query->chunkById(100, function ($batch) use (
            $providerId, &$ok, &$hilang, &$gagal, $bar
        ) {
            foreach ($batch as $video) {

                try {
                    $this->engine->delete($providerId, (string) $video->object_key)
                        ? $ok++
                        : $hilang++;

                } catch (Throwable $e) {

                    $gagal++;

                    // Dicatat apa adanya, tetapi tidak menghentikan sisanya:
                    // satu objek yang izinnya bermasalah bukan alasan
                    // meninggalkan seratus objek lain setengah jalan.
                    $this->newLine();
                    $this->components->warn(
                        '#'.$video->id.' gagal: '.mb_substr($e->getMessage(), 0, 90)
                    );
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);

        $this->components->twoColumnDetail('Dihapus', (string) $ok);
        $this->components->twoColumnDetail(
            'Sudah tidak ada',
            $hilang.' <fg=gray>(bukan kegagalan — objeknya memang tidak ada lagi)</>'
        );
        $this->components->twoColumnDetail(
            'Gagal',
            $gagal > 0 ? '<fg=red>'.$gagal.'</>' : '0'
        );

        $this->newLine();
        $this->line('  <fg=gray>Periksa hasilnya: php artisan storage:usage '.$provider->slug.'</>');
        $this->line('  <fg=gray>Lalu buka bot dan putar salah satu video yang berkasnya baru dihapus.</>');
        $this->newLine();

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
