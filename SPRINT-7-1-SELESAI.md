# Sprint 7.1 — Fondasi Multi Storage

Selesai: 30 Juli 2026

Tujuan sprint: situs tidak lagi terikat pada satu storage provider. Sprint ini
**hanya membangun pondasi** — belum ada satu pun jalur upload, tidak ada
integrasi Telegram, tidak ada thumbnail atau subtitle.

---

## Langkah wajib sebelum dipakai

Enam dari sembilan provider memakai protokol S3, dan adapternya **belum
terpasang** di `vendor/`. Laravel 12 tidak membawa adapter cloud apa pun secara
bawaan.

```
composer require league/flysystem-aws-s3-v3
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\StorageProviderSeeder
php artisan storage:test
```

`composer.json` sengaja **tidak** saya ubah sendiri. Menambah baris `require`
tanpa memperbarui `composer.lock` membuat `composer install` di `deploy.sh`
berhenti dengan galat, dan saya tidak punya composer di lingkungan kerja saya
untuk memperbarui lock-nya. Jalankan `composer require` di lokal, lalu commit
`composer.json` dan `composer.lock` bersamaan.

Tanpa paket itu, provider S3 tetap tersimpan di database tetapi ditolak saat
diaktifkan, dengan pesan yang menyebut perintah composer di atas.

---

## Provider yang dikenali

| Slug | Nama | Driver Flysystem | Paket composer |
|---|---|---|---|
| `local` | Penyimpanan Lokal | `local` | — (bawaan) |
| `s3` | Amazon S3 | `s3` | `league/flysystem-aws-s3-v3` |
| `r2` | Cloudflare R2 | `s3` | `league/flysystem-aws-s3-v3` |
| `b2` | Backblaze B2 | `s3` | `league/flysystem-aws-s3-v3` |
| `wasabi` | Wasabi | `s3` | `league/flysystem-aws-s3-v3` |
| `spaces` | DigitalOcean Spaces | `s3` | `league/flysystem-aws-s3-v3` |
| `minio` | MinIO | `s3` | `league/flysystem-aws-s3-v3` |
| `gcs` | Google Cloud Storage | `gcs` | `league/flysystem-google-cloud-storage` |
| `azure` | Azure Blob Storage | `azure` | `league/flysystem-azure-blob-storage` |

GCS dan Azure hanya kerangka. Keduanya tidak memakai pasangan key/secret
seperti provider lain, jadi jalur kredensialnya belum diselesaikan. Keduanya
ditandai jelas di kode sebagai belum diuji.

---

## Berkas yang dibuat

**Enum**

- `app/Enums/StorageDriver.php` — sembilan provider, pemetaan ke driver
  Flysystem, field wajib per provider, region bawaan, nama paket composer, dan
  nama kelas adapter untuk memeriksa apakah paketnya benar-benar terpasang.
- `app/Enums/StorageStatus.php` — `active` / `inactive`.

**Database**

- `database/migrations/2026_07_30_090000_create_storage_providers_table.php`
- `database/seeders/StorageProviderSeeder.php`

**Model**

- `app/Models/StorageProvider.php`

**Repository**

- `app/Repositories/Contracts/StorageProviderRepositoryInterface.php`
- `app/Repositories/StorageProviderRepository.php`

**Storage Manager**

- `app/Services/Storage/Contracts/StorageManagerInterface.php`
- `app/Services/Storage/StorageManager.php`
- `app/Services/Storage/DiskConfigFactory.php`
- `app/Services/Storage/StorageTestResult.php`
- `app/Services/Storage/Exceptions/StorageProviderException.php`

**Service**

- `app/Services/StorageProviderService.php`

**Config & perintah**

- `config/storage.php`
- `app/Console/Commands/StorageTest.php` — `php artisan storage:test`

**Disunting**

- `app/Providers/AppServiceProvider.php` — binding repository, dan
  `StorageManagerInterface` sebagai singleton
- `database/seeders/DatabaseSeeder.php` — memanggil `StorageProviderSeeder`
- `.env.example` — blok Multi Storage
- `tools/verify-consistency.py` — dua perbaikan, lihat bagian terakhir
- `tools/check-php-structure.py` — satu perbaikan, lihat bagian terakhir

---

## Keputusan desain

### Disk dibangun dari database, bukan config/filesystems.php

`config/filesystems.php` tidak disentuh sama sekali. Disk dibangun saat
dibutuhkan lewat `Storage::build()` dari baris `storage_providers`.

Alasannya: `config:cache` membekukan isi config sampai deploy berikutnya.
Kalau daftar provider ada di config, menambah provider berarti deploy. Dengan
di database, admin bisa menambah provider dan langsung mengujinya.

