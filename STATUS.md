# DramaVerse ID — Status Proyek

**Berkas ini untuk dibaca lebih dulu saat memulai sesi baru.**
Tempel isinya, atau cukup minta: *"baca STATUS.md di folder ini."*

Terakhir diperbarui: 30 Juli 2026

---

## Ringkasan

Platform streaming drama Asia privat. Autentikasi hanya lewat Telegram —
tidak ada login email untuk pengguna biasa. Panel admin memakai email +
kata sandi.

| Hal | Nilai |
|---|---|
| Live di | https://dracinverse.cloud |
| Repo | github.com/brian-dev1/dramaverse-id (branch `main`) |
| Folder lokal | `C:\ProjectDrama\dramaverse-id` |
| Folder VPS | `/var/www/dramaverse` |
| VPS | Ubuntu 24.04, `203.194.112.10` |
| Bot Telegram | @DracinVersee_Bot |
| Stack | Laravel 12, PHP 8.3, MySQL, Redis, Blade, Vite, Nginx |

---

## Yang sudah selesai

### Sprint 0 — Audit
Menemukan tiga blocker: 6 migration inti kosong, lapisan API tidak
terjangkau, 14 nama route mati. Laporan: `AUDIT-SPRINT-0.md`.

### Sprint 1 — Fondasi
Migration ditulis ulang, relasi genre jadi pivot `drama_genre`, routing
disusun ulang, 76 komponen yatim dikarantina ke `_staging/`.
Detail: `SPRINT-1-SELESAI.md`.

### Sprint 2 — Kejujuran data & layout
Data dummy dipisah ke `Demo\DemoSeeder`, empty state di semua halaman,
bug `.section` menimpa `.section-pad` diperbaiki, emoji diganti SVG.
Detail: `SPRINT-2-SELESAI.md`.

### Sprint 6 — Panel admin (3 bagian + pelengkap)
CRUD lengkap katalog, dashboard + analytics + report, membership,
subscription, pengguna, Telegram broadcast, settings, role-permission,
resize gambar, ekspor XLSX, batch episode, seret-lepas, rate limit.
Detail: `SPRINT-6-BAGIAN-1.md`, `SPRINT-6-BAGIAN-3.md`,
`SPRINT-6-PELENGKAP.md`.

### Sprint 7.1 — Fondasi Multi Storage
Tabel `storage_providers`, enum `StorageDriver` (9 provider), StorageManager
yang membangun disk dari database lewat `Storage::build()`, DiskConfigFactory,
repository, service, Test Connection, dan `php artisan storage:test`.
Kredensial terenkripsi dengan `APP_KEY`. Belum ada jalur upload sama sekali.
Detail: `SPRINT-7-1-SELESAI.md`.

**Langkah wajib sebelum multi storage bisa dipakai:**
```
composer require league/flysystem-aws-s3-v3
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\StorageProviderSeeder
php artisan storage:test
```
Enam dari sembilan provider memakai protokol S3 dan adapternya belum ada di
`vendor/`. `composer.json` sengaja tidak diubah dari sesi asisten karena
`composer.lock` tidak bisa diperbarui tanpa composer — ubah lewat
`composer require` di lokal supaya lock ikut terbarui, kalau tidak
`composer install` di `deploy.sh` akan berhenti dengan galat.

### Sprint 7.2A — Storage Manager (baca-saja)
Halaman `/admin/storage` + menu sidebar. Daftar provider dengan pencarian,
filter driver dan status, pagination, empty state, badge status. Belum ada
Create/Edit/Delete/Enable/Disable/Default/Priority/Test Connection.
Memakai `AdminCrudController` yang sudah ada — nol view baru, nol CSS baru.
Detail: `SPRINT-7-2A-SELESAI.md`.

