<?php

namespace App\Services\Storage;

use App\Models\StorageProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\Waktu;

/**
 * Angka-angka untuk Storage Monitoring.
 *
 * Kelas ini HANYA MEMBACA. Tidak ada satu pun operasi tulis di sini, tidak
 * menyentuh `Storage`, dan tidak pernah menghubungi provider mana pun.
 * Test Connection tetap milik `StorageProviderService::test()` yang sudah ada
 * sejak Sprint 7.1 — halaman monitoring memanggilnya, bukan menulis ulang
 * versinya sendiri.
 *
 * ## Dari mana angkanya
 *
 * "Total Uploaded Files" dan "Total Storage Used" dihitung dari DATABASE
 * (`episode_videos` + `drama_assets`), bukan dari isi bucket.
 *
 * Itu keputusan, bukan jalan pintas. Menghitung dari bucket berarti
 * menjalankan operasi list terhadap setiap provider setiap kali halaman
 * dibuka: lambat, berbayar pada sebagian provider, dan halamannya akan macet
 * total begitu satu provider tidak bisa dihubungi. Angka dari database selalu
 * bisa ditampilkan, dan yang diukur pun sebenarnya lebih tepat — yang ingin
 * diketahui admin adalah "berapa banyak berkas yang dikenal aplikasi ini",
 * bukan "berapa objek yang kebetulan ada di bucket".
 *
 * Konsekuensinya harus disebut terus terang, dan halamannya menyebutkannya:
 * berkas yatim — objek di bucket yang barisnya sudah hilang — TIDAK terhitung
 * di sini. Sprint 7.4 sampai 7.7 mencatat setiap objek yatim yang tertinggal
 * ke log dengan peristiwa `*.orphan`.
 */
class StorageMonitorService
{
    /**
     * Dua tabel yang menyimpan berkas terunggah, beserta nama tampilannya.
     *
     * Ditulis sekali di sini dan dipakai ulang seluruh kelas. Menambahkan
     * modul berkas ketiga nanti berarti menambah satu baris di sini, bukan
     * menyisir seluruh berkas mencari `episode_videos` yang tertinggal.
     *
     * @var array<string, string>
     */
    public const SOURCE_TABLES = [
        'episode_videos' => 'Video part',
        'drama_assets'   => 'Aset drama',
    ];

