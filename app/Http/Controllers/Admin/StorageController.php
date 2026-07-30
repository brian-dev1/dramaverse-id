<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use App\Services\StorageProviderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Storage Manager.
 *
 * 7.2A: daftar baca-saja. 7.2B: tambah provider.
 *
 * Ubah, hapus, enable, disable, set default, ubah prioritas, dan Test
 * Connection belum ada. Route-nya pun belum didaftarkan, dan itulah yang
 * membuat `crud/index.blade.php` tidak merender tombol Ubah maupun Hapus —
 * view memeriksa `Route::has()` sebelum menampilkan setiap tombol. Sifat
 * "belum ada" ditegakkan oleh ketiadaan route, bukan oleh view yang
 * menyembunyikan tombol yang sebenarnya berfungsi.
 *
 * Seluruh logika daftar (pencarian, filter, urutan, pagination, empty state)
 * datang dari AdminCrudController. Kelas ini hanya mendeklarasikan konfigurasi,
 * kecuali `store()` yang sengaja ditimpa — alasannya dijelaskan di sana.
 */
class StorageController extends AdminCrudController
{
    public function __construct(
        protected StorageProviderService $service
    ) {
    }

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

    /*
    |--------------------------------------------------------------------------
    | Form tambah (Sprint 7.2B)
    |--------------------------------------------------------------------------
    */

    /**
     * Data pendukung form.
     *
     * Peta field wajib per driver ikut dikirim supaya petunjuk di form berasal
     * dari sumber yang sama dengan validasinya. Kalau keduanya ditulis
     * terpisah, cepat atau lambat form akan menjanjikan sesuatu yang ditolak
     * server.
     */
    protected function formData(?Model $model = null): array
    {
        $requirements = [];

        foreach (StorageDriver::cases() as $driver) {
            $requirements[$driver->value] = [
                'label'    => $driver->label(),
                'required' => $driver->requiredFields(),
                'package'  => $driver->composerPackage(),
                'ready'    => $this->adapterInstalled($driver),
                'region'   => $driver->defaultRegion(),
            ];
        }

        return [
            'driverOptions'     => $this->allowedDriverOptions(),
            'visibilityOptions' => ['private' => 'Privat', 'public' => 'Publik'],
            'requirements'      => $requirements,
        ];
    }

    /**
     * Driver yang boleh dipilih, dibatasi config `storage.allowed_drivers`.
     */
    protected function allowedDriverOptions(): array
    {
        $allowed = (array) config('storage.allowed_drivers', StorageDriver::values());

        return array_intersect_key(
            StorageDriver::options(),
            array_flip($allowed)
        );
    }

    protected function adapterInstalled(StorageDriver $driver): bool
    {
        $class = $driver->adapterClass();

        return $class === null || class_exists($class);
    }

    /**
     * Aturan validasi.
     *
     * Field wajib TIDAK dipatok, melainkan diturunkan dari
     * `StorageDriver::requiredFields()`. R2 tidak memerlukan region (selalu
     * `auto`), S3 asli tidak memerlukan endpoint (diturunkan dari region),
     * dan `local` tidak memerlukan apa pun. Menulis satu daftar wajib yang
     * sama untuk sembilan provider akan menolak konfigurasi yang sebenarnya
     * sah.
     */
    protected function rules(Request $request, ?Model $model = null): array
    {
        $driver = StorageDriver::tryFrom((string) $request->input('driver'));

        $rules = [
            'name' => ['required', 'string', 'max:100'],

            'slug' => [
                'nullable', 'string', 'max:100', 'alpha_dash',
                Rule::unique('storage_providers', 'slug')->ignore($model?->getKey()),
            ],

            'driver' => [
                'required',
                Rule::in(array_keys($this->allowedDriverOptions())),
            ],

            'bucket' => ['nullable', 'string', 'max:255'],

            // Bukan rule `url`: endpoint MinIO sering berupa host:port di
            // jaringan lokal, dan sebagian operator menuliskannya tanpa skema.
            'endpoint' => ['nullable', 'string', 'max:255'],

            'region' => ['nullable', 'string', 'max:64'],

            'access_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:255'],

            'root' => ['nullable', 'string', 'max:255'],

            'public_url' => ['nullable', 'string', 'max:255', 'url'],

            'visibility' => ['required', Rule::in(['private', 'public'])],

            'use_path_style' => ['nullable', 'boolean'],

            // unsignedSmallInteger di migration: 0..65535.
            'priority' => ['required', 'integer', 'min:0', 'max:65535'],
        ];