**Langkah opsional setelah deploy:**
```
php artisan db:seed --class='Database\Seeders\RoleSeeder' --force
```
Izin `storage.view` baru. Halaman tetap terbuka tanpa langkah ini karena
route dan menunya juga menerima `setting.manage`; seeder hanya diperlukan
bila ingin memberi peran lain akses storage tanpa akses Pengaturan.

### Sprint 7.2B — Create Storage Provider
Form tambah provider, validasi sadar-driver (field wajib diturunkan dari
`StorageDriver::requiredFields()`), simpan lewat `StorageProviderService`,
redirect, toast. Provider baru selalu dibuat **nonaktif** dan tidak jadi
default — aktivasi menunggu Test Connection di sprint berikutnya.
Kredensial ditambahkan ke `dontFlash` supaya tidak bocor ke session dan HTML
saat validasi gagal. Detail: `SPRINT-7-2B-SELESAI.md`.

### Sprint 7.2C — Edit & Delete
Ubah dan hapus provider, keduanya lewat `StorageProviderService`. **Soft delete
dibuat tersedia** (`deleted_at`), beserta pemulihannya — menghapus provider
menghilangkan kredensial dan pemetaan ke bucket, sedangkan berkas di bucket
tetap ada, jadi kekeliruan harus bisa dibatalkan. Unique slug dipindah ke
gabungan `(slug, deleted_at)` supaya slug bisa dipakai ulang setelah dihapus.
Toast `session('error')` baru untuk penolakan yang bukan kesalahan isian.
Belum ada Enable/Disable/Set Default/Priority/Test Connection/force delete.
Detail: `SPRINT-7-2C-SELESAI.md`.

### Sprint 7.2D — Enable, Disable, Set Default, Update Priority
Empat aksi di Storage Manager. Invarian **tepat satu default** dijaga dengan
transaction *plus* `lockAll()` (`SELECT ... FOR UPDATE` seluruh baris):
transaction sendirian tidak cukup, karena dua permintaan bersamaan bisa
sama-sama membersihkan flag sebelum salah satunya commit. Penjagaan di
`disable()`, `delete()`, dan `makeDefault()` dipindah ke DALAM kunci —
diperiksa di luar kunci, hasilnya bisa kedaluwarsa dan meninggalkan provider
default yang nonaktif atau terhapus. Update Priority: satu formulir untuk
semua baris, hanya nilai yang benar-benar berubah yang ditulis.
Belum ada Test Connection dari panel dan hapus permanen.
Detail: `SPRINT-7-2D-SELESAI.md`.

### Sprint 7.3 — Connection Test
Tombol Test Connection di panel, untuk keenam provider S3 (R2, Amazon S3,
Backblaze B2, Wasabi, MinIO, Spaces) lewat jalur yang sama. Mesinnya sudah ada
sejak 7.1 — sprint ini menyambungkannya. Hasil ditampilkan di **panel yang
menetap**, bukan toast: toast hilang setelah 4 detik, terlalu cepat untuk pesan
galat SDK yang panjangnya bisa satu paragraf. Durasi kini **disimpan**
(`last_test_duration_ms`), jadi Response Time tidak hilang saat halaman dimuat
ulang. Kolom baru "Uji Terakhir" di tabel.
Detail: `SPRINT-7-3-SELESAI.md`.

### Sprint 7.4 — Storage Engine (core upload)
Pusat seluruh operasi berkas: upload, delete, replace, rename, move, copy,
URL publik, temporary URL, metadata. Mode Auto (provider default) dan Manual
(per id/slug). `StorageEngineInterface` adalah satu-satunya pintu — controller
tidak boleh menyentuh `Storage`. Enum `StorageCollection` menyediakan skema
alamat 8 koleksi (episode, thumbnail, subtitle, poster, cover, banner, avatar,
asset) beserta ekstensi, batas ukuran, dan visibility-nya.
Belum ada load balancing, failover, queue, retry, dan modul upload apa pun.
Detail: `SPRINT-7-4-SELESAI.md`.

