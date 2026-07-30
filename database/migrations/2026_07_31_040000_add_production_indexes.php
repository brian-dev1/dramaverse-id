<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk kolom yang paling sering dipakai menyaring dan mengurutkan.
 *
 * Seluruhnya **aditif**: tidak ada kolom yang berubah, tidak ada data yang
 * disentuh, dan aplikasi berjalan persis sama tanpa migration ini — hanya
 * lebih lambat seiring bertambahnya baris.
 *
 * Setiap indeks dibungkus pemeriksaan keberadaan. Sebagian mungkin sudah ada
 * dari migration sebelumnya, dan `CREATE INDEX` yang menabrak indeks yang
 * sudah ada akan menghentikan `deploy.sh` di langkah migrate — kegagalan
 * yang tidak perlu untuk perubahan yang seharusnya tidak berisiko sama
 * sekali.
 *
 * Indeks gabungan disusun mengikuti aturan kolom paling selektif lebih dulu
 * untuk penyaringan, diikuti kolom pengurutan. `(user_id, created_at)` pada
 * activity_logs melayani pertanyaan yang paling sering diajukan halaman log:
 * "apa saja yang dilakukan pengguna ini, terbaru lebih dulu".
 */
return new class extends Migration
{
    /**
     * Tabel => nama indeks => kolomnya.
     *
     * @var array<string, array<string, array<int,string>>>
     */
    private array $indexes = [

        'activity_logs' => [
            'activity_logs_user_created_index'   => ['user_id', 'created_at'],
            'activity_logs_module_created_index' => ['module', 'created_at'],
        ],

        'episodes' => [
            'episodes_drama_number_index' => ['drama_id', 'episode_number'],
        ],

        'watch_histories' => [
            'watch_histories_user_updated_index' => ['user_id', 'updated_at'],
        ],

        'favorites' => [
            'favorites_user_drama_index' => ['user_id', 'drama_id'],
        ],

        'users' => [
            'users_last_seen_index' => ['last_seen_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tabel => $daftar) {

            if (! Schema::hasTable($tabel)) {
                continue;
            }

            foreach ($daftar as $nama => $kolom) {

                // Kolomnya belum tentu ada — beberapa tabel dibuat di sprint
                // yang berbeda dan bentuknya bisa berubah.
                if (! $this->kolomAda($tabel, $kolom)) {
                    continue;
                }

                if ($this->indexAda($tabel, $nama)) {
                    continue;
                }

                Schema::table($tabel, function (Blueprint $table) use ($kolom, $nama) {
                    $table->index($kolom, $nama);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $tabel => $daftar) {

            if (! Schema::hasTable($tabel)) {
                continue;
            }

            foreach (array_keys($daftar) as $nama) {

                if (! $this->indexAda($tabel, $nama)) {
                    continue;
                }

                Schema::table($tabel, function (Blueprint $table) use ($nama) {
                    $table->dropIndex($nama);
                });
            }
        }
    }

    /** @param  array<int,string>  $kolom */
    private function kolomAda(string $tabel, array $kolom): bool
    {
        foreach ($kolom as $satu) {
            if (! Schema::hasColumn($tabel, $satu)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apakah indeks bernama itu sudah ada.
     *
     * Diperiksa lewat SHOW INDEX lalu disaring di PHP — bukan lewat
     * `WHERE Key_name = ?`, karena SHOW tidak menerima placeholder di semua
     * versi MySQL, dan bukan lewat Doctrine, yang tidak lagi ikut secara
     * bawaan di Laravel 12.
     *
     * Nama tabel TIDAK datang dari luar: ia berasal dari daftar tetap di atas.
     * Itu yang membuat penggabungannya ke dalam SQL aman di sini, dan tidak
     * boleh ditiru untuk nilai yang datang dari permintaan.
     */
    private function indexAda(string $tabel, string $nama): bool
    {
        $koneksi = Schema::getConnection();

        if ($koneksi->getDriverName() !== 'mysql') {
            // Driver lain: biarkan Schema::table yang memutuskan. Migration
            // ini memang ditulis untuk MySQL, dan proyek ini hanya memakai
            // MySQL di produksi.
            return false;
        }

        foreach ($koneksi->select('SHOW INDEX FROM `'.$tabel.'`') as $baris) {

            if (($baris->Key_name ?? null) === $nama) {
                return true;
            }
        }

        return false;
    }
};