        if ($driver !== null) {
            foreach ($driver->requiredFields() as $field) {
                $rules[$field] = $this->makeRequired($rules[$field] ?? ['string']);
            }

            $rules['driver'][] = $this->rejectMissingAdapter($driver);
        }

        return $rules;
    }

    /**
     * Menjadikan satu aturan wajib tanpa menghapus batasan lainnya.
     */
    protected function makeRequired(array $rules): array
    {
        $rules = array_values(array_filter(
            $rules,
            fn ($rule) => $rule !== 'nullable'
        ));

        array_unshift($rules, 'required');

        return $rules;
    }

    /**
     * Menolak driver yang paket composer-nya belum terpasang.
     *
     * Tanpa ini, provider tersimpan rapi dan terlihat benar di daftar, lalu
     * gagal saat disk dibangun dengan galat Flysystem yang tidak menyebut
     * sebabnya. Lebih baik ditolak di form, di tempat orangnya masih
     * memperhatikan.
     */
    protected function rejectMissingAdapter(StorageDriver $driver): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($driver): void {
            if ($this->adapterInstalled($driver)) {
                return;
            }

            $fail(
                "Adapter untuk {$driver->label()} belum terpasang di server. "
                ."Jalankan: composer require {$driver->composerPackage()}"
            );
        };
    }

    /**
     * Pesan validasi.
     *
     * Kolom yang wajibnya bergantung driver diberi pesan yang menyebut driver
     * itu. "Kolom bucket wajib diisi" memancing pertanyaan "kenapa? kemarin
     * tidak"; "Driver Cloudflare R2 memerlukan bucket" langsung menjawabnya.
     *
     * Kolom yang selalu wajib (nama, visibility, priority) tetap memakai pesan
     * umum — menyebut driver di sana justru menyesatkan.
     */
    protected function validationMessages(?StorageDriver $driver = null): array
    {
        $messages = [
            'required'        => 'Kolom ini wajib diisi.',
            'slug.unique'     => 'Slug ini sudah dipakai provider lain.',
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, strip, dan garis bawah.',
            'public_url.url'  => 'URL publik harus lengkap, termasuk https://.',
            'priority.max'    => 'Priority paling besar 65535.',
            'driver.in'       => 'Driver itu tidak dikenali, atau tidak diizinkan di server ini.',
        ];

        if ($driver === null) {
            return $messages;
        }

        $namaKolom = [
            'bucket'     => 'bucket',
            'endpoint'   => 'endpoint',
            'region'     => 'region',
            'access_key' => 'access key',
            'secret_key' => 'secret key',
        ];

        foreach ($driver->requiredFields() as $field) {
            $messages[$field.'.required'] = sprintf(
                'Driver %s memerlukan %s.',
                $driver->label(),
                $namaKolom[$field] ?? $field
            );
        }

        return $messages;
    }

    /**
     * Simpan provider baru.
     *
     * Sengaja menimpa `store()` bawaan. Versi bawaan memanggil
     * `Model::create()` langsung, yang berarti melewati StorageProviderService
     * — padahal di sanalah penjagaan bisnisnya berada: provider pertama
     * otomatis jadi default, normalisasi driver, path-style yang dipaksa untuk
     * MinIO dan R2, region bawaan, dan pencatatan aktivitas.
     *
     * Ini bukan duplikasi logika, melainkan mengarahkan ke lapisan yang benar.
     * Base class tidak dipanggil sama sekali supaya aktivitas tidak tercatat
     * dua kali.
     */
    public function store(Request $request): RedirectResponse
    {
        $driver = StorageDriver::tryFrom((string) $request->input('driver'));

        $data = $request->validate(
            $this->rules($request),
            $this->validationMessages($driver)
        );

        // Checkbox yang tidak dicentang tidak ikut terkirim sama sekali.
        $data['use_path_style'] = $request->boolean('use_path_style');

        // Provider baru SELALU dibuat nonaktif, dan status tidak disediakan di
        // form. Sprint 7.1 menetapkan provider tidak boleh menerima lalu lintas
        // sebelum Test Connection berhasil — sedangkan Test Connection dari
        // panel baru dibuat di sprint berikutnya. Mengizinkan admin langsung
        // menandainya aktif berarti memasukkan tujuan penyimpanan yang belum
        // pernah terbukti bisa dihubungi ke dalam rantai fallback.
        $data['status'] = StorageStatus::INACTIVE->value;

        $provider = $this->service->store($data);

        return redirect()
            ->route('admin.storage.index')
            ->with('status', sprintf(
                'Storage provider "%s" tersimpan sebagai nonaktif. '
                .'Uji dulu dengan: php artisan storage:test %s',
                $provider->name,
                $provider->slug
            ));
    }
}
