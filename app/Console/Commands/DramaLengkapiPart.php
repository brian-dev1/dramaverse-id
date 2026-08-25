<?php

namespace App\Console\Commands;

use App\Models\Drama;
use App\Models\Episode;
use App\Models\VideoInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lengkapi part drama yang sudah terlanjur tersimpan tanpa part.
 *
 * ## Masalahnya
 *
 * Pembuatan part otomatis di form drama hanya berjalan saat drama
 * DISIMPAN. Drama yang dibuat sebelum aturan itu ada tidak akan pernah
 * terpicu, dan membuka ratusan drama satu per satu hanya untuk menekan
 * Simpan bukan pekerjaan yang pantas dilakukan manusia.
 *
 * Yang lebih menyulitkan: `dramas.total_episode` tidak bisa dijadikan
 * acuan tunggal. `EpisodeController::syncEpisodeCount()` menimpanya
 * dengan JUMLAH PART YANG BENAR-BENAR ADA setiap kali episode berubah,
 * jadi drama tanpa part selalu berakhir di angka 0. Niat asli admin
 * ("drama ini 5 part") tidak tersimpan di mana pun.
 *
 * ## Dari mana angkanya diambil
 *
 * Dari `video_inboxes.original_filename`. Video yang sudah Anda unduh
 * dari Telegram membawa namanya sendiri:
 *
 *     Amarah Lalu Kubalas Sekarang part 5.mp4
 *     Tangkap Peluang Raih Kekayaan part 4 end.mp4
 *
 * Nama itu memuat DUA keterangan sekaligus: drama mana, dan part
 * keberapa. Nomor tertinggi yang ditemukan untuk sebuah drama adalah
 * jumlah part yang seharusnya ada. Ini bukan tebakan liar — inbox
 * memang catatan berkas apa saja yang sudah masuk untuk drama itu.
 *
 * Kalau `total_episode` kebetulan lebih besar dari yang ditemukan di
 * inbox, yang lebih besar yang dipakai.
 *
 * ## Pencocokan judul
 *
 * Nama berkas dipotong di kata "part"/"episode", sisanya diseragamkan
 * jadi slug, lalu dicocokkan PERSIS dengan slug drama. Tidak ada
 * pencocokan sebagian atau kemiripan: dua drama bisa berjudul mirip,
 * dan salah membuat part di drama yang keliru jauh lebih merepotkan
 * daripada melewatkannya. Yang tidak cocok dilaporkan, bukan ditebak.
 *
 * ```
 * php artisan drama:lengkapi-part                # laporan saja
 * php artisan drama:lengkapi-part --terapkan     # benar-benar buat
 * php artisan drama:lengkapi-part --drama=12
 * ```
 */
class DramaLengkapiPart extends Command
{
    protected $signature = 'drama:lengkapi-part
                            {--drama= : Batasi ke satu drama (id)}
                            {--terapkan : Benar-benar buat partnya}
                            {--jumlah=40 : Berapa baris laporan yang ditampilkan}';

    protected $description = 'Buat part yang belum ada untuk drama lama, jumlahnya diambil dari Video Inbox';

    /** Batas part per drama dalam sekali jalan. */
    private const BATAS = 300;

    public function handle(): int
    {
        $terapkan = (bool) $this->option('terapkan');
        $tampil = max(1, (int) $this->option('jumlah'));

        $dariInbox = $this->partTertinggiPerDrama();

        $query = Drama::query()->withCount('episodes');

        if ($this->option('drama') !== null) {
            $query->whereKey($this->option('drama'));
        }

        $rencana = [];
        $totalPart = 0;

        foreach ($query->orderBy('title')->get() as $drama) {
            $ada = (int) $drama->episodes_count;

            $target = max(
                (int) $drama->total_episode,
                $dariInbox[$drama->slug] ?? 0
            );

            if ($target <= $ada) {
                continue;
            }

            $target = min($target, self::BATAS);

            if ($target <= $ada) {
                continue;
            }

            $rencana[] = [
                'drama'  => $drama,
                'ada'    => $ada,
                'target' => $target,
                'sumber' => ($dariInbox[$drama->slug] ?? 0) >= (int) $drama->total_episode
                    ? 'inbox'
                    : 'total_episode',
            ];

            $totalPart += $target - $ada;
        }

        if ($rencana === []) {
            $this->info('Tidak ada drama yang perlu dilengkapi.');

            $this->laporkanTakCocok($dariInbox);

            return self::SUCCESS;
        }

        $this->line('');
        $this->table(
            ['Drama', 'Part ada', 'Jadi', 'Tambah', 'Sumber angka'],
            array_map(static fn (array $r): array => [
                Str::limit($r['drama']->title, 44),
                $r['ada'],
                $r['target'],
                $r['target'] - $r['ada'],
                $r['sumber'],
            ], array_slice($rencana, 0, $tampil))
        );

        if (count($rencana) > $tampil) {
            $this->line('  ... dan '.(count($rencana) - $tampil).' drama lainnya.');
        }

        $this->line('');
        $this->line(sprintf(
            '%s drama, %s part akan dibuat sebagai draf.',
            number_format(count($rencana)),
            number_format($totalPart)
        ));

        $this->laporkanTakCocok($dariInbox);

        if (! $terapkan) {
            $this->line('');
            $this->info('Ini baru laporan — belum ada satu part pun yang dibuat.');
            $this->line('Tambahkan --terapkan kalau angka di atas sudah sesuai.');

            return self::SUCCESS;
        }

        $dibuat = $this->buat($rencana);

        $this->line('');
        $this->info("{$dibuat} part dibuat.");
        $this->line(
            'Semuanya berstatus draf dan belum punya video. '
            .'Pasangkan videonya lewat Video Inbox, lalu terbitkan.'
        );

        return self::SUCCESS;
    }