**Modul yang memakai engine WAJIB menyimpan `provider_id` bersama
`object_key`.** Menyimpan key saja hanya benar sampai provider default
dipindah — sesudahnya berkas dicari di bucket yang salah, dan gejalanya
"berkas hilang" tanpa jejak.

**Cara membuktikannya jalan:**
```
php artisan storage:smoke              # mode Auto
php artisan storage:smoke local        # provider tertentu
```
Menjalankan satu siklus penuh lewat engine, termasuk dua penjagaan keamanan.

**Angka saat ini:** 139 route, 18 controller admin, 19 view admin,
32 migration, 11 middleware, 197 kelas CSS, 26 interface repository.

---

## Yang BELUM dikerjakan

Sprint 3, 4, 5 dari rencana awal sebagian sudah tercakup Sprint 1, tapi
beberapa masih dangkal:

### Prioritas tinggi
- **Halaman detail drama** — sudah render, tapi komponen cast, galeri,
  ulasan, rating, trailer masih di `_staging/components/drama/`
- **Pemutar episode** — sudah jalan, tapi auto-next, kualitas, dan
  subtitle belum ada. Komponen di `_staging/components/player/`
- **Pencarian realtime** — endpoint `/api/v1/search` sudah ada, tapi
  belum tersambung ke UI. 13 komponen di `_staging/components/search/`

### Prioritas sedang
- **Halaman profil, membership, about, contact** — sudah render sederhana;
  komponen kaya menunggu di `_staging/`
- **Notifikasi** — tabel dan halaman ada, tapi belum ada yang membuat
  notifikasi saat episode baru terbit
- **Continue watching di pemutar** — progres tersimpan, tapi belum
  otomatis melanjutkan

### Bug diketahui, belum diperbaiki
- **`STORAGE_TIMEOUT` tidak berpengaruh.** Ada di `config/storage.php` dan
  `.env.example`, tapi tidak ada kode yang membacanya — belum disambungkan ke
  klien S3, jadi yang berlaku batas waktu bawaan AWS SDK. Sudah diberi catatan
  di kedua berkas, dan `hint()` tidak lagi menyuruh menaikkannya. Menyambungkan
  lewat kunci `http` pada config disk harus diuji langsung di server: kunci
  yang salah mematikan seluruh provider S3 sekaligus.
- **Form bersarang di `crud/index.blade.php`.** Saat sebuah modul punya aksi
  massal, form bulk membungkus tabel — sehingga form tombol Hapus di dalam
  baris jadi bersarang. Parser HTML membuang tag `<form>` bersarang, jadi
  tombol Hapus mengirim ke `admin.<modul>.bulk`, bukan `.destroy`.
  Terdampak: drama, episode, genre, country, banner, membership, subscription,
  user. Tidak terdampak: storage, logs, role (aksi massalnya kosong).
  Perbaikannya mekanis — beri form bulk sebuah `id`, tutup tanpa melingkupi
  tabel, tambahkan `form="bulk-form"` pada kotak centang. Teknik yang sama
  sudah dipakai editor prioritas di 7.2D. **Uji di browser sebelum dan
  sesudah.**

### Lanjutan Multi Storage (Sprint 7.5 dan seterusnya)
- **`Admin\MediaService` masih melewati multi-storage.** Menulis dengan
  `storeAs(..., 'public')` dan `Storage::disk('public')` yang dipatok di kode,
  jadi poster, cover, thumbnail, banner, dan logo situs tidak pernah sampai ke
  provider awan meski R2 sudah aktif dan default. Pemanggilnya: Banner, Drama,
  Episode, Setting controller. Direktori di `StorageCollection` sudah sengaja
  disamakan dengan peta folder `MediaService`, jadi letak berkas lama tidak
  berubah saat dipindahkan. **Yang perlu diputuskan:**
  `ImageProcessor::optimise()` butuh path absolut di disk lokal, yang tidak
  mungkin untuk berkas yang langsung ditulis ke bucket awan — urutannya harus
  dibalik: perkecil dulu di berkas sementara, baru unggah.
