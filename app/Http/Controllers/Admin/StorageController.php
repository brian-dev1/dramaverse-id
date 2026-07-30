<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StorageDriver;
use App\Enums\StorageStatus;
use App\Models\StorageProvider;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\Exceptions\StorageProviderException;
use App\Services\Storage\StorageTestResult;
use App\Services\StorageProviderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Storage Manager.
 *
 * 7.2A: daftar baca-saja. 7.2B: tambah provider. 7.2C: ubah, hapus (soft
 * delete), pulihkan.
 *
 * Enable, Disable, Set Default, ubah prioritas, dan Test Connection belum ada.
 * Route-nya pun belum didaftarkan, dan itulah yang membuat tombolnya tidak
 * pernah muncul — `crud/index.blade.php` memeriksa `Route::has()` sebelum
 * menampilkan setiap tombol. Sifat "belum ada" ditegakkan oleh ketiadaan
 * route, bukan oleh view yang menyembunyikan tombol yang sebenarnya berfungsi.
 *
 * Seluruh logika daftar (pencarian, filter, urutan, pagination, empty state,
 * filter Terhapus) datang dari AdminCrudController. Kelas ini mendeklarasikan
 * konfigurasi, kecuali `store()`, `update()`, `destroy()`, dan `restore()`
 * yang sengaja ditimpa — alasan tiap penimpaan dijelaskan di tempatnya.
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

            // Accessor, bukan kolom. Menggabungkan hasil, waktu respons, dan
            // kapan terakhir diuji — ketiganya hanya bermakna bersama-sama.
            // Pesan galatnya tidak ikut: panjangnya bisa satu paragraf dan
            // akan merusak tata letak tabel.
            'Uji Terakhir' => 'last_test_summary',

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
        // `last_test_summary` sengaja tidak ada di sini: ia accessor, bukan
        // kolom, sehingga `orderBy` atasnya akan menghasilkan galat SQL.
        // Untuk mengurutkan menurut kapan terakhir diuji, `last_tested_at`
        // yang dipakai — kolomnya nyata.
        return [
            'name', 'driver', 'status', 'priority', 'is_default',
            'last_tested_at', 'created_at',
        ];
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

            // Apakah kredensial sudah tersimpan — hanya benar/salah, tanpa
            // isinya. Form perlu tahu ini untuk memberi tahu admin bahwa
            // membiarkan kolom kosong berarti mempertahankan yang lama.
            // Dibaca lewat getRawOriginal() agar tidak memicu dekripsi.
            'credentialsStored' => [
                'access_key' => $this->alreadyStored($model, 'access_key'),
                'secret_key' => $this->alreadyStored($model, 'secret_key'),
            ],
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

                // `whereNull('deleted_at')` wajib: index unique di database
                // kini gabungan (slug, deleted_at), jadi slug milik provider
                // yang sudah dihapus memang boleh dipakai ulang. Tanpa klausa
                // ini validasi akan menolak sesuatu yang database izinkan.
                Rule::unique('storage_providers', 'slug')
                    ->ignore($model?->getKey())
                    ->whereNull('deleted_at'),
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

                if ($this->alreadyStored($model, $field)) {
                    continue;
                }

                $rules[$field] = $this->makeRequired($rules[$field] ?? ['string']);
            }

            $rules['driver'][] = $this->rejectMissingAdapter($driver);
        }

        return $rules;
    }

    /**
     * Field kredensial yang boleh dikirim kosong pada penyuntingan.
     *
     * Kosong berarti "jangan ubah", bukan "hapus" — penjagaan itu ada di
     * StorageProviderService::prepare().
     */
    private const KEPT_WHEN_BLANK = ['access_key', 'secret_key'];

    /**
     * Kredensial ini sudah tersimpan, jadi tidak perlu diketik ulang.
     *
     * Tanpa pengecualian ini, mengganti nama provider R2 akan menolak simpan
     * dengan "Driver Cloudflare R2 memerlukan secret key" — padahal form
     * sengaja tidak pernah menampilkan kembali secret yang tersimpan (7.2B).
     * Admin jadi harus menggali ulang kunci dari dashboard Cloudflare hanya
     * untuk memperbaiki satu salah tulis, dan penjagaan "kosong berarti jangan
     * ubah" di service tidak akan pernah bisa tercapai.
     *
     * Nilainya dibaca lewat getRawOriginal(), BUKAN lewat accessor biasa.
     * Kolom ini memakai cast `encrypted`, sehingga membacanya secara normal
     * akan mendekripsi — dan melempar DecryptException bila APP_KEY sudah
     * diganti. Di sini kita hanya perlu tahu apakah ada isinya, bukan apa
     * isinya, jadi ciphertext mentah sudah cukup dan tidak bisa gagal.
     */
    protected function alreadyStored(?Model $model, string $field): bool
    {
        if ($model === null || ! in_array($field, self::KEPT_WHEN_BLANK, true)) {
            return false;
        }

        return filled($model->getRawOriginal($field));
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
            'slug.unique'     => 'Slug ini sudah dipakai provider lain yang masih aktif. '
                                 .'Slug milik provider yang sudah dihapus boleh dipakai ulang.',
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

    /*
    |--------------------------------------------------------------------------
    | Ubah, hapus, pulihkan (Sprint 7.2C)
    |--------------------------------------------------------------------------
    */

    /**
     * Simpan perubahan provider.
     *
     * Sama seperti `store()`, versi bawaan tidak dipakai karena ia memanggil
     * `Model::update()` langsung dan dengan begitu melewati
     * StorageProviderService — tempat penjagaan "kredensial kosong berarti
     * jangan ubah" berada. Lewat jalur bawaan, mengganti nama provider akan
     * menimpa access_key dan secret_key dengan string kosong.
     *
     * `status` dan `is_default` tidak ikut ditulis, dan itu bukan kelalaian:
     * keduanya tidak ada di `rules()`, sehingga `validate()` tidak pernah
     * mengembalikannya dan `update()` tidak pernah menyentuhnya. Enable,
     * Disable, dan Set Default belum dibuat — provider aktif tidak boleh
     * diam-diam nonaktif hanya karena namanya disunting.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        $driver = StorageDriver::tryFrom((string) $request->input('driver'));

        $data = $request->validate(
            $this->rules($request, $provider),
            $this->validationMessages($driver)
        );

        // Checkbox yang tidak dicentang tidak ikut terkirim sama sekali.
        $data['use_path_style'] = $request->boolean('use_path_style');

        $updated = $this->service->update($provider, $data);

        return redirect()
            ->route('admin.storage.index')
            ->with('status', sprintf(
                'Storage provider "%s" diperbarui. Konfigurasi berubah, jadi '
                .'uji ulang dengan: php artisan storage:test %s',
                $updated->name,
                $updated->slug
            ));
    }

    /**
     * Hapus provider (soft delete).
     *
     * StorageProviderService menolak penghapusan provider default, karena
     * situs tanpa tujuan penyimpanan default membuat setiap upload berikutnya
     * gagal — dengan gejala yang muncul jauh dari sebabnya. Penolakan itu
     * ditangkap di sini dan ditampilkan sebagai pesan, bukan dibiarkan menjadi
     * halaman 500.
     */
    public function destroy(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        try {
            $this->service->delete($provider);
        } catch (StorageProviderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf(
            'Storage provider "%s" dipindahkan ke terhapus. Konfigurasi dan '
            .'kredensialnya masih tersimpan dan bisa dipulihkan lewat kotak '
            .'centang "Terhapus" di daftar.',
            $provider->name
        ));
    }

    /**
     * Pulihkan provider yang terhapus.
     *
     * Tidak memakai `restore()` bawaan karena ada satu penjagaan yang tidak
     * ada di sana: slug harus unik di antara baris hidup. Setelah provider
     * `r2` dihapus, slug itu bebas dipakai provider baru — dan bila itu
     * terjadi, memulihkan yang lama akan ditolak database dengan galat
     * integritas mentah. Lebih baik ditolak di sini, dengan sebab yang jelas
     * dan langkah yang bisa dikerjakan.
     */
    public function restore(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = StorageProvider::onlyTrashed()->findOrFail($id);

        // Query tanpa onlyTrashed/withTrashed hanya melihat baris hidup,
        // yang persis lingkup keunikan yang dijamin index database.
        $bentrok = StorageProvider::where('slug', $provider->slug)
            ->whereKeyNot($provider->getKey())
            ->exists();

        if ($bentrok) {
            return back()->with('error', sprintf(
                'Slug "%s" kini dipakai provider lain, jadi "%s" tidak bisa '
                .'dipulihkan. Ubah slug provider yang memakainya lebih dulu.',
                $provider->slug,
                $provider->name
            ));
        }

        $provider->restore();

        app(ActivityLogger::class)->log('dipulihkan', 'storage', $provider);

        return back()->with('status', sprintf(
            'Storage provider "%s" dipulihkan, tetap berstatus %s.',
            $provider->name,
            $provider->status->label()
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Enable, Disable, Set Default, Update Priority (Sprint 7.2D)
    |--------------------------------------------------------------------------
    |
    | Keempatnya mengarah ke StorageProviderService dan tidak menyentuh model
    | langsung. Seluruh penjagaan invarian ada di sana: provider setengah jadi
    | tidak boleh aktif, provider default tidak boleh dinonaktifkan, dan hanya
    | tepat satu baris boleh bertanda default.
    |
    | Setiap penolakan ditangkap dan ditampilkan sebagai pesan. Penjagaan yang
    | muncul sebagai halaman 500 akan dilaporkan sebagai bug, bukan dibaca
    | sebagai peringatan.
    */

    /**
     * Aktifkan provider.
     *
     * Service menolak bila field wajibnya kosong, adapter composer-nya belum
     * terpasang, atau masih ada nilai contoh yang belum diganti.
     */
    public function enable(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        try {
            $this->service->enable($provider);
        } catch (StorageProviderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf(
            'Storage provider "%s" diaktifkan. Uji koneksinya dengan: '
            .'php artisan storage:test %s',
            $provider->name,
            $provider->slug
        ));
    }

    /**
     * Nonaktifkan provider.
     *
     * Service menolak menonaktifkan provider default. Itu bukan kehati-hatian
     * berlebihan: provider default wajib aktif, jadi menonaktifkannya berarti
     * situs kehilangan tujuan penyimpanan yang bisa dipakai, dan kegagalannya
     * baru muncul saat ada berkas sungguhan yang hendak disimpan.
     */
    public function disable(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        try {
            $this->service->disable($provider);
        } catch (StorageProviderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf(
            'Storage provider "%s" dinonaktifkan dan keluar dari rantai '
            .'penyimpanan. Berkas yang sudah ada di sana tidak terhapus.',
            $provider->name
        ));
    }

    /**
     * Jadikan provider ini tujuan penyimpanan bawaan.
     *
     * Service mengerjakannya di dalam transaction dengan seluruh baris
     * terkunci, sehingga tanda default berpindah — tidak bertambah.
     */
    public function makeDefault(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        if ($provider->is_default) {
            return back()->with('status', sprintf(
                'Storage provider "%s" memang sudah menjadi default.',
                $provider->name
            ));
        }

        try {
            $this->service->makeDefault($provider);
        } catch (StorageProviderException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf(
            'Storage provider "%s" kini menjadi default. Unggahan baru masuk '
            .'ke sini; berkas yang sudah ada TIDAK dipindahkan.',
            $provider->name
        ));
    }

    /**
     * Perbarui prioritas beberapa provider sekaligus.
     *
     * Angka lebih kecil dicoba lebih dulu. Nilai dibatasi 0..65535 karena
     * kolomnya `unsignedSmallInteger` — tanpa batas atas, MySQL dalam mode
     * non-strict akan memotong nilainya diam-diam menjadi 65535, dan urutan
     * yang tersimpan berbeda dari yang diminta tanpa ada yang tahu.
     */
    public function updatePriority(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'priority'   => ['required', 'array', 'min:1'],
            'priority.*' => ['required', 'integer', 'min:0', 'max:65535'],
        ], [
            'priority.*.integer' => 'Priority harus berupa angka bulat.',
            'priority.*.min'     => 'Priority paling kecil 0.',
            'priority.*.max'     => 'Priority paling besar 65535.',
        ]);

        $jumlah = $this->service->reorder($data['priority']);

        if ($jumlah === 0) {
            return back()->with('status', 'Tidak ada prioritas yang berubah.');
        }

        return back()->with('status', sprintf(
            'Prioritas %d storage provider diperbarui. Angka lebih kecil '
            .'dicoba lebih dulu.',
            $jumlah
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Test Connection (Sprint 7.3)
    |--------------------------------------------------------------------------
    */

    /**
     * Uji koneksi ke satu provider.
     *
     * Mesinnya sudah ada sejak Sprint 7.1 dan dipakai `php artisan
     * storage:test`. Sprint ini hanya menyambungkannya ke tombol — tidak ada
     * logika pengujian yang ditulis ulang di sini.
     *
     * Yang diuji: tulis satu berkas kecil, baca ulang, lalu hapus. Ketiganya
     * diperlukan. Kredensial yang hanya punya izin tulis akan lolos kalau yang
     * diuji cuma tulis, lalu gagal saat berkas hendak dibaca pengguna.
     *
     * Berlaku untuk keenam provider berprotokol S3 (R2, Amazon S3, Backblaze
     * B2, Wasabi, MinIO, DigitalOcean Spaces) lewat jalur yang sama persis,
     * karena semuanya memakai driver Flysystem `s3` — yang berbeda hanya
     * endpoint, region, dan gaya path, dan itu sudah diurus DiskConfigFactory.
     *
     * Kegagalan BUKAN halaman 500. `StorageManager::test()` menangkap seluruh
     * Throwable dan mengembalikannya sebagai hasil, karena kegagalan koneksi
     * adalah jawaban yang sah dari sebuah tombol uji — bukan kerusakan
     * aplikasi.
     */
    public function test(int $id): RedirectResponse
    {
        /** @var StorageProvider $provider */
        $provider = $this->findOrFail($id);

        $result = $this->service->test($provider);

        // Panel hasil: menetap di halaman, tidak ikut hilang setelah 4 detik
        // seperti toast. Pesan galat penyimpanan bisa sepanjang satu paragraf
        // dan justru di situ petunjuknya.
        return back()->with('detail', [
            'ok'    => $result->success,
            'title' => sprintf('Test Connection: %s', $provider->name),
            'meta'  => $this->testMeta($provider, $result),

            // Pesan asli dari SDK, apa adanya. Kadang menyesatkan, tapi
            // kadang justru di situ satu-satunya petunjuk yang menentukan.
            'message' => $result->message,

            // Terjemahan ke penyebab yang paling mungkin. Menemani pesan
            // asli, bukan menggantikannya.
            'hint' => $result->hint(),
        ]);
    }

    /**
     * Baris keterangan di bawah judul panel hasil.
     */
    protected function testMeta(StorageProvider $provider, StorageTestResult $result): string
    {
        $bagian = [$provider->driver->label()];

        if ($waktu = $result->durationForHumans()) {
            $bagian[] = 'waktu respons '.$waktu;
        }

        $bagian[] = $result->success
            ? 'tulis, baca, dan hapus berhasil'
            : 'gagal sebelum siklus tulis-baca-hapus selesai';

        return implode(', ', $bagian).'.';
    }
}