    /**
     * Nomor part tertinggi per slug drama, dibaca dari nama berkas inbox.
     *
     * @return array<string, int>
     */
    private function partTertinggiPerDrama(): array
    {
        $peta = [];

        $this->takCocok = [];

        // Slug drama yang sah, untuk mencocokkan secara persis.
        $slugDrama = Drama::query()->pluck('slug')->flip();

        VideoInbox::query()
            ->select(['id', 'original_filename'])
            ->orderBy('id')
            ->chunk(500, function ($batch) use (&$peta, $slugDrama): void {
                foreach ($batch as $baris) {
                    $nama = (string) $baris->original_filename;

                    $slug = $this->slugJudul($nama);
                    $nomor = $this->nomorPart($nama);

                    if ($slug === '' || $nomor === null) {
                        $this->takCocok[] = $nama;

                        continue;
                    }

                    if (! $slugDrama->has($slug)) {
                        $this->takCocok[] = $nama;

                        continue;
                    }

                    $peta[$slug] = max($peta[$slug] ?? 0, $nomor);
                }
            });

        return $peta;
    }

    /** @var array<int, string> */
    private array $takCocok = [];

    private function laporkanTakCocok(array $dariInbox): void
    {
        if ($this->takCocok === []) {
            return;
        }

        $this->line('');
        $this->warn(
            count($this->takCocok).' berkas di Video Inbox tidak bisa '
            .'dicocokkan ke drama mana pun (judulnya tidak sama persis '
            .'dengan slug drama). Berkas ini DIABAIKAN, bukan ditebak:'
        );

        foreach (array_slice($this->takCocok, 0, 10) as $nama) {
            $this->line('  - '.Str::limit($nama, 70));
        }

        if (count($this->takCocok) > 10) {
            $this->line('  ... dan '.(count($this->takCocok) - 10).' lainnya.');
        }
    }

    /**
     * @param  array<int, array{drama: Drama, ada: int, target: int}>  $rencana
     */
    private function buat(array $rencana): int
    {
        $dibuat = 0;

        foreach ($rencana as $item) {
            $drama = $item['drama'];

            $ada = Episode::where('drama_id', $drama->id)
                ->pluck('episode_number')
                ->flip();

            DB::transaction(function () use ($drama, $item, $ada, &$dibuat): void {
                for ($nomor = 1; $nomor <= $item['target']; $nomor++) {
                    if ($ada->has($nomor)) {
                        continue;
                    }

                    // is_vip TIDAK diisi di sini. EpisodeObserver yang
                    // menentukannya dari nomor part, supaya aturannya
                    // hanya ditulis di satu tempat.
                    Episode::create([
                        'drama_id'       => $drama->id,
                        'episode_number' => $nomor,
                        'title'          => 'Part '.$nomor,
                        'slug'           => Str::slug($drama->slug.'-episode-'.$nomor),
                        'status'         => 'draft',
                        'published_at'   => null,
                    ]);

                    $dibuat++;
                }
            });

            $jumlah = Episode::where('drama_id', $drama->id)->count();

            $drama->forceFill(['total_episode' => $jumlah])->saveQuietly();
        }

        return $dibuat;
    }

    /**
     * Slug judul drama dari nama berkas.
     *
     * "Amarah Lalu Kubalas Sekarang part 5.mp4"
     *   -> "amarah-lalu-kubalas-sekarang"
     */
    private function slugJudul(string $nama): string
    {
        $nama = $this->rapikan($nama);

        $potong = preg_split(
            '/\b(?:part|episode|eps|ep)\b/i',
            $nama,
            2
        );

        return Str::slug($potong[0] ?? '');
    }

    /**
     * Nomor part dari nama berkas.
     *
     * Kata kunci yang eksplisit didahulukan. Bila tidak ada, angka
     * TERAKHIR yang dipakai — pada "drama 2024 ep 05" angka terakhirlah
     * nomor partnya, bukan tahunnya. Aturan yang sama dipakai penebak
     * di halaman Video Inbox, supaya keduanya tidak pernah berbeda.
     */
    private function nomorPart(string $nama): ?int
    {
        $nama = $this->rapikan($nama);

        if (preg_match('/\b(?:part|episode|eps|ep|e)\s*(\d{1,4})\b/i', $nama, $cocok) === 1) {
            return (int) $cocok[1];
        }

        if (preg_match_all('/\d{1,4}/', $nama, $semua) > 0) {
            return (int) end($semua[0]);
        }

        return null;
    }

    /**
     * Buang ekstensi, dan ubah pemisah jadi spasi.
     *
     * Titik, garis bawah, dan tanda hubung harus menjadi spasi LEBIH DULU.
     * Tanpa itu `\bpart\b` tidak pernah cocok pada nama seperti
     * "Judul_Drama_part_4_end.mp4", karena garis bawah termasuk karakter
     * kata sehingga tidak ada batas kata di sebelah "part".
     */
    private function rapikan(string $nama): string
    {
        $nama = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $nama) ?? $nama;

        return preg_replace('/[._\-]+/', ' ', $nama) ?? $nama;
    }
}