- **Load balancing dan failover** — `StorageManager::chain()` sudah menyiapkan
  urutannya, tapi engine sengaja selalu memakai satu provider dan gagal
  terang-terangan. Berpindah diam-diam akan menyebarkan berkas satu modul ke
  beberapa bucket tanpa ada yang memutuskan begitu.
- **Jaminan satu-default di tingkat database** — kolom generated + unique
  index. Kandidat pertama, tapi harus dijalankan di server yang bisa langsung
  diuji: sintaks kolom generated berbeda antar versi MySQL, dan migration yang
  gagal menghentikan `deploy.sh` di langkah migrate.
- **Sambungkan `STORAGE_TIMEOUT`** ke klien S3 lewat kunci `http`. Sekarang
  waktu yang tepat: tombol Test Connection membuat akibatnya langsung terlihat.
- **Test Connection massal** dari panel (satuan sudah ada; semua sekaligus
  masih lewat `php artisan storage:test`)
- **Test Connection di antrean** — sekarang sinkron, jadi provider yang lambat
  menahan permintaan admin sampai SDK menyerah
- **Hapus permanen** — baris terhapus kini hanya bisa dipulihkan atau dibiarkan
- **Test Connection dari panel** — sudah ada di `php artisan storage:test`
- **Kolom hasil test terakhir** di tabel (`last_tested_at`, `last_test_status`)
- **Router upload dengan failover** — memakai `StorageManager::chain()`
- **Upload video, thumbnail, subtitle** ke provider terpilih
- **Presigned URL** untuk berkas `private`
- **Migrasi berkas antar provider**
- **GCS dan Azure** — baru kerangka, jalur kredensialnya belum selesai

### Belum ada sama sekali
- PDF otomatis dari PHP (sekarang lewat dialog cetak peramban)
- Impor data dari CSV/Excel (hanya ekspor)
- Notifikasi in-app untuk admin
- Halaman kelola izin terpisah (izin dikelola dari dalam form peran)
- Log masuk terpisah (semua di satu tabel `activity_logs`)

---

## Cara kerja yang berlaku

1. **Audit dulu, baru tulis kode.** Jangan menganggap kode lama benar.
2. **Jangan buat data karangan.** Halaman kosong yang jujur lebih baik
   daripada katalog berisi judul palsu.
3. **Tidak ada emoji, simbol teks, atau SVG inline.** Semua ikon lewat
   `<x-web.home.icon>`. Emoji bendera tidak dirender di Windows.
4. **Tidak ada dead link.** Setiap route punya controller dan view.
   Tombol yang route-nya tidak ada tidak boleh dirender.
5. **Jalankan alat verifikasi sebelum menyatakan selesai.**
6. **Selalu akhiri dengan perintah yang harus dijalankan**, dipisah jelas
   antara PowerShell di PC dan SSH di VPS. Lihat bagian di bawah.

---

## Perintah penutup setiap pekerjaan

Asisten WAJIB mengakhiri setiap sprint dengan dua blok ini, diisi sesuai
pekerjaan yang baru selesai. Jangan digabung — perintah yang dijalankan di
mesin yang salah sudah pernah menimbulkan commit liar di VPS.

| Mesin | Tempat | Perannya |
|---|---|---|
| **PC** | PowerShell di `C:\ProjectDrama\dramaverse-id` | menulis kode, commit, push |
| **VPS** | SSH `root@203.194.112.10`, `/var/www/dramaverse` | hanya menarik kode. **Jangan pernah commit di sini** |

**Blok 1 — di PC (PowerShell):**
```powershell
git add -A
git commit -m "<pesan sprint>"
git push origin main
```

**Blok 2 — di VPS (SSH):**
```bash
cd /var/www/dramaverse
bash deploy.sh
```

Lalu sebutkan **langkah tambahan hanya bila memang perlu**, jangan
ditempelkan setiap kali:

