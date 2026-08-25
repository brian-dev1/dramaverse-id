<?php

namespace App\Console\Commands;

use App\Models\Drama;
use App\Models\Episode;
use App\Observers\EpisodeObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rapikan `episodes.is_vip` agar mengikuti aturan: part 1 gratis, sisanya VIP.
 *
 * ## Kenapa perintah ini ada
 *
 * `EpisodeObserver` menegakkan aturan itu untuk setiap baris yang dibuat
 * atau diubah SETELAH ia dipasang. Baris yang sudah ada sebelumnya tidak
 * tersentuh — observer hanya berjalan saat ada yang menyimpan.
 *
 * Dan data lama memang tidak seragam: seeder demo dulu menggratiskan part
 * 1 DAN 2, form massal membiarkan admin memilih per rentang, dan sebagian
 * drama ditandai VIP seluruhnya. Tanpa perapian sekali jalan, aturan baru
 * hanya berlaku untuk drama yang ditambahkan mulai hari ini.
 *
 * ## Bawaannya tidak mengubah apa pun
 *
 * Tanpa `--terapkan`, perintah ini hanya melaporkan. Kolom `is_vip`
 * menentukan siapa yang boleh menonton apa, jadi mengubahnya secara massal
 * tanpa melihat angkanya lebih dulu adalah cara cepat menggratiskan
 * seluruh katalog tanpa sengaja.
 *
 * ```
 * php artisan episode:selaraskan-akses                  # laporan saja
 * php artisan episode:selaraskan-akses --terapkan       # benar-benar ubah
 * php artisan episode:selaraskan-akses --drama=12       # satu drama saja
 * ```
 */
class EpisodeSelaraskanAkses extends Command
{
    protected $signature = 'episode:selaraskan-akses
                            {--drama= : Batasi ke satu drama (id)}
                            {--terapkan : Benar-benar simpan perubahannya}
                            {--jumlah=25 : Berapa contoh yang ditampilkan}';

    protected $description = 'Selaraskan is_vip tiap part: part 1 gratis, part 2 dan seterusnya VIP';

    public function handle(): int
    {
        $dramaId = $this->option('drama');
        $terapkan = (bool) $this->option('terapkan');
        $contoh = max(1, (int) $this->option('jumlah'));

        $query = Episode::query()->select([
            'id', 'drama_id', 'episode_number', 'is_vip', 'title',
        ]);

        if ($dramaId !== null) {
            if (! Drama::whereKey($dramaId)->exists()) {
                $this->error("Drama id {$dramaId} tidak ada.");

                return self::FAILURE;
            }

            $query->where('drama_id', $dramaId);
        }

        /*
        | Dua kelompok yang berbeda arah, dan bedanya penting:
        |
        | - "dibuka"  : part 1 yang sekarang VIP -> jadi gratis.
        | - "dikunci" : part 2+ yang sekarang gratis -> jadi VIP.
        |
        | Yang kedua MENGURANGI akses penonton. Kalau ada part yang selama
        | ini bisa ditonton gratis lalu mendadak terkunci, itu keluhan yang
        | datang keesokan harinya — jadi angkanya dipisah supaya terlihat
        | sebelum diputuskan, bukan sesudah.
        */
        $dibuka = [];
        $dikunci = [];

        $query->orderBy('drama_id')
            ->orderBy('episode_number')
            ->chunkById(500, function ($batch) use (&$dibuka, &$dikunci): void {
                foreach ($batch as $episode) {
                    $nomor = (int) $episode->episode_number;

                    if ($nomor < 1) {
                        continue;
                    }

                    $seharusnya = EpisodeObserver::seharusnyaVip($nomor);

                    if ((bool) $episode->is_vip === $seharusnya) {
                        continue;
                    }

                    if ($seharusnya) {
                        $dikunci[] = $episode;
                    } else {
                        $dibuka[] = $episode;
                    }
                }
            });

        $total = count($dibuka) + count($dikunci);

        $this->line('');
        $this->table(
            ['Perubahan', 'Jumlah'],
            [
                ['Part 1 yang jadi GRATIS (kini VIP)', number_format(count($dibuka))],
                ['Part 2+ yang jadi VIP (kini gratis)', number_format(count($dikunci))],
                ['Total', number_format($total)],
            ]
        );

        if ($total === 0) {
            $this->info('Semua part sudah sesuai aturan. Tidak ada yang perlu diubah.');

            return self::SUCCESS;
        }

        $this->tampilkanContoh('Akan dibuka (jadi gratis)', $dibuka, $contoh);
        $this->tampilkanContoh('Akan dikunci (jadi VIP)', $dikunci, $contoh);

        if (! $terapkan) {
            $this->line('');
            $this->info('Ini baru laporan — belum ada satu baris pun yang diubah.');
            $this->line('Tambahkan --terapkan kalau angka di atas sudah sesuai.');

            return self::SUCCESS;
        }

        if (count($dikunci) > 0 && $this->input->isInteractive()) {
            $this->line('');
            $this->warn(
                count($dikunci).' part yang selama ini gratis akan '
                .'menjadi khusus VIP.'
            );

            if (! $this->confirm('Lanjutkan?', false)) {
                $this->warn('Dibatalkan. Tidak ada yang diubah.');

                return self::SUCCESS;
            }
        }

        $diubah = $this->terapkan($dibuka, $dikunci);

        $this->line('');
        $this->info("{$diubah} part diselaraskan.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, Episode>  $dibuka
     * @param  array<int, Episode>  $dikunci
     */
    private function terapkan(array $dibuka, array $dikunci): int
    {
        $diubah = 0;

        /*
        | Sengaja `update()` massal per potongan id, bukan menyimpan satu
        | per satu lewat model.
        |
        | Lewat model berarti EpisodeObserver ikut berjalan untuk tiap baris
        | dan menghitung ulang nilai yang sudah kita hitung di sini — hasilnya
        | sama, kerjanya dua kali, dan untuk puluhan ribu baris itu terasa.
        | Nilainya di sini memang berasal dari aturan yang sama persis
        | (EpisodeObserver::seharusnyaVip), jadi tidak ada yang bisa
        | menyimpang.
        */
        DB::transaction(function () use ($dibuka, $dikunci, &$diubah): void {
            foreach ([[false, $dibuka], [true, $dikunci]] as [$nilai, $daftar]) {
                $ids = array_map(
                    static fn (Episode $e): int => (int) $e->id,
                    $daftar
                );

                foreach (array_chunk($ids, 500) as $bagian) {
                    $diubah += Episode::whereIn('id', $bagian)
                        ->update(['is_vip' => $nilai]);
                }
            }
        });

        return $diubah;
    }

    /**
     * @param  array<int, Episode>  $daftar
     */
    private function tampilkanContoh(string $judul, array $daftar, int $batas): void
    {
        if ($daftar === []) {
            return;
        }

        $this->line('');
        $this->line($judul.':');

        $baris = [];

        foreach (array_slice($daftar, 0, $batas) as $episode) {
            $baris[] = [
                $episode->drama_id,
                $episode->episode_number,
                $episode->title ?: '-',
                $episode->is_vip ? 'VIP' : 'Gratis',
            ];
        }

        $this->table(['Drama', 'Part', 'Judul', 'Sekarang'], $baris);

        if (count($daftar) > $batas) {
            $this->line('  ... dan '.number_format(count($daftar) - $batas).' lainnya.');
        }
    }
}