### Kredensial terenkripsi, dan itu punya konsekuensi

`access_key` dan `secret_key` memakai cast `encrypted`, sehingga terenkripsi
dengan `APP_KEY` sebelum masuk database. Kolomnya `TEXT`, bukan `VARCHAR`,
karena hasil enkripsi jauh lebih panjang dari nilai aslinya. Keduanya juga
masuk `$hidden` agar tidak pernah ikut serialisasi JSON, log, atau `dd()`.

**Konsekuensi yang harus dicatat:** mengganti `APP_KEY` membuat seluruh
kredensial storage tidak bisa dibaca lagi dan harus dimasukkan ulang lewat
panel admin.

### Kredensial kosong berarti "jangan ubah"

Form admin tidak akan pernah menampilkan kembali secret yang tersimpan, jadi
field itu selalu terkirim kosong ketika admin cuma mengganti nama provider.
`StorageProviderService::prepare()` membuang key kosong dari data sebelum
`update()`, supaya satu penyuntingan sepele tidak menghapus kredensial.

### Provider tidak bisa aktif kalau setengah jadi

`enable()` menolak provider yang field wajibnya kosong atau adapternya belum
terpasang. Mengaktifkan provider setengah jadi berarti memasukkannya ke rantai
fallback, dan kegagalannya baru terlihat saat ada berkas sungguhan yang hendak
disimpan — jauh dari sebabnya.

Aturan serupa menjaga status default: default tidak bisa dihapus, tidak bisa
dinonaktifkan, dan tidak bisa disetel ke provider yang tidak aktif. Situs
tanpa provider default berarti setiap upload berikutnya gagal.

### Test Connection menulis, membaca, lalu menghapus

Ketiganya diperlukan. Kredensial yang hanya punya izin tulis akan lolos kalau
yang diuji cuma tulis, lalu gagal saat berkas hendak dibaca pengguna. Berkas
uji diberi nama acak supaya dua test yang berjalan bersamaan tidak saling
menghapus, dan dihapus di blok `finally` supaya bucket tidak menumpuk sampah
walaupun pembacaan gagal.

Test Connection **tidak pernah** melempar exception. Kegagalan adalah hasil
yang sah dan harus bisa ditampilkan di panel admin, bukan halaman 500.
Provider nonaktif tetap bisa diuji — justru itu gunanya.

### Angka priority kecil dicoba lebih dulu

Provider lokal di-seed dengan priority 900: pilihan terakhir. Disk VPS tidak
dimaksudkan menampung berkas video.

### Seeder hanya memasang provider lokal

Tidak ada baris R2 atau Wasabi di seeder. Baris provider awan tanpa kredensial
sungguhan hanya akan tampak siap dipakai padahal tidak — persis jenis data
karangan yang dihindari proyek ini. Kredensial contoh yang ikut ter-commit juga
masalah keamanan. Provider awan ditambahkan lewat panel admin, oleh orang yang
memegang kuncinya.

Seeder aman dijalankan ulang: kalau baris `local` sudah ada, kolom `status`,
`priority`, dan `is_default` **tidak** disentuh, karena itu keputusan operator.

### Visibility bawaan `private`

Bucket video yang terbuka untuk umum berarti siapa pun yang menebak URL bisa
mengunduh seluruh katalog tanpa membayar. Provider lokal adalah pengecualian
(`public`) karena memang melayani `/storage`.

### Tidak ada penyesuaian spekulatif per provider

R2 dan B2 memang menyimpang dari S3 Amazon. Saya sempat menulis penyesuaian
konfigurasi untuk keduanya, lalu membuangnya: nama kunci yang benar berbeda
antar versi AWS SDK, saya tidak punya PHP untuk mengujinya, dan kunci yang
salah nama bisa menggagalkan pembuatan klien S3 — membuat provider yang tadinya
jalan justru mati.

Penyesuaian per provider diisi lewat kolom `options` (JSON) pada barisnya.
Nilai di kolom itu ditimpakan paling akhir, sehingga bisa dicoba dan
dibatalkan tanpa deploy.

---

## Cara memakai dari kode

Kode lain **tidak boleh** memanggil `Storage::disk('s3')` langsung. Semua
akses lewat kontrak:

```php
use App\Services\Storage\Contracts\StorageManagerInterface;

public function __construct(
    protected StorageManagerInterface $storage
) {}

// Disk provider default
$this->storage->default()->put('video/ep-1.mp4', $contents);

// Disk provider tertentu
$this->storage->disk('r2')->get('video/ep-1.mp4');

// Urutan percobaan untuk failover (dipakai sprint upload nanti)
foreach ($this->storage->chain() as $provider) {
    // ...
}
```

