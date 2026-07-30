# Sprint 7.2A — Storage Manager (Read Only)

Selesai: 30 Juli 2026

Halaman admin untuk melihat seluruh storage provider yang tersimpan.
Tidak ada Create, Edit, Delete, Enable, Disable, Set Default, Update Priority,
Test Connection, maupun Upload. Pondasi Sprint 7.1 tidak diubah.

---

## Langkah setelah deploy

```
php artisan db:seed --class='Database\Seeders\RoleSeeder' --force
```

Izin baru `storage.view` ditambahkan ke daftar izin, dan barisnya belum ada di
database sampai `RoleSeeder` dijalankan ulang.

Halaman ini **tetap bisa dibuka tanpa langkah itu**, karena route dan menunya
menerima `setting.manage` sebagai alternatif — izin yang sudah dimiliki Super
Admin. Menjalankan `RoleSeeder` diperlukan hanya bila Anda ingin memberi peran
lain akses ke Storage Manager tanpa sekaligus memberi akses Pengaturan.

---

## Berkas yang dibuat

- `app/Http/Controllers/Admin/StorageController.php`

Satu berkas. Tidak ada view baru, tidak ada CSS baru, tidak ada route file baru.

## Berkas yang disunting

- `routes/web.php` — `GET /admin/storage` sebagai `admin.storage.index`
- `app/Providers/AuthServiceProvider.php` — izin `storage.view`
- `resources/views/web/layouts/admin.blade.php` — menu sidebar, dan dukungan
  `can` berupa array
- `resources/views/components/admin/cell.blade.php` — penanganan enum
- `resources/views/components/web/home/icon.blade.php` — ikon `database`
- `app/Http/Controllers/Admin/AdminCrudController.php` — hook `applyDefaultSort()`
- `tools/verify-consistency.py` — pemeriksaan ke-18

---

## Keputusan desain

### Tidak ada view baru: memakai `crud/index.blade.php` yang sudah ada

`StorageController` menurunkan `AdminCrudController`, sama seperti
`LogController` untuk daftar baca-saja. Seluruh pencarian, filter, urutan,
pagination, dan empty state datang dari sana. Controller ini hanya
mendeklarasikan konfigurasi — 10 metode, semuanya hook, nol logika daftar.

Konsekuensi yang menguntungkan: **tombol aksi hilang dengan sendirinya.**
`crud/index.blade.php` memeriksa `Route::has()` sebelum merender tombol Tambah,
Ubah, dan Hapus. Karena route tulis untuk modul ini tidak didaftarkan,
ketiganya tidak dirender tanpa satu baris kondisi pun ditulis khusus. Sifat
"read only" ditegakkan oleh ketiadaan route, bukan oleh view yang menyembunyikan
tombol yang sebenarnya berfungsi.

### Kredensial tidak ditampilkan dan tidak dicari

`access_key` dan `secret_key` tidak masuk daftar kolom maupun `searchable()`.
Keduanya tersimpan terenkripsi dan akan **terdekripsi begitu dibaca lewat
model** — menampilkannya di tabel berarti mengirim kredensial produksi ke dalam
HTML setiap kali halaman dibuka.

Mencari di dalamnya juga tidak akan pernah bekerja: `LIKE` di database
mencocokkan ciphertext, bukan isinya.

### Urutan bawaan: prioritas, bukan terbaru

`AdminCrudController` mengurutkan `latest()` untuk semua modul. Untuk storage
provider itu menyesatkan — urutan yang benar-benar dipakai `StorageManager`
saat memilih tujuan penyimpanan adalah `priority`. Daftar yang urutannya
berbeda dari perilaku sistem akan membingungkan orang yang sedang menelusuri
masalah.

Daripada menimpa `index()` seluruhnya (yang berarti menduplikasi ~40 baris
logika daftar), saya menambahkan hook `applyDefaultSort(Builder $query)` ke
base class. Nilai bawaannya tetap `latest()`, jadi tidak ada modul lain yang
berubah perilakunya. `StorageController` menimpanya menjadi `byPriority()`.

### Filter mengambil opsi dari enum

Opsi filter Driver dan Status dibangun dari `StorageDriver::options()` dan
`StorageStatus::options()`, bukan array yang ditulis ulang. Daftar filter jadi
tidak bisa melenceng dari nilai yang benar-benar tersimpan di kolom, dan
menambah provider ke-10 ke enum otomatis memunculkannya di filter.

### Kolom yang bisa diurutkan dipilih, bukan semua

Bawaan base class adalah "semua kolom bisa diurutkan". Untuk halaman ini
`bucket` dan `endpoint` dikecualikan: mengurutkan menurut nama bucket tidak ada
gunanya, dan hanya menambah tautan yang tidak berguna di kepala tabel.

### Izin ganda pada route