- `composer require <paket>` → di **PC**, supaya `composer.lock` ikut
  terbarui dan `composer install` di `deploy.sh` tidak berhenti
- `php artisan db:seed --class='Database\Seeders\RoleSeeder' --force` → di
  **VPS**, hanya bila ada izin baru di `AuthServiceProvider`
- `php artisan db:seed --class='Database\Seeders\StorageProviderSeeder' --force`
  → di **VPS**, hanya bila ada data referensi baru
- `php artisan storage:test` → di **VPS**, setiap kali storage disentuh

Tutup dengan daftar singkat **apa yang harus dilihat di browser**, karena
seluruh alat verifikasi proyek ini statis dan tidak pernah merender apa pun.

---

## Alat verifikasi

```bash
python tools/verify-consistency.py       # 18 pemeriksaan
python tools/check-blade-directives.py resources/views/**/*.blade.php
python tools/check-css-coverage.py
python tools/check-php-structure.py app/**/*.php config/*.php database/**/*.php
```

`verify-consistency.py` memeriksa: route mati di Blade dan PHP, controller,
view, komponen, layout, `$fillable` vs migration, urutan foreign key,
binding repository, PSR-4, import CSS, kolom tanggal, form + CSRF, href
buntu, emoji, kelengkapan `match` enum, dan route di menu sidebar admin.

**Alat verifikasinya sendiri pernah salah.** Dua bug diperbaiki di Sprint 7.1:
pembatas blok migration di `cols_of()` tidak pernah bekerja (sehingga
pemeriksaan `$fillable` jauh lebih longgar dari yang terlihat), dan
pemeriksaan paritas tanda kutip di `check-php-structure.py` menuduh delapan
berkas sah — termasuk `config/app.php` bawaan Laravel. Perlakukan alat ini
seperti kode lain: bisa salah, dan perlu diaudit.

**Penting:** semua pemeriksaan ini **statis**. Tidak menjalankan PHP.
Beberapa kesalahan hanya muncul saat dieksekusi — rekam jejak sprint
sebelumnya membuktikan itu.

---

## Perintah rutin

**Lokal (Windows, PowerShell di folder project):**
```
php artisan serve
npm run dev
git add -A && git commit -m "..." && git push origin main
```

**VPS (setelah `ssh root@203.194.112.10`):**
```bash
cd /var/www/dramaverse
bash deploy.sh
```

`deploy.sh` menjalankan: pull, composer, npm build, migrate, cache,
izin berkas, restart worker.

**Jangan pernah** menjalankan `migrate:fresh` di VPS setelah ada data
pengguna sungguhan.

---

## Hal yang mudah terlupa

- `php artisan config:cache` setiap kali `.env` berubah
- `composer dump-autoload` setelah menambah helper di `autoload.files`
- `php artisan storage:link` supaya gambar unggahan tampil
- Worker antrean harus jalan agar broadcast Telegram terkirim
- `RoleSeeder` perlu dijalankan sekali agar peran dan izin terisi

---

## Catatan jujur soal batasan asisten

PHP tidak tersedia di lingkungan tempat saya bekerja. Artinya saya
**tidak pernah menjalankan** `php artisan route:list`, tidak pernah
merender satu halaman pun, dan tidak pernah mengirim satu form pun.

Selama pengerjaan, beberapa kesalahan lolos dari pemeriksaan statis dan
baru muncul saat dieksekusi:

- Kolom `timestamp` tanpa `nullable` (MySQL menolak saat migrate)
- Seeder yang bergantung pada route cache
- Urutan `optimize:clear` yang keliru di panduan
- `composer.json` merujuk berkas yang tidak ada

Semua sudah diperbaiki dan pemeriksaannya ditambahkan, tapi kelas
kesalahan lain tetap mungkin ada. **Selalu uji di browser setelah
deploy**, dan kirim pesan galat apa adanya.
