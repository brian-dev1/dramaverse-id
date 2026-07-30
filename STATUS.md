# DramaVerse ID — Status Proyek

**Berkas ini untuk dibaca lebih dulu saat memulai sesi baru.**

Terakhir diperbarui: 31 Juli 2026

---

## Memulai sesi baru

Kalimat pembuka yang cukup, tanpa perlu menjelaskan apa pun lagi:

> Baca `STATUS.md` di folder ini sampai habis, lalu ikuti seluruh aturan di
> dalamnya. Sesudah itu tunggu spesifikasi sprint dari saya.

Yang WAJIB dilakukan asisten sebelum menulis satu baris kode:

1. Baca `STATUS.md` **sampai habis** — termasuk "Bug diketahui" dan
   "Batasan asisten" di bagian bawah.
2. Baca berkas `SPRINT-*-SELESAI.md` yang relevan dengan pekerjaan berikutnya.
   Sprint terakhir yang selesai adalah **7.9** (`SPRINT-7-8-7-9-SELESAI.md`).
   **Phase 7 selesai.** Berikutnya Phase 8 — Telegram Integration.
3. Jalankan keempat alat verifikasi lebih dulu, untuk tahu keadaan awal yang
   bersih. Kalau ada yang GAGAL sejak awal, laporkan sebelum menambah apa pun.
4. **Periksa daftar berkas ini lagi sebelum menulis dokumen apa pun.**
   Di sesi 7.8 pembacaan folder di awal mengembalikan keadaan yang sudah basi:
   `SPRINT-7-7-SELESAI.md` tidak muncul di daftar dan `STATUS.md` yang terbaca
   adalah versi sebelum diperbarui, sehingga asisten melaporkan dokumen 7.7
   hilang padahal ada. Tidak ada yang tertimpa — penulisannya ditolak karena
   berkasnya sudah ada — tetapi satu pertanyaan ke Anda jadi berdiri di atas
   premis yang keliru.

Yang WAJIB dilakukan asisten sebelum menyatakan selesai:

1. Jalankan keempat alat verifikasi lagi — semuanya harus lolos.
2. Lakukan **self-audit** khusus sprint itu, dan laporkan hasilnya apa adanya
   termasuk yang GAGAL palsu karena cacat skrip auditnya sendiri.
3. Tulis `SPRINT-<nomor>-SELESAI.md` berisi keputusan desain **beserta
   alasannya**, bug yang ditemukan, dan apa yang sengaja tidak dikerjakan.
4. Perbarui `STATUS.md`: ringkasan sprint, angka terkini, bug baru yang
   diketahui.
5. Hapus `.git/index.lock` (lihat catatan di bawah).
6. Tutup dengan dua blok perintah PC/VPS + daftar yang harus dilihat di browser.

**`.git/index.lock` sering tersangkut** setelah asisten menyentuh berkas.
Selama berkas itu ada, `git add` maupun `git commit` di PC akan gagal dengan
*"Another git process seems to be running"*. Asisten harus menghapusnya di
akhir setiap sprint. Kalau terlanjur tertinggal, di PowerShell:

```powershell
Remove-Item C:\ProjectDrama\dramaverse-id\.git\index.lock
```

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

### Sprint 7.5 — Upload Video Episode
Halaman `/admin/episode/video`, plus tombol "Unggah video" di daftar Episode. Seluruh unggahan lewat `StorageEngineInterface` —
controller dan service nol `Storage::`. Mode Auto (provider default) dan Manual
(hanya provider aktif yang sudah lolos Test Connection). Tabel `episode_videos`
menyimpan 13 kolom metadata termasuk checksum SHA256. Drag & drop, progress bar
XHR, pratayang nama/ukuran/format/tujuan.
Detail: `SPRINT-7-5-SELESAI.md`.

