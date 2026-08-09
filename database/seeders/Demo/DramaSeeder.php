<?php

namespace Database\Seeders\Demo;

use App\Models\Country;
use App\Models\Drama;
use App\Models\Episode;
use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DramaSeeder extends Seeder
{
    /**
     * Katalog contoh — judul, sinopsis, dan metadata fiktif
     * agar homepage langsung terisi setelah migrate --seed.
     */
    private const CATALOG = [
        ['Musim Semi di Hanok Tua', 'Korea Selatan', ['Romansa', 'Keluarga'], 2026, 16, 9.1, 'g3', true, true, false,
            'Seorang arsitek dari Seoul kembali ke kampung halaman untuk merenovasi rumah tradisional peninggalan neneknya, dan menemukan surat cinta lama yang mengubah segalanya.'],
        ['Sutra & Baja', 'Tiongkok', ['Sejarah', 'Aksi'], 2025, 40, 9.4, 'g3', true, true, true,
            'Putri seorang pedagang sutra menyamar sebagai perwira untuk membalas kematian ayahnya di perbatasan utara.'],
        ['Hujan di Gyeongbokgung', 'Korea Selatan', ['Sejarah', 'Romansa'], 2025, 20, 9.2, 'g5', true, true, false,
            'Seorang pelukis istana jatuh cinta pada selir raja, dan setiap goresan kuasnya menjadi pengakuan yang tak bisa diucapkan.'],
        ['Restoran Tengah Malam', 'Thailand', ['Keluarga', 'Komedi'], 2026, 12, 9.0, 'g7', false, true, false,
            'Sebuah kedai yang hanya buka dari tengah malam sampai subuh, tempat orang-orang yang tidak bisa tidur saling menemukan.'],
        ['Detektif Toko Buku', 'Jepang', ['Misteri'], 2025, 10, 8.9, 'g1', false, true, false,
            'Pemilik toko buku bekas memecahkan kasus pembunuhan lewat catatan pinggir yang ditinggalkan pembaca sebelumnya.'],
        ['Pewaris Tersembunyi', 'Korea Selatan', ['Misteri', 'Thriller'], 2025, 16, 8.8, 'g8', true, true, false,
            'Seorang pegawai magang mengetahui bahwa dirinya adalah ahli waris konglomerat yang selama ini memburunya.'],
        ['Jalan Setapak Guilin', 'Tiongkok', ['Romansa'], 2024, 24, 8.7, 'g2', false, false, false,
            'Dua pemandu wisata yang saling membenci terpaksa menempuh jalur pegunungan yang sama selama satu musim.'],
        ['Api di Bawah Salju', 'Korea Selatan', ['Aksi', 'Thriller'], 2026, 14, 8.6, 'g1', true, false, true,
            'Regu penyelamat gunung menemukan sesuatu yang seharusnya tetap terkubur di bawah longsoran.'],
        ['Perjanjian Bulan Purnama', 'Tiongkok', ['Fantasi', 'Romansa'], 2025, 36, 8.9, 'g2', true, false, false,
            'Seorang gadis desa membuat perjanjian dengan roh bulan, dan harus membayarnya setiap purnama.'],
        ['Pelabuhan Terakhir', 'Jepang', ['Keluarga'], 2024, 11, 8.4, 'g4', false, false, false,
            'Tiga bersaudara pulang untuk menjual galangan kapal ayah mereka, dan menemukan alasan untuk tetap tinggal.'],
        ['Warisan Keluarga Han', 'Korea Selatan', ['Keluarga', 'Sejarah'], 2024, 20, 8.7, 'g5', false, false, false,
            'Perebutan resep kimchi turun-temurun berubah menjadi perang dingin antar generasi.'],
        ['Kota Tanpa Nama', 'Taiwan', ['Misteri', 'Thriller'], 2026, 8, 8.8, 'g6', true, false, true,
            'Seorang jurnalis menyelidiki kota yang dihapus dari seluruh peta resmi negara.'],
        ['Angin dari Utara', 'Korea Selatan', ['Romansa', 'Sekolah'], 2026, 16, 8.5, 'g6', false, false, true,
            'Murid pindahan dari Busan mengubah dinamika kelas yang sudah bertahun-tahun beku.'],
        ['Puisi yang Terlupakan', 'Tiongkok', ['Sejarah', 'Romansa'], 2026, 30, 8.6, 'g4', false, false, true,
            'Seorang penyair istana dihukum karena satu bait, dan muridnya menghabiskan hidup memulihkan namanya.'],
        ['Toko Kaset Kenangan', 'Jepang', ['Fantasi', 'Komedi'], 2026, 10, 8.3, 'g8', false, false, true,
            'Toko kaset yang setiap rekamannya memutar kembali satu hari dalam hidup pembelinya.'],
        ['Cinta di Musim Kemarau', 'Thailand', ['Romansa'], 2026, 12, 8.2, 'g5', false, false, true,
            'Dua petani padi bertaruh pada hujan yang tak kunjung datang, dan pada satu sama lain.'],
        ['Rumah di Ujung Waktu', 'Korea Selatan', ['Fantasi', 'Misteri'], 2026, 16, 9.0, 'g7', true, false, true,
            'Sebuah rumah yang setiap kamarnya membuka ke tahun yang berbeda.'],
        ['Nyanyian Pelabuhan', 'Tiongkok', ['Keluarga', 'Sejarah'], 2026, 24, 8.5, 'g1', false, false, true,
            'Kisah tiga generasi nelayan yang terikat pada satu lagu yang diwariskan turun-temurun.'],
        ['Musim Gugur Terakhir', 'Korea Selatan', ['Romansa', 'Medis'], 2025, 16, 9.6, 'g7', true, false, false,
            'Dokter onkologi merawat mantan kekasihnya, dan harus memilih antara kejujuran medis dan belas kasih.'],
        ['Kaisar Tanpa Mahkota', 'Tiongkok', ['Sejarah', 'Aksi'], 2024, 45, 9.5, 'g1', true, false, false,
            'Pangeran yang dibuang membangun kembali kekuasaannya dari desa perbatasan.'],
        ['Diary Hujan Kedua', 'Jepang', ['Romansa'], 2025, 12, 9.4, 'g4', false, false, false,
            'Dua orang bertukar buku harian tanpa pernah bertemu, selama tiga musim hujan berturut-turut.'],
        ['Jembatan Dua Kota', 'Taiwan', ['Misteri', 'Thriller'], 2025, 18, 9.3, 'g8', true, false, false,
            'Satu jembatan, dua yurisdiksi kepolisian, dan mayat yang ditemukan tepat di garis batas.'],
        ['Rahasia Griya Tenun', 'Filipina', ['Keluarga', 'Misteri'], 2024, 24, 9.3, 'g2', false, false, false,
            'Motif tenun keluarga ternyata menyimpan peta menuju sesuatu yang disembunyikan tiga generasi.'],
        ['Penjaga Mercusuar', 'Korea Selatan', ['Keluarga', 'Misteri'], 2025, 16, 9.2, 'g3', false, false, false,
            'Penjaga mercusuar terakhir menolak pindah, dan alasannya jauh lebih berat dari nostalgia.'],
        ['Malam Tanpa Bintang', 'Tiongkok', ['Thriller'], 2025, 20, 8.9, 'g2', true, false, false,
            'Pemadaman listrik massal di Shanghai menyembunyikan satu pembunuhan yang direncanakan bertahun-tahun.'],
        ['Persimpangan Han River', 'Korea Selatan', ['Romansa', 'Komedi'], 2025, 24, 8.8, 'g6', false, false, false,
            'Empat orang asing yang setiap pagi berpapasan di jembatan yang sama, tanpa pernah menyadarinya.'],
    ];

    public function run(): void
    {
        $countries = Country::pluck('id', 'name');
        $genres    = Genre::pluck('id', 'name');

        foreach (self::CATALOG as $i => $row) {
            [$title, $country, $genreNames, , $episodes, , $gradient, $isVip, $isTrending, $isNew, $synopsis] = $row;

            $drama = Drama::updateOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'title'          => $title,
                    'synopsis'       => $synopsis,
                    'gradient'       => $gradient,
                    'country_id'     => $countries[$country] ?? null,
                    'total_episode'  => $episodes,
                    'status'         => $isNew ? 'ongoing' : 'completed',
                    'views'          => random_int(1_200, 480_000),
                    'is_vip'         => $isVip,
                    'is_featured'    => $i < 5,
                    'is_trending'    => $isTrending,
                    'published_at'   => $isNew ? now()->subDays($i) : now()->subMonths($i % 18 + 1),
                ]
            );

            // --- Genre (pivot) ---
            $drama->genres()->sync(
                collect($genreNames)->map(fn ($g) => $genres[$g] ?? null)->filter()->all()
            );

            // --- Episode ---
            $this->seedEpisodes($drama, $isNew ? min($episodes, 6) : $episodes);
        }
    }

    private function seedEpisodes(Drama $drama, int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            Episode::updateOrCreate(
                ['drama_id' => $drama->id, 'episode_number' => $n],
                [
                    'title'        => 'Episode '.$n,
                    'slug'         => $drama->slug.'-episode-'.$n,
                    'video_url'    => 'https://cdn.example.com/'.$drama->slug.'/ep-'.$n.'.mp4',
                    // Episode 1-2 selalu gratis sebagai pemikat.
                    'is_vip'       => $drama->is_vip && $n > 2,
                    'views'        => random_int(100, 60_000),
                    'air_date'     => $drama->published_at?->addDays($n * 3),
                    'status'       => 'published',
                    'published_at' => $drama->published_at?->addDays($n * 3) ?? now(),
                ]
            );
        }
    }
}