Route memakai `permission:storage.view,setting.manage` — middleware
`EnsureHasPermission` memakai `hasAnyPermission`, jadi salah satu cukup. Pola
ini sudah dipakai modul Pengguna (`permission:user.view,user.manage`).

Alasannya operasional: `storage.view` baru, sehingga barisnya belum ada di
database sampai `RoleSeeder` dijalankan. Tanpa alternatif, menu akan
tersembunyi dan halamannya 403 di server yang baru di-deploy — gejala yang
mudah disalahartikan sebagai bug.

Menu sidebar mengikuti semantik yang sama: `can` sekarang boleh berupa array,
dan salah satu izin sudah cukup.

---

## Bug yang ditemukan dan diperbaiki

### `x-admin.cell` fatal untuk kolom enum

`cell.blade.php` mencetak status dengan `ucfirst((string) $value)`, dan kolom
lain dengan `{{ $value }}`. Keduanya **melempar `Error`** bila nilainya enum —
`(string) $enum` tidak sah di PHP.

`StorageProvider` adalah model pertama di proyek ini yang cast kolom
tampilannya ke enum (`driver` dan `status`). Saya sudah memastikan tidak ada
model lain yang begitu, jadi ini bukan bug yang sudah aktif — tapi tanpa
perbaikan ini halaman Storage Manager **mati total**, bukan tampil aneh.

Cabang baru mengambil `label()` bila enum menyediakannya, kalau tidak nilai
mentahnya.

### Pewarnaan badge sengaja dibatasi pada enum

Rancangan pertama saya mencocokkan nilai status sebagai string, supaya `active`
tampil hijau dan `inactive` redup. Saya batalkan setelah memeriksa modul lain:
**`SubscriptionController` punya kolom status berisi string `'active'` juga**,
sehingga halaman Langganan ikut berubah warna — perubahan yang tidak diminta
sprint ini dan tidak diuji.

Pencocokan sekarang bertumpu pada nilai enum saja, yang `null` untuk string.
Diverifikasi: Langganan (`active`, `expired`), Drama (`completed`), dan Episode
(`draft`) semuanya tetap `.badge-status` persis seperti sebelumnya.

---

## Pemeriksaan baru: route di menu sidebar

Menu admin memanggil `route($item['route'])` dengan **variabel**, sedangkan
pemeriksaan route mati di Blade hanya mengenali literal `route('...')`. Artinya
seluruh array `$menu` selama ini tidak pernah diperiksa sama sekali.

Bobot kesalahannya tinggi: satu salah tulis di sana membuat setiap halaman
admin melempar `RouteNotFoundException`, karena layout-lah yang gagal —
seluruh panel mati, bukan satu menu.

Pemeriksaan ke-18 di `verify-consistency.py` kini memvalidasi ke-16 route di
menu itu. Diuji dengan sengaja mengubah satu nama route menjadi salah:
tertangkap, lalu dipulihkan.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       328 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    63 blade, 0 bermasalah
```

Self-audit tambahan yang dijalankan:

- 9 kolom sesuai spesifikasi dan berurutan
- semua kolom tampilan dan kolom sortable benar-benar ada di migration
- `access_key` dan `secret_key` tidak ada di kolom maupun pencarian
- `admin.storage.index` terdefinisi; ketujuh route tulis (`create`, `store`,
  `edit`, `update`, `destroy`, `restore`, `bulk`) terbukti **tidak** ada
- `StorageController` tidak menimpa satu pun metode tulis; satu-satunya
  sentuhan query adalah `$query->byPriority()`
- nilai enum cocok dengan yang ditulis seeder (lewat `->value`, bukan literal)
- modul status lama dirender identik

Dua "GAGAL" pertama pada self-audit ternyata cacat skrip audit saya sendiri:
`function bulk` cocok sebagai substring dengan `function bulkActions`, dan
`Builder $q` dengan `Builder $query`. Diulang dengan batas kata, hasilnya
bersih.

**Semua verifikasi ini statis.** Belum diuji di browser. Yang perlu dilihat
langsung setelah deploy:

- menu Storage Manager muncul di kelompok Sistem
- tabel menampilkan provider `local` dengan badge Aktif dan Default "Ya"
- badge Driver berbunyi "Penyimpanan Lokal", bukan `local`
- filter Driver dan Status menyaring dengan benar
- tidak ada tombol Tambah, Ubah, atau Hapus di halaman itu
- halaman Langganan tidak berubah tampilannya

---

## Belum dikerjakan (sengaja, di luar lingkup 7.2A)

- Create, Edit, Delete provider
- Enable, Disable, Set Default, Update Priority
- Test Connection dari panel (masih lewat `php artisan storage:test`)
- Kolom hasil test terakhir (`last_tested_at`, `last_test_status`) belum
  ditampilkan
- Upload, Telegram, thumbnail, subtitle