    /**
     * Seluruh angka yang ditampilkan halaman monitoring.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $providers = $this->providers();

        $files = $this->fileTotals();

        return [
            'providers' => $this->providerCounts($providers),
            'files'     => $files,
            'default'   => $this->defaultSummary($providers),
            'test'      => $this->testSummary($providers),
            'rows'      => $this->rows($providers),
            'at'        => Waktu::ringkas(now()),
        ];
    }

    /**
     * Provider yang masih hidup, urut prioritas.
     *
     * Yang sudah di-soft-delete sengaja tidak ikut. Baris itu memang masih ada
     * demi pemulihan (Sprint 7.2C), tetapi menghitungnya sebagai "provider
     * nonaktif" akan membuat angka Total tidak pernah cocok dengan apa yang
     * terlihat di Storage Manager.
     *
     * @return Collection<int, StorageProvider>
     */
    public function providers(): Collection
    {
        return StorageProvider::query()->byPriority()->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Penghitung provider
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, StorageProvider>  $providers
     * @return array<string, int>
     */
    protected function providerCounts(Collection $providers): array
    {
        $aktif = $providers->filter(fn (StorageProvider $p) => $p->isActive());

        return [
            'total'    => $providers->count(),
            'active'   => $aktif->count(),
            'inactive' => $providers->count() - $aktif->count(),

            // Provider yang aktif TAPI belum tentu bisa dipakai: adapter
            // composer-nya belum terpasang, field wajibnya belum lengkap,
            // atau nilai contohnya belum diganti. Angka ini yang menjelaskan
            // kenapa unggahan gagal padahal statusnya hijau.
            'unusable' => $aktif->reject(fn (StorageProvider $p) => $p->isUsable())->count(),
        ];
    }

    /**
     * Provider default beserta keadaannya.
     *
     * Tiga hal yang berbeda dan sering tertukar: tidak ada default sama
     * sekali, ada tapi nonaktif, dan ada tapi belum bisa dipakai. Ketiganya
     * membuat mode Auto gagal, dengan perbaikan yang berbeda-beda.
     *
     * @param  Collection<int, StorageProvider>  $providers
     * @return array<string, mixed>
     */
    protected function defaultSummary(Collection $providers): array
    {
        $default = $providers->firstWhere('is_default', true);

        if ($default === null) {
            return [
                'name'    => null,
                'ok'      => false,
                'problem' => 'Belum ada provider default. Mode Auto akan gagal untuk '
                             .'setiap unggahan sampai salah satu provider ditetapkan '
                             .'sebagai default di Storage Manager.',
            ];
        }

        if (! $default->isActive()) {
            return [
                'name'    => $default->name,
                'ok'      => false,
                'problem' => 'Provider default sedang nonaktif. Mode Auto akan gagal '
                             .'sampai provider ini diaktifkan atau default dipindah.',
            ];
        }

        if (! $default->isUsable()) {
            return [
                'name'    => $default->name,
                'ok'      => false,
                'problem' => 'Provider default aktif tetapi belum siap dipakai: '
                             .$this->alasanTidakSiap($default),
            ];
        }

        return ['name' => $default->name, 'ok' => true, 'problem' => null];
    }

    /**
     * Kenapa sebuah provider yang aktif tetap belum bisa dipakai.
     *
     * Disusun dari pemeriksaan yang SAMA dengan yang dipakai
     * `StorageEngine::assertReady()`. Kalau halaman ini memakai daftar
     * pemeriksaannya sendiri, cepat atau lambat akan ada provider yang
     * dilaporkan sehat di sini lalu ditolak engine tanpa penjelasan.
     */
    public function alasanTidakSiap(StorageProvider $provider): string
    {
        if (! $provider->hasAdapterInstalled()) {
            return 'adapter '.$provider->driver->label().' belum terpasang di vendor/ '
                   .'(jalankan composer require league/flysystem-aws-s3-v3 di PC).';
        }

        $kurang = $provider->missingFields();

        if ($kurang !== []) {
            return 'field wajib belum diisi: '.implode(', ', $kurang).'.';
        }

        $contoh = $provider->placeholderFields();

        if ($contoh !== []) {
            return 'masih memuat nilai contoh pada: '.implode(', ', array_keys($contoh)).'.';
        }

        return 'sebabnya tidak dikenali pemeriksaan mana pun — jalankan Test Connection.';
    }

    /**
     * Ringkasan hasil Test Connection seluruh provider.
     *
     * @param  Collection<int, StorageProvider>  $providers
     * @return array<string, mixed>
     */
    protected function testSummary(Collection $providers): array
    {
        $terakhir = $providers
            ->pluck('last_tested_at')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'ok'          => $providers->where('last_test_status', 'ok')->count(),
            'failed'      => $providers->where('last_test_status', 'failed')->count(),
            'never'       => $providers->whereNull('last_test_status')->count(),
            'last_at'     => $terakhir instanceof Carbon ? Waktu::ringkas($terakhir) : null,
            'last_for_humans' => $terakhir instanceof Carbon ? $terakhir->diffForHumans() : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Penghitung berkas
    |--------------------------------------------------------------------------
    */

    /**
     * Jumlah dan ukuran seluruh berkas terunggah, plus potongan waktunya.
     *
     * @return array<string, int|string>
     */
    public function fileTotals(): array
    {
        $total = 0;
        $bytes = 0;
        $hariIni = 0;
        $bulanIni = 0;

        $awalHari  = now()->startOfDay();
        $awalBulan = now()->startOfMonth();

        foreach (array_keys(self::SOURCE_TABLES) as $table) {

            // Satu query per tabel, bukan satu query per angka. Empat angka
            // dari satu kali baca — `uploaded_at` sudah ada indeksnya di
            // kedua tabel, tetapi tetap saja delapan query untuk empat angka
            // adalah pemborosan yang tidak perlu.
            $baris = DB::table($table)
                ->selectRaw('COUNT(*) as jumlah')
                ->selectRaw('COALESCE(SUM(size), 0) as ukuran')
                ->selectRaw('SUM(CASE WHEN uploaded_at >= ? THEN 1 ELSE 0 END) as hari_ini', [$awalHari])
                ->selectRaw('SUM(CASE WHEN uploaded_at >= ? THEN 1 ELSE 0 END) as bulan_ini', [$awalBulan])
                ->first();

            $total    += (int) ($baris->jumlah ?? 0);
            $bytes    += (int) ($baris->ukuran ?? 0);
            $hariIni  += (int) ($baris->hari_ini ?? 0);
            $bulanIni += (int) ($baris->bulan_ini ?? 0);
        }

        return [
            'total'      => $total,
            'bytes'      => $bytes,
            'size_human' => self::bytesForHumans($bytes),
            'today'      => $hariIni,
            'month'      => $bulanIni,
        ];
    }

    /**
     * Satu baris tabel monitoring per provider.
     *
     * @param  Collection<int, StorageProvider>  $providers
     * @return array<int, array<string, mixed>>
     */
    protected function rows(Collection $providers): array
    {
        $perProvider = $this->filesPerProvider();

        return $providers->map(function (StorageProvider $provider) use ($perProvider) {

            $id = (int) $provider->getKey();

            $angka = $perProvider[$id] ?? ['jumlah' => 0, 'ukuran' => 0];

            return [
                'id'          => $id,
                'name'        => $provider->name,
                'driver'      => $provider->driver->label(),
                'active'      => $provider->isActive(),
                'is_default'  => (bool) $provider->is_default,
                'usable'      => $provider->isUsable(),
                'not_ready'   => $provider->isActive() && ! $provider->isUsable()
                    ? $this->alasanTidakSiap($provider)
                    : null,
                'test_status' => $provider->last_test_status,
                'test_badge'  => $this->testBadge($provider->last_test_status),
                'test_label'  => $this->testLabel($provider->last_test_status),
                'tested_at'   => Waktu::ringkas($provider->last_tested_at),
                'duration'    => $provider->last_test_duration,
                'files'       => (int) $angka['jumlah'],
                'bytes'       => (int) $angka['ukuran'],
                'size_human'  => self::bytesForHumans((int) $angka['ukuran']),
            ];
        })->all();
    }

    /**
     * Jumlah dan ukuran berkas, dikelompokkan per provider.
     *
     * @return array<int, array{jumlah: int, ukuran: int}>
     */
    public function filesPerProvider(): array
    {
        $hasil = [];

        foreach (array_keys(self::SOURCE_TABLES) as $table) {

            $baris = DB::table($table)
                ->selectRaw('storage_provider_id, COUNT(*) as jumlah, COALESCE(SUM(size), 0) as ukuran')
                ->groupBy('storage_provider_id')
                ->get();

            foreach ($baris as $item) {

                // Provider yang sudah dihapus permanen meninggalkan
                // `storage_provider_id` bernilai null (nullOnDelete). Berkasnya
                // tetap terhitung di total keseluruhan, tetapi tidak bisa
                // ditempelkan ke baris provider mana pun.
                if ($item->storage_provider_id === null) {
                    continue;
                }

                $id = (int) $item->storage_provider_id;

                $hasil[$id]['jumlah'] = ($hasil[$id]['jumlah'] ?? 0) + (int) $item->jumlah;
                $hasil[$id]['ukuran'] = ($hasil[$id]['ukuran'] ?? 0) + (int) $item->ukuran;
            }
        }

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu tampilan
    |--------------------------------------------------------------------------
    */

    /**
     * Kelas badge hasil Test Connection.
     *
     * Dipetakan di sini, bukan di Blade, dengan alasan yang sama seperti
     * `UploadStatus::badgeClass()`: nilai baru tidak boleh meninggalkan badge
     * tanpa warna di halaman yang kebetulan tidak ikut disunting.
     */
    public function testBadge(?string $status): string
    {
        return match ($status) {
            'ok'     => 'badge-on',
            'failed' => 'badge-off',
            default  => 'badge-pending',
        };
    }

    public function testLabel(?string $status): string
    {
        return match ($status) {
            'ok'     => 'Terhubung',
            'failed' => 'Gagal',
            default  => 'Belum pernah diuji',
        };
    }

    /**
     * Byte menjadi satuan yang terbaca.
     *
     * Ada tiga salinan logika ini di proyek — `EpisodeVideo`, `DramaAsset`,
     * dan `UploadJob` — yang masing-masing bekerja pada kolom `size` model
     * sendiri. Yang di sini bekerja pada angka lepas hasil SUM, yang bukan
     * milik model mana pun. Penyatuan ketiganya dicatat di STATUS.md sebagai
     * pekerjaan tersendiri; menariknya sekarang berarti menyentuh dua model
     * milik sprint yang sedang tidak boleh diubah.
     */
    public static function bytesForHumans(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        $size = (float) max(0, $bytes);

        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $size : number_format($size, 2)).' '.$units[$i];
    }
}