**Wajib diatur di server sebelum bisa dipakai** — batas bawaan PHP dan Nginx
jauh di bawah ukuran video episode:
```
# /etc/php/8.3/fpm/php.ini
upload_max_filesize = 4G
post_max_size = 4G
max_execution_time = 3600
# /etc/nginx/sites-available/dramaverse
client_max_body_size 4G;
```
Berkas yang melewati `post_max_size` membuat request kosong dan muncul sebagai
419 tanpa penjelasan, bukan sebagai pesan validasi.

### Sprint 7.6 — Drama Asset Management
Halaman `/admin/drama/{id}/asset` plus tombol "Kelola aset" di daftar Drama.
Sepuluh jenis: poster, cover desktop/mobile, backdrop, banner, thumbnail,
logo, thumbnail trailer, galeri (multi upload), dan subtitle. Semua lewat
`StorageEngineInterface` — controller dan service nol `Storage::`. Mode Auto
dan Manual, tabel `drama_assets` dengan checksum SHA256, drag & drop,
progress, pratayang, ganti, dan hapus.
**SVG tidak diterima** — dokumen XML yang boleh memuat `<script>`, disajikan
dari domain yang sama dengan panel admin.
Detail: `SPRINT-7-6-SELESAI.md`.

### Sprint 7.7 — Queue & Background Upload
Pengiriman video episode ke storage provider pindah ke background lewat
Laravel Queue. Halaman `/admin/upload` berisi riwayat pekerjaan dengan lima
status (Menunggu, Diproses, Berhasil, Gagal, Dibatalkan), Retry, Cancel,
Hapus, dan log per pekerjaan. Storage Engine (7.4) dan `EpisodeVideoService`
(7.5) **tidak diubah satu baris pun** — rantainya tetap
Job → EpisodeVideoService → StorageEngineInterface.
Detail: `SPRINT-7-7-SELESAI.md`.

**Yang dipindahkan ke background hanya bagian keduanya.** Pengiriman peramban
ke server tetap di dalam request — byte-nya datang lewat request itu sendiri
dan tidak bisa dipindahkan rancangan mana pun. Yang berpindah adalah
pengiriman server ke bucket, dan itulah yang selama ini menggantung berpuluh
menit.

**Worker WAJIB mendengarkan antrean `uploads`:**
```
php artisan queue:work uploads --queue=uploads --timeout=3600 --tries=1
```
Worker yang hanya mendengarkan `default` membuat setiap unggahan menggantung
di status Menunggu **selamanya, tanpa satu pun pesan galat di mana pun**. Nama
antrean dan koneksinya ditampilkan di halaman Upload Queue supaya bisa
dicocokkan.

### Sprint 7.8–7.9 — Storage Monitoring, File Manager & Batch Upload
Tiga modul terakhir Phase 7.

**Storage Monitoring** (`/admin/storage/monitor`) — jumlah provider (total,
aktif, nonaktif, terhubung, gagal, belum diuji), provider default beserta
keadaannya, total berkas, total ruang terpakai, unggahan hari ini dan bulan
ini, plus tabel per provider. Tombol Refresh Status dan Test Connection.
Angkanya dibaca dari **database**, bukan dari isi bucket.

**File Manager** (`/admin/files`) — satu daftar untuk seluruh berkas yang
dikenal aplikasi, dibaca dari `episode_videos` dan `drama_assets` sekaligus
lewat UNION. Pencarian, empat penyaring, empat kolom yang bisa diurutkan,
pagination, pratayang gambar, unduh, salin URL, ganti nama, pindahkan, hapus.
Semua operasi lewat `StorageEngineInterface`.

**Batch Upload** (`/admin/upload/batch`) — banyak berkas sekali jalan lewat
antrean yang sama dengan unggahan satuan. Video episode (dipetakan per episode,
nomornya ditebak dari nama berkas dan bisa dikoreksi) dan aset drama. Tiap
berkas jadi satu permintaan HTTP dan satu pekerjaan antrean, sehingga progress
per berkas nyata dan kegagalan satu tidak menghentikan yang lain.

Detail: `SPRINT-7-8-7-9-SELESAI.md`.

