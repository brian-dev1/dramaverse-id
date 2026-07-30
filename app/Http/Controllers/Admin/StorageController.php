<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Storage Manager — daftar baca-saja.
 *
 * Sprint 7.2A sengaja hanya menampilkan. Tambah, ubah, hapus, enable,
 * disable, set default, ubah prioritas, dan Test Connection belum ada.
 *
 * Karena route tulis untuk modul ini tidak didaftarkan, `crud/index.blade.php`
 * otomatis tidak merender tombol Tambah, Ubah, maupun Hapus — view itu
 * memeriksa `Route::has()` sebelum menampilkan setiap tombol. Jadi tidak ada
 * tombol mati di halaman ini, dan itu terjadi tanpa penanganan khusus.
 *
 * Seluruh logika daftar (pencarian, filter, urutan, pagination, empty state)
 * datang dari AdminCrudController. Kelas ini hanya mendeklarasikan konfigurasi.
 */
class StorageController extends AdminCrudController
{
    protected function model(): string
    {
        return StorageProvider::class;
    }

    protected function routeKey(): string
    {
        return 'storage';
    }

    protected function label(): string
    {
        return 'Storage Provider';
    }

    /**
     * Kolom sesuai spesifikasi sprint.
     *
     * `access_key` dan `secret_key` TIDAK ditampilkan, dan itu bukan
     * kelalaian: keduanya tersimpan terenkripsi dan akan terdekripsi begitu
     * dibaca lewat model. Menampilkannya di tabel berarti mengirim kredensial
     * produksi ke dalam HTML setiap kali halaman ini dibuka.
     */
    protected function columns(): array
    {
        return [
            'Nama'       => 'name',
            'Driver'     => 'driver',
            'Bucket'     => 'bucket',
            'Endpoint'   => 'endpoint',
            'Region'     => 'region',
            'Status'     => 'status',
            'Priority'   => 'priority',
            'Default'    => 'is_default',
            'Created At' => 'created_at',
        ];
    }

    /**
     * Kolom yang boleh diurutkan — hanya yang benar-benar ada di tabel.
     *
     * Sengaja tidak memakai bawaan `array_values($this->columns())`, karena
     * mengurutkan menurut `endpoint` atau `bucket` tidak ada gunanya dan cuma
     * menambah tautan yang tak berguna di kepala tabel.
     */
    protected function sortable(): array
    {
        return ['name', 'driver', 'status', 'priority', 'is_default', 'created_at'];
    }

    /**
     * Kolom yang dicari.
     *
     * Kredensial dikecualikan dengan sengaja. Selain alasan keamanan, mencari
     * di dalamnya juga tidak akan pernah bekerja: nilainya terenkripsi di
     * database, sehingga `LIKE` mencocokkan ciphertext, bukan isinya.
     */
    protected function searchable(): array
    {
        return ['name', 'slug', 'driver', 'bucket', 'endpoint', 'region'];
    }

    /**
     * Filter Driver dan Status.
     *
     * Kunci opsi diambil dari enum, jadi daftarnya tidak bisa melenceng dari
     * nilai yang benar-benar tersimpan di kolom. Menambah provider ke-10 di
     * StorageDriver otomatis memunculkannya di filter ini.
     */
    protected function filters(): array
    {
        return [
            'driver' => [
                'label'   => 'Driver',
                'options' => StorageDriver::options(),
            ],
            'status' => [
                'label'   => 'Status',
                'options' => StorageStatus::options(),
            ],
        ];
    }

    /**
     * Urut prioritas, bukan terbaru-dulu.
     *
     * Inilah urutan yang benar-benar dipakai StorageManager saat memilih
     * tujuan penyimpanan. Daftar yang urutannya berbeda dari perilaku sistem
     * akan menyesatkan orang yang sedang menelusuri masalah.
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->byPriority();
    }

    /**
     * Belum ada aksi massal di sprint ini.
     */
    protected function bulkActions(): array
    {
        return [];
    }

    /**
     * Tidak ada route tulis untuk modul ini, jadi tidak ada yang divalidasi.
     */
    protected function rules(Request $request, ?Model $model = null): array
    {
        return [];
    }
}