Setelah konfigurasi provider berubah di permintaan yang sama, panggil
`forget($slug)` — disk yang sudah dibangun dimemoisasi dan tidak tahu
konfigurasinya sudah berubah.

---

## Perbaikan alat verifikasi

Dua bug ditemukan di alat verifikasi sendiri saat sprint ini dikerjakan.

### `verify-consistency.py` — pembatas blok migration tidak pernah bekerja

Fungsi `cols_of()` membandingkan `mig[i] == '{{'`: satu karakter dengan string
dua karakter, yang tidak pernah cocok. Akibatnya `depth` tidak pernah turun,
blok membentang sampai akhir berkas, dan pemeriksaan `$fillable` sebenarnya
hanya menanyakan *"apakah nama kolom ini muncul di migration mana pun"* —
bukan *"apakah kolom ini ada di tabel ini"*.

Sudah diperbaiki, dan dibuktikan bekerja: `cols_of('storage_providers')` kini
mengembalikan 21 kolom miliknya sendiri, dan kolom tabel lain (`synopsis`,
`episode_number`, `telegram_id`) tidak lagi ikut terbaca.

Daftar model yang diperiksa juga diperluas dari 11 menjadi 18 pasangan —
`Media`, `Review`, `Setting`, `Notification`, `ActivityLog`, `Role`,
`Permission`, dan `StorageProvider` sebelumnya tidak pernah diperiksa sama
sekali. Semuanya lolos.

### `check-php-structure.py` — menuduh kode yang benar

Pemeriksaan paritas tanda kutip menghitung tanda kutip pada sumber mentah,
sehingga apostrof di dalam komentar (`the application's name`) dan escape di
ujung string (`'/\\'`) dilaporkan sebagai kesalahan. Delapan berkas sah gagal
karenanya — termasuk `config/app.php` bawaan Laravel.

Alat yang menuduh kode benar lebih berbahaya daripada tidak ada alat, karena
hasilnya jadi terbiasa diabaikan. Pemeriksaan itu dibuang, diganti deteksi
string tak tertutup di dalam `strip()`, yang sudah menelusuri string sambil
menghormati escape. Diuji dengan berkas rusak buatan: string tak tertutup dan
kurung tak seimbang tetap tertangkap, sementara berkas yang memuat `'/\\'` dan
apostrof di komentar kini lolos.

### Pemeriksaan baru: kelengkapan `match` enum

`match` tanpa arm `default` melempar `UnhandledMatchError` saat dieksekusi
kalau menerima nilai yang tidak tercantum. Menambah satu case ke
`StorageDriver` tanpa memperbarui `requiredFields()` karena itu menghasilkan
kesalahan yang tidak terlihat sampai jalur kode itu dijalankan — untuk storage
provider, bisa berarti baru terlihat di produksi.

Pemeriksaan ke-17 di `verify-consistency.py` kini memastikan setiap `match`
tanpa `default` di `app/Enums/` menangani seluruh case. Diuji dengan enum
buatan: case yang terlewat tertangkap, arm bergabung (`self::A, self::B =>`)
dan metode yang punya `default` tidak salah dilaporkan.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        17/17 pemeriksaan lolos
python tools/check-php-structure.py       327 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    63 blade, 0 bermasalah
```

Binding repository: 26/26 interface ter-bind.

**Semua pemeriksaan di atas statis. Tidak ada PHP yang dijalankan** di
lingkungan tempat sprint ini ditulis.

Terbukti di server pada 30 Juli 2026, setelah deploy:

- `php artisan migrate` membuat tabel `storage_providers`
- `StorageProviderSeeder` memasang provider `local`
- `php artisan storage:test` melaporkan provider lokal OK — artinya
  `Storage::build()` menghasilkan disk yang berfungsi, dan siklus tulis, baca,
  hapus di Test Connection berjalan utuh

**Masih belum terbukti**, karena belum ada provider awan yang ditambahkan:

- cast `encrypted` bolak-balik pada kolom `TEXT` (belum ada kredensial tersimpan)
- konfigurasi disk untuk driver S3, GCS, dan Azure
- rantai fallback `chain()` dengan lebih dari satu provider

Uji ulang `php artisan storage:test` setelah provider awan pertama ditambahkan.

---

## Belum dikerjakan (sengaja)

Di luar lingkup Sprint 7.1, menunggu sprint berikutnya:

- Panel admin untuk storage provider (CRUD, tombol Test Connection,
  seret-lepas prioritas)
- Route dan permission untuk modul storage
- Router upload dengan failover memakai `chain()`
- Upload video, thumbnail, subtitle
- Integrasi Telegram
- Migrasi berkas antar provider
- Presigned URL untuk berkas `private`