**Tiga modul lama ikut disentuh, semuanya aditif dan diverifikasi:**
`StorageEngineInterface` mendapat **satu** method baru (`readStream()`, tanpa
mengubah satu pun method lama); `UploadQueueService` diberi jalur kedua lewat
`createJob()` yang diekstrak dari `queueEpisodeVideo()`; dua helper daftar
provider di `EpisodeVideoController` pindah ke `StorageChoiceService`.
`EpisodeVideoService` dan `DramaAssetService` **tidak disentuh**.

**Berkas dihapus:** `app/Models/EpisodeSubtitle.php` — kelas kosong tanpa
tabel, tanpa migration, tanpa satu pun rujukan. Dead code sejak dibuat.

**Angka saat ini:** 171 route, 24 controller admin, 25 view admin,
37 migration, 11 middleware, 241 kelas CSS, 26 interface repository,
8 job antrean.

Angka route dihitung dengan menambahkan 13 route baru ke angka 158 dari 7.7.
Penghitungan mandiri lewat `tools/routeparse.py` (101 nama di `web.php` + 5 di
`api.php` + 64 hasil ekspansi perulangan CRUD yang tidak terbaca parser)
memberi 170 — selisih satu yang tidak bisa saya pastikan tanpa menjalankan
`php artisan route:list`. Disebutkan supaya bisa diperiksa, bukan ditutupi.

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
- **Berkas staging bisa menumpuk di disk VPS.** Selama pekerjaan unggah belum
  berhasil, videonya tersimpan dua kali: di `storage/app/upload-queue` dan
  (nanti) di bucket. Berkas milik pekerjaan **Gagal** sengaja dipertahankan
  supaya tombol Retry punya bahan. Sepuluh unggahan 3 GB yang gagal berbarengan
  berarti 30 GB tertahan sampai ada yang mengulang atau menjalankan
  `php artisan upload:prune`. Perintah itu belum dijadwalkan otomatis.
- **Baris antrean bisa tersangkut di status Diproses.** Worker yang dimatikan
  di tengah jalan (restart supervisor, reboot, OOM killer) tidak sempat
  menjalankan penanganan galat mana pun. Hook `failed()` menangkap kasus batas
  waktu, tapi tidak menangkap proses yang dibunuh paksa. Yang melepaskannya
  `upload:prune`, dijalankan manual.
- **Berkas staging yatim tidak terdeteksi.** Kalau baris `upload_jobs` dihapus
  langsung dari database (bukan lewat panel), berkasnya tertinggal tanpa ada
  yang menunjuk. `upload:prune` bekerja dari baris, bukan dari isi folder.
- **Galeri belum bisa diurutkan ulang.** Kolom `sort_order` sudah ada dan
  terisi urut unggah, tapi belum ada UI seret-lepas untuk mengubahnya.
- **`EpisodeVideoService` dan `DramaAssetService` berbagi pola yang sama**
  (checksum → engine → metadata → kompensasi) tanpa disatukan. Tidak disatukan
  di 7.6 karena spesifikasinya melarang menyentuh modul video episode.
  Kandidat penyatuan jadi satu `StoredFileWriter` di sprint yang boleh
  mengubah keduanya.
- **Belum ada cara menghapus video episode dari panel.** Mengunggah ulang
  menggantinya (dan menghapus berkas lama), tapi tidak ada tombol untuk
  melepaskan video tanpa menggantinya. Menghapus baris di Upload Queue hanya
  menghapus riwayat dan berkas sementaranya — video yang sudah sampai ke
  storage provider TIDAK ikut terhapus.
- **Aset drama lewat antrean hanya dari Batch Upload.** Sejak 7.9 ada jalur
  `drama_asset` di `upload_jobs`, tapi yang memakainya baru halaman Batch
  Upload. Halaman Asset Manager per drama (7.6) masih mengunggah di dalam
  request — mengunggah sepuluh gambar galeri dari sana masih memblokir sampai
  semuanya sampai ke bucket. Menyatukannya berarti mengubah
  `DramaAssetController`, dan jalur antreannya sudah terbukti dulu di Batch
  Upload sebelum modul lama dipindahkan ke sana.
- **Subtitle banyak sekaligus belum ada.** Subtitle yang ada adalah subtitle
  tingkat drama, dan jenis itu hanya boleh punya satu berkas per drama
  (`updateOrCreate` pada `(drama_id, asset_type)`). Batch Upload menolak lebih
  dari satu dengan pesan yang menyebutkan sebabnya. "Multiple Subtitle" yang
  berguna adalah subtitle **per episode**, dan modul itu tidak ada sama sekali:
  tidak ada tabel `episode_subtitles`, tidak ada migration, tidak ada service.
  Kandidat sprint tersendiri.
- **File Manager tidak menampilkan berkas yatim.** Daftarnya dibaca dari
  `episode_videos` dan `drama_assets`; objek di bucket yang barisnya sudah
  hilang tidak akan muncul meskipun masih menghabiskan ruang berbayar. Belum
  ada perintah yang menyisir bucket dan membandingkannya dengan database.
- **Pindah berkas antar provider belum ada.** File Manager memindahkan antar
  direktori di provider yang SAMA. Antar provider perlu mengalirkan isi berkas,
  tahan terhadap kegagalan di tengah jalan, dan untuk video gigabyte terlalu
  lama untuk berada di dalam request — tempatnya di antrean.
- **Checksum belum pernah diverifikasi ulang.** Kolomnya terisi setiap
  unggahan, tapi belum ada perintah yang membandingkannya dengan berkas di
  bucket. Nilainya baru berguna kalau ada yang memeriksanya.
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
- **Test Connection massal** dari panel (satuan sudah ada di Storage Manager
  dan di Storage Monitoring; semua sekaligus masih lewat
  `php artisan storage:test`)
- **Test Connection di antrean** — sekarang sinkron, jadi provider yang lambat
  menahan permintaan admin sampai SDK menyerah
- **Hapus permanen** — baris terhapus kini hanya bisa dipulihkan atau dibiarkan
- **Router upload dengan failover** — memakai `StorageManager::chain()`
- **Migrasi berkas antar provider**
- **GCS dan Azure** — baru kerangka, jalur kredensialnya belum selesai

Tiga baris yang dulu ada di daftar ini sudah selesai dan dibuang: Test
Connection dari panel (7.3), kolom hasil test terakhir di tabel (7.3), dan
presigned URL untuk berkas privat — yang terakhir dipakai File Manager lewat
`StorageEngine::temporaryUrl()` sejak 7.8.

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
- `supervisorctl restart dramaverse-worker:*` → di **VPS**, setiap kali job
  atau konfigurasi antrean berubah. Worker memuat kode saat dinyalakan, jadi
  yang lama akan terus menjalankan versi sebelumnya sampai direstart
- `php artisan upload:prune` → di **VPS**, saat berkas staging perlu dibersihkan

Tutup dengan daftar singkat **apa yang harus dilihat di browser**, karena
seluruh alat verifikasi proyek ini statis dan tidak pernah merender apa pun.

---

## Alat verifikasi

```bash
python tools/verify-consistency.py       # 18 pemeriksaan
python tools/check-blade-directives.py resources/views/**/*.blade.php
python tools/check-css-coverage.py
python tools/check-php-structure.py app/**/*.php config/*.php database/**/*.php
python tools/audit-sprint-7-8.py         # 143 pemeriksaan khusus Sprint 7.8-7.9
```

`audit-sprint-7-8.py` khusus sprint itu, tetapi tetap disimpan: sebagian
pemeriksaannya berlaku terus — nol `Storage::` di controller dan service,
satu jalur pembuatan baris antrean, dan tidak ada method kontrak engine yang
hilang dari implementasinya.

`verify-consistency.py` memeriksa: route mati di Blade dan PHP, controller,
view, komponen, layout, `$fillable` vs migration, urutan foreign key,
binding repository, PSR-4, import CSS, kolom tanggal, form + CSRF, href
buntu, emoji, kelengkapan `match` enum, dan route di menu sidebar admin.

**Alat verifikasinya sendiri sudah lima kali terbukti salah.** Perlakukan
seperti kode lain: bisa keliru, dan perlu diaudit.

- **7.1** — pembatas blok migration di `cols_of()` tidak pernah bekerja,
  sehingga pemeriksaan `$fillable` jauh lebih longgar daripada yang terlihat.
- **7.1** — pemeriksaan paritas tanda kutip di `check-php-structure.py`
  menuduh delapan berkas sah, termasuk `config/app.php` bawaan Laravel.
- **7.6** — `routeparse.py` menghitung `{` dan `}` yang ada **di dalam
  string**, sehingga `->prefix('drama/{drama}/asset')` membuat prefix nama
  dibuang dan seluruh route di grup itu dilaporkan mati padahal benar.
- **7.7** — skrip self-audit sprint itu sendiri menghitung `<form` **di dalam
  komentar Blade**. Kalimat penjelasan yang memuat kata `<form>` terhitung
  sebagai tag pembuka yang tidak pernah ditutup, dan menghasilkan dua GAGAL
  palsu tentang form bersarang.
- **7.8** — skrip self-audit sprint itu memeriksa "setiap route punya
  middleware permission" dengan mengambil **3000 karakter tetap** setelah nama
  grupnya. Jendelanya menembus ke grup berikutnya dan melaporkan
  `files.: 9 middleware permission untuk 17 route` pada grup yang sebenarnya
  berisi 6 route dengan 6 middleware. Diperbaiki dengan mencocokkan kurung.
  Ini kegagalan palsu kedua yang disebabkan penghitungan blok yang naif,
  setelah `routeparse.py` di 7.6.

Pelajarannya: kalau alat melaporkan GAGAL pada kode yang menurut Anda benar,
periksa alatnya juga — jangan langsung menganggap kodenya yang salah.

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
- **Worker antrean juga harus mendengarkan `uploads`** sejak Sprint 7.7, kalau
  tidak setiap unggahan video menggantung di status Menunggu tanpa galat
- `php artisan upload:prune` sesekali, supaya berkas staging milik unggahan
  yang gagal tidak menumpuk di disk VPS
- `RoleSeeder` perlu dijalankan sekali agar peran dan izin terisi

---

## Catatan jujur soal batasan asisten

Di lingkungan tempat asisten bekerja **tidak tersedia**: PHP, composer,
MySQL, maupun peramban. Yang ada hanya Python dan Node.

Artinya asisten **tidak pernah menjalankan** `php artisan route:list`, tidak
pernah menjalankan migration, tidak pernah merender satu halaman pun, dan
tidak pernah mengirim satu form pun. Seluruh "verifikasi" yang dilaporkannya
bersifat statis — pembacaan teks, bukan eksekusi.

Konsekuensi praktisnya:

- `composer require` selalu jadi tugas Anda di PC, bukan asisten.
- Migration baru belum pernah benar-benar dijalankan sampai Anda deploy.
- Perubahan tampilan belum pernah dilihat sampai Anda membukanya di browser.
- Kalau asisten menulis "sudah diverifikasi", tanyakan **dengan cara apa**.

Selama pengerjaan, beberapa kesalahan lolos dari pemeriksaan statis dan
baru muncul saat dieksekusi:

- Kolom `timestamp` tanpa `nullable` (MySQL menolak saat migrate)
- Seeder yang bergantung pada route cache
- Urutan `optimize:clear` yang keliru di panduan
- `composer.json` merujuk berkas yang tidak ada

Semua sudah diperbaiki dan pemeriksaannya ditambahkan, tapi kelas
kesalahan lain tetap mungkin ada. **Selalu uji di browser setelah
deploy**, dan kirim pesan galat apa adanya.
