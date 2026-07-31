# DramaVerse ID — Status Proyek

**Berkas ini untuk dibaca lebih dulu saat memulai sesi baru.**

Terakhir diperbarui: 31 Juli 2026

---

## Memulai sesi baru

Kalimat pembuka yang cukup, tanpa perlu menjelaskan apa pun lagi:

> Baca `STATUS.md` di folder ini sampai habis, lalu ikuti seluruh aturan di
> dalamnya. Sesudah itu tunggu spesifikasi sprint dari saya.

Yang WAJIB dilakukan asisten sebelum menulis satu baris kode:

0. **Jalankan `git log --oneline -5` lebih dulu, sebelum membaca apa pun.**
   Judul commit terakhir menyebutkan sprint terakhir yang selesai. Riwayat
   commit tidak bisa basi dengan cara yang sama seperti daftar berkas, jadi
   inilah satu-satunya sumber yang bisa dipercaya di detik pertama sesi.
   Kalau judul commit terakhir menyebut sprint yang lebih baru daripada yang
   tertulis di poin 2 di bawah, **berarti berkas yang terbaca sudah basi** —
   berhenti, baca ulang, jangan lanjut.
1. Baca `STATUS.md` **sampai habis** — termasuk "Bug diketahui" dan
   "Batasan asisten" di bagian bawah.
2. Baca berkas `SPRINT-*-SELESAI.md` yang relevan dengan pekerjaan berikutnya.
   Sprint terakhir yang selesai adalah **12.5** (`SPRINT-12-SELESAI.md`).
   **Seluruh fase selesai. Dokumentasi lengkap ada di `docs/`.**
   **Phase 7, 8, 9, dan 10 selesai.**
3. Jalankan kelima alat verifikasi lebih dulu, untuk tahu keadaan awal yang
   bersih. Kalau ada yang GAGAL sejak awal, laporkan sebelum menambah apa pun.
   **Cocokkan angkanya dengan "Angka saat ini" di bawah.** Angka yang lebih
   kecil berarti pohon berkasnya belum lengkap, bukan berarti ada yang hilang.
4. **Periksa daftar berkas ini lagi sebelum menulis dokumen apa pun.**
   Pembacaan folder di awal sesi sudah **dua kali** mengembalikan keadaan basi:
   - **7.8** — `SPRINT-7-7-SELESAI.md` tidak muncul di daftar dan `STATUS.md`
     yang terbaca adalah versi sebelum diperbarui, sehingga asisten melaporkan
     dokumen 7.7 hilang padahal ada.
   - **8.1** — `STATUS.md` yang terbaca berhenti di Sprint 7.6 (468 baris,
     bukan 604), dua berkas SELESAI dan `tools/audit-sprint-7-8.py` tidak
     muncul sama sekali, dan baseline alat verifikasi dijalankan di atas pohon
     yang kurang 22 berkas PHP. Ketahuan hanya karena angka hasil alat
     verifikasi melompat di tengah sesi.

   Tidak ada yang tertimpa di kedua kejadian, tetapi keduanya membuat sebagian
   pekerjaan berdiri di atas premis yang keliru. Poin 0 di atas ditambahkan
   sesudah kejadian kedua.

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

### Sprint 8.1 — Telegram Core Service
Fondasi Phase 8. `TelegramServiceInterface` jadi **satu-satunya pintu** ke
Telegram Bot API, dengan `TelegramClient` sebagai satu-satunya tempat yang
membuka koneksi HTTP. Sembilan method dasar (sendMessage, sendPhoto, sendVideo,
sendDocument, editMessage, deleteMessage, answerCallbackQuery, getFile, getMe)
plus `call()`/`query()` untuk method Bot API lain. Timeout, percobaan ulang yang
menghormati `retry_after`, logging request/response/error, dan redaksi token.
Storage (7.1–7.9) tidak diubah satu baris pun.
Detail: `SPRINT-8-1-SELESAI.md`.

**Sebelum sprint ini ada TIGA jalur HTTP ke Telegram** — dua service dan satu
repository, masing-masing dengan token, timeout, dan penanganan galat sendiri;
salah satunya membaca `services.telegram.bot_token` sementara sisanya membaca
`telegram.bot_token`. Ketiganya disatukan. Dua kelas `TelegramService` dihapus,
`TelegramRepository` diubah perannya jadi akses data (segmen broadcast, jumlah,
penonaktifan pengguna yang memblokir bot).

**Perubahan perilaku:** kegagalan sekarang **dilempar** sebagai
`TelegramException`, tidak lagi dikembalikan sebagai array yang boleh
diabaikan — dari 20 pemanggil sebelumnya, 19 tidak pernah memeriksa `ok`.
Konsekuensinya ditangani di dua tempat: `TelegramWebhookController` menahan
exception (kalau tidak, Telegram mengirim ulang update yang sama selamanya),
dan halaman admin memakai `withRetries(1)` supaya tidak menahan permintaan.

**Cara membuktikannya jalan:**
```
php artisan telegram:test                          # token + koneksi + webhook
php artisan telegram:test --chat=<telegram_id>     # kirim pesan sungguhan
```
`getMe` membuktikan token dan jaringan; ia TIDAK membuktikan pesan bisa sampai
ke orang. Untuk itu chat id-nya harus disebut, dan pengguna yang bersangkutan
harus sudah pernah menekan Start di bot.

**Menu bot bisa diatur dari panel** — `/admin/telegram/menu`, izin
`telegram.manage` (tidak ada izin baru). Tabel `telegram_menus` menyimpan
label, perbuatan, URL, baris, posisi, dan status aktif. `TelegramMenuAction`
jadi satu-satunya daftar yang menghubungkan pilihan di panel, `callback_data`,
dan handler-nya — sebelumnya daftar tombol dan daftar handler ditulis terpisah
dan memang sempat tidak sinkron. Bawaan tetap dipatok di kode sebagai jaring
pengaman: tabel kosong tidak boleh membuat bot mengirim sambutan tanpa satu
tombol pun.

```
php artisan migrate
php artisan db:seed --class='Database\Seeders\TelegramMenuSeeder' --force
```

**Dua bug yang baru ketahuan saat diuji di bot sungguhan:**
`user_sessions.user_id` adalah foreign key ke `users.id`, tetapi yang dikirim
ke sana adalah `telegram_id` — melanggar constraint, webhook menjawab 500, dan
yang dilihat pengguna adalah tombol yang ditekan lalu tidak terjadi apa-apa.
Bug yang sama versi diam ada di `TelegramRouter`, membuat state `SEARCH` tidak
pernah terbaca. Keduanya diperbaiki lewat `findByTelegramId()`.

### Sprint 8.2–8.6 — Telegram Integration
Website, Storage, Database, dan Bot saling terhubung. Core Service (8.1),
Storage Engine (7.4), modul unggah (7.5–7.6), dan Queue (7.7) tidak diubah satu
baris pun. Detail: `SPRINT-8-2-SELESAI.md`.

**Gagasan yang menentukan seluruh rancangannya: video tidak pernah dikirim dua
kali ke Telegram.** Sekali per berkas, admin menyinkronkannya dari storage
provider dan `file_id`-nya disimpan. Sesudah itu setiap pengiriman ke pengguna
hanya menyebut file_id — nol byte keluar dari server, nol bandwidth bucket,
selesai dalam milidetik. Bot TIDAK pernah mengunggah video saat pengguna
memintanya.

- **Sinkron** — `/admin/telegram/sync`. Sync, Retry Sync, Sinkronkan semua
  (batas 25 sekali tekan). Status Pending, Processing, Synced, Failed. Tujuh
  kolom baru di `episode_videos` termasuk `telegram_file_id`,
  `telegram_unique_file_id`, `last_error`, `retry_count`.
- **Deep link** — `t.me/<bot>?start=watch_<id>` dan `drama_<id>`. Tombol
  "Tonton di Telegram" di halaman episode, hanya dirender bila videonya memang
  sudah ada di Telegram.
- **Playback** — inline keyboard Sebelumnya / Daftar Episode / Berikutnya /
  Favorit / Website, dengan daftar episode berhalaman.
- **Membership** — ditanyakan ke `EpisodeAccessService`, service yang sama
  dengan pemutar website. Free, Premium, dan Expired dibedakan; yang ditolak
  mendapat tombol Upgrade.
- **Continue Watching, Riwayat, Favorit** — lewat `WatchHistoryService` dan
  `FavoriteService`. Tidak ada mekanisme sinkronisasi antara website dan bot,
  karena tidak ada dua salinan data: ada satu data dan dua tampilan.

**Langkah wajib setelah deploy:**
```
php artisan migrate
php artisan config:cache
```
Plus `TELEGRAM_STORAGE_CHAT_ID` di `.env` — channel PRIVAT tempat bot jadi
admin. Tanpa itu tombol Sync menolak dengan alasan yang disebutkan di panel.
Channel itu tidak boleh publik: isinya seluruh katalog video termasuk yang
berbayar, dan siapa pun yang bisa membukanya menonton tanpa lewat pemeriksaan
membership.

### Sprint 8.7-8.9 - Telegram Finalization
Admin tools, otomatisasi, dan optimasi di atas seluruh arsitektur sebelumnya,
tanpa mengubah satu pun fitur yang sudah berjalan.
Detail: `SPRINT-8-7-SELESAI.md`.

- **Admin tools** - `/admin/telegram/sync` kini punya kartu status (bot,
  webhook, antrean, tersangkut), statistik, pencarian, penyaring, pengurutan,
  pagination, dan **lima aksi massal**: Bulk Sync, Bulk Retry, Bulk Cancel,
  Refresh Status, Verifikasi file_id. Semuanya lewat antrean.
  Plus `/admin/telegram/log` - pembaca log Telegram tanpa perlu masuk server.
- **Otomatisasi** - `EpisodeVideoObserver` (auto sync + pembuangan cache) dan
  `php artisan telegram:auto retry|health|cleanup|all`, dijadwalkan tiap 15
  menit, 30 menit, dan tiap jam.
- **Notifikasi** - `TelegramAlertService` untuk sync gagal, antrean gagal,
  galat API, bot mati, dan scheduler gagal. Ke log selalu; ke Telegram bila
  `TELEGRAM_ALERT_CHAT_ID` diisi, dengan penahan supaya tidak membanjiri.
- **Optimasi** - pembatas laju sebelum Telegram menahan, cache `file_id` dan
  metadata episode, eager loading, pembacaan log dari ujung berkas.

**Bug yang ditemukan:** `ActivityLogger::log()` menerima `?Model`, bukan `int`.
Sprint 8.1 dan 8.2 memanggilnya dengan `$video->id` di tiga tempat - TypeError
setiap kali tombol Sync, Retry, atau Hapus menu ditekan. Lolos dari empat alat
statis karena tak satu pun memeriksa tipe argumen.

**WAJIB dipasang di VPS - tanpa ini seluruh otomatisasi tidak pernah jalan:**
```
crontab -e
* * * * * cd /var/www/dramaverse && php artisan schedule:run >> /dev/null 2>&1
```
Tidak akan ada satu pun galat yang memberitahukan kalau baris ini lupa dipasang.

### Phase 9 - Production Ready (9.1-9.5)
Lapisan operasional di atas seluruh arsitektur sebelumnya, tanpa mengubah satu
pun alur bisnis. Detail: `SPRINT-9-1-SELESAI.md`.

- **Monitoring** - `/admin/monitoring`. Sembilan pemeriksaan: basis data,
  cache, antrean, scheduler, cadangan, server, Telegram, storage, galat.
  Storage dan Telegram DIPANGGIL dari service yang sudah ada (7.8 dan 8.9),
  bukan diperiksa ulang - kalau ditulis ulang, dua halaman bisa memberi
  jawaban berbeda tentang sistem yang sama.
- **Cadangan** - `php artisan backup:run`, harian 02:30, plus tombol di panel
  (lewat antrean). Basis data + `.env`, diverifikasi tepat setelah dibuat,
  dipangkas otomatis. **Video tidak ikut** - yang dicadangkan petanya.
- **Log Sistem** - `/admin/system/log`, membaca berkas log (apa yang rusak).
  Melengkapi `/admin/logs` yang membaca `activity_logs` (siapa melakukan apa).
- **Jejak audit autentikasi** - masuk, keluar, gagal masuk, dan terkunci.
  Kata sandi tidak pernah dicatat.
- **Indeks basis data** - enam indeks, seluruhnya aditif dan dibungkus
  pemeriksaan keberadaan.

**Detak scheduler.** `Schedule::call()` tiap menit menulis penanda ke cache.
Tanpa itu, scheduler yang tidak pernah berjalan sama sekali terlihat persis
sama dengan yang berjalan normal - dan seluruh otomatisasi Telegram serta
seluruh cadangan bergantung padanya.

**Langkah wajib setelah deploy:**
```
php artisan migrate
php artisan config:cache
```

### Phase 10 - Payment & Membership System
Sistem membership dan pembayaran yang modular. **Provider tidak dipatok di
kode**: menambah Stripe atau PayPal cukup satu kelas driver + satu case enum,
tanpa menyentuh Business Logic Membership, controller, route, maupun view.
Detail: `SPRINT-10-SELESAI.md`.

- **Membership** - `App\Services\Membership\MembershipService`: paket, status
  (Free/Premium/Expired), riwayat, aktivasi, perpanjangan yang MENUMPUK pada
  sisa yang berjalan, pembatalan, kedaluwarsa terjadwal.
- **Pembayaran** - tabel `invoices` dan `payment_transactions`. Satu invoice
  boleh punya beberapa percobaan; nomor tagihan tidak pernah dibuat ulang.
- **Provider** - tabel `payment_providers`, kredensial terenkripsi, sandbox
  dan live bisa berdampingan. Panel di `/admin/payment/provider`.
- **Driver** - `manual` dan `trakteer` JALAN PENUH. Midtrans, Xendit, Tripay
  terdaftar sebagai kerangka yang menolak diaktifkan sampai diuji dengan akun
  sungguhan.
- **Callback** - satu route `/payment/callback/{provider}` untuk semua
  provider. Empat penjagaan: tanda tangan, `lockForUpdate`, perpindahan status,
  pencocokan nominal.
- **Admin** - `/admin/payment/invoice` (search, filter, sort, pagination,
  ekspor CSV, verifikasi manual, pembatalan), `/admin/payment/log`.

**Bug fatal yang ditemukan dan diperbaiki:** `EpisodeAccessRepository` membaca
`episodes.access_type` dan `users.is_premium` yang **keduanya tidak pernah ada
di migration mana pun**. Eloquent mengembalikan null tanpa galat, sehingga
method itu selalu mengembalikan false - **tidak ada satu pun episode yang bisa
ditonton siapa pun, termasuk yang gratis.** Dipakai pemutar web DAN bot
Telegram sejak Sprint 8.5.

Bug kedua: `PaymentService` menyuntik `PaymentGatewayInterface` yang tidak
pernah di-bind. Setiap upaya membangunnya melempar `BindingResolutionException`.
Tidak pernah terlihat karena tidak ada yang memanggilnya - dead code sejak
dibuat, bersama tiga gateway yang isinya hanya `TODO`.

**Langkah wajib setelah deploy:**
```
php artisan migrate
php artisan db:seed --class='Database\Seeders\PaymentProviderSeeder' --force
php artisan config:cache
```
Lalu isi nomor rekening di `/admin/payment/provider` dan aktifkan. Sebelum itu
tombol Berlangganan tidak dirender sama sekali - tombol yang menjanjikan
pembayaran lalu dijawab "belum ada metode" adalah dead link.

### Phase 11 - Analytics & Business Intelligence
Dashboard analitik lima seksi (bisnis, konten, Telegram, penyimpanan,
keuangan) dengan periode harian/mingguan/bulanan/tahunan, tujuh jenis laporan,
dan pemanas cache terjadwal. **Alur utama aplikasi tidak diubah satu baris
pun** - nol migration, nol tabel, nol CSS baru, nol komponen grafik baru.
Detail: `SPRINT-11-SELESAI.md`.

**Bug yang ditemukan: laporan pendapatan menghitung dari tabel yang salah.**
`ReportController` dan `StatsService` sama-sama menjumlahkan
`subscriptions.price`. Sejak Phase 10 itu keliru dua kali: langganan yang
DIBERIKAN admin punya harga tetapi tidak ada uang masuk, dan biaya layanan
provider hanya tercatat di invoice. Keduanya salah dengan cara yang sama,
sehingga angkanya selalu cocok satu sama lain - dan kecocokan itulah yang
membuatnya tidak pernah dicurigai. Laporan sudah diperbaiki ke `invoices`
lunas; `StatsService` BELUM (lihat bug diketahui).

**Cara memakainya:**
```
php artisan analytics:refresh
```
Memanaskan cache dashboard. Dijadwalkan tiap sepuluh menit; jalankan manual
setelah impor data besar.

### Phase 12 - Final Launch & Optimization
Pemeriksaan seluruh proyek, pembersihan, pengerasan, dan dokumentasi.
Detail: `SPRINT-12-SELESAI.md`.

**Alat baru `tools/audit-final.py`** menyisir seluruh pohon untuk MENEMUKAN
masalah, bukan menegaskan yang sudah diketahui. Jalan pertamanya: 21/43 lolos.

- **Bug ditemukan:** `episodes:publish` ada sejak Sprint 6 tetapi tidak pernah
  dijadwalkan. Episode berjadwal tidak pernah terbit sendiri, tanpa satu pun
  galat. Sudah dijadwalkan tiap lima menit.
- **31 kelas mati dihapus** beserta 6 repository, 4 job stub, dan 31 import
  yang ikut yatim.
- **Blok config `storage.limits` dibuang** - tiga kunci yang tidak pernah
  dibaca siapa pun, salah satunya berpura-pura mengatur batas waktu S3.
- **Delapan kelompok kode kembar disatukan** jadi `Support\Bytes` dan empat
  trait di `Support\Concerns`, plus pemakaian `StorageChoiceService` dan
  `StorageTestResult::meta()` yang sudah ada.
- **`php artisan env:check`** - menolak peluncuran bila environment belum
  layak. Memeriksa 20+ hal termasuk detak scheduler, dan keluar dengan kode 1
  supaya bisa menghentikan skrip deploy.
- **14 dokumen di `docs/`** plus indeksnya.

**Tiga kali saya merusak kode sendiri di sprint ini**, ketiganya karena regex
penyunting kode yang mencocok lebih panjang dari yang dimaksud - termasuk
`config/storage.php` yang kehilangan 315 dari 322 baris. Semuanya tertangkap
karena `check-php-structure.py` dan `git diff --stat` dijalankan segera
sesudah setiap jalan. Ditulis lengkap di `SPRINT-12-SELESAI.md`; pelajarannya
ada di bagian bawah berkas ini.

**Angka saat ini:** 195 route, 32 controller admin, 34 view admin,
45 migration, 11 middleware, 257 kelas CSS, 20 interface repository,
8 job antrean, 397 berkas PHP, 80 blade, 12 perintah artisan,
15 dokumen di `docs/`.

Empat angka terakhir ditambahkan sebagai pembanding untuk poin 3 di bagian
"Memulai sesi baru": alat verifikasi yang melaporkan angka lebih kecil berarti
pohon berkasnya belum lengkap.

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
- ~~**Callback pembayaran selalu dijawab 419.**~~ **SELESAI** — `payment/callback/*`
  tidak ada di daftar pengecualian CSRF, hanya `telegram/webhook`. Akibatnya
  SETIAP callback gateway ditolak sebelum satu baris kode pun berjalan;
  gateway mengirim ulang, ditolak lagi, dan pembayaran yang sah tidak pernah
  mengaktifkan membership. Tidak ada galat aplikasi karena kodenya memang
  tidak pernah dijalankan. Ditemukan saat audit webhook Trakteer.
- **`StatsService::summary()['revenue']` masih menjumlahkan
  `subscriptions.price`.** Angka yang sama salahnya dengan yang sudah
  diperbaiki di laporan Phase 11: langganan pemberian admin ikut terhitung
  sebagai pendapatan, dan biaya layanan provider tidak pernah muncul. Kartu
  pendapatan di **dashboard utama** karenanya bisa berbeda dari halaman
  Analytics dan Laporan, yang keduanya sudah membaca dari `invoices` lunas.
  Tidak disentuh di Phase 11 karena `StatsService` juga memberi makan
  dashboard, dan memindahkannya berarti mengubah halaman yang tidak diminta
  sprint itu. Perbaikannya mekanis: panggil
  `AnalyticsRepositoryInterface::revenueTotals()`.
- **Tiga driver gateway masih kerangka.** Midtrans, Xendit, dan Tripay
  terdaftar penuh di `PaymentDriver`, punya daftar field kredensialnya sendiri,
  dan muncul di panel — tetapi `isImplemented()` mengembalikan false dan
  keduanya menolak diaktifkan. Alur charge, bentuk callback, dan cara
  menghitung tanda tangannya hanya bisa dipastikan dengan akun sungguhan;
  menulisnya dari ingatan menghasilkan kode yang lolos seluruh pemeriksaan
  statis lalu gagal pertama kali ada orang yang benar-benar membayar. Tiga
  langkah untuk menyelesaikannya ada di docblock masing-masing.
- **Auto renewal belum berjalan.** Kolom `auto_renew` ada dan bisa diisi; yang
  memperpanjang otomatis saat jatuh tempo belum ada. Perpanjangan otomatis
  memerlukan penyimpanan token kartu di sisi provider dan alur pembatalan yang
  bisa diandalkan — keduanya keputusan yang lebih besar daripada satu kolom.
- **Pengembalian dana baru struktur data.** `RefundStatus` lengkap, kolomnya
  ada, panel menampilkannya. Yang belum ada: yang memanggil API pengembalian
  dana provider. Spesifikasi Phase 10 memang hanya meminta struktur datanya.
- **Trakteer menyambung lewat pesan bebas.** Trakteer tidak punya API
  pembuatan transaksi, jadi tagihan dikenali dari pola `INV-YYYYMMDD-XXXXXX`
  di pesan pendukung. Yang salah ketik tidak akan tersambung ke tagihan mana
  pun. Itu bukan kegagalan yang bisa dihilangkan kode — yang bisa dilakukan,
  dan dilakukan: payload lengkapnya dicatat sebagai
  `payment.callback.unmatched` plus peringatan ke operator, sehingga bisa
  dicocokkan manual.
- **Bot API menolak berkas di atas 50 MB, dan itu batas Telegram.** Video
  episode drama umumnya jauh lebih besar, jadi dalam keadaan bawaan sebagian
  besar katalog **tidak akan bisa disinkronkan ke Telegram**. Tidak ada
  rancangan di sisi kita yang bisa melewatinya. Jalan keluarnya satu:
  menjalankan Local Bot API Server sendiri lalu mengarahkan
  `TELEGRAM_API_URL` ke sana — batasnya naik jadi 2000 MB. Jalurnya sudah siap
  sejak 8.1 (`api_url` dan `upload_max_mb` keduanya dari config, nol URL yang
  dipatok di kode), tetapi **belum pernah diuji**.
- **Progres tontonan dari bot tidak tercatat per detik.** Telegram tidak
  memberi tahu detik ke berapa pengguna berhenti — tidak ada callback untuk
  itu. Yang tercatat dari bot adalah "episode ini ditonton", bukan posisinya.
  Progres per detik tetap hanya dari pemutar website.
- **Video baru tidak otomatis disinkronkan.** Statusnya PENDING dan menunggu
  admin menekan Sync. Sengaja: mengantrekannya otomatis berarti setiap unggahan
  langsung memakan kuota Telegram sebelum ada yang memutuskan berkas itu memang
  akan disajikan lewat bot.
- **Menghapus baris `episode_videos` tidak menghapus pesannya di chat
  penyimpanan Telegram.** `telegram_message_id` sudah disimpan supaya bisa
  dikerjakan nanti, tapi belum ada yang memakainya.
- **`telegram_file_id` belum pernah diverifikasi masih berlaku.** Telegram bisa
  membuang berkas lama. Sama seperti checksum bucket yang juga belum pernah
  diperiksa ulang, nilainya baru berguna kalau ada yang memeriksanya.
- **`QueueService::telegram()` mengantrekan job yang tidak melakukan apa pun.**
  `SendTelegramNotificationJob::handle()` isinya hanya komentar `TODO`, tapi
  `QueueService::telegram($message)` tetap mengantrekannya — notifikasinya
  hilang tanpa jejak dan antrean melaporkan sukses. Tidak diperbaiki di 8.1
  karena job itu hanya menerima `$message` tanpa chat id: mengisinya berarti
  memutuskan siapa penerimanya (semua pengguna? admin? satu chat khusus?), dan
  itu keputusan modul notifikasi, bukan core service. `QueueService` sendiri
  saat ini tidak dipanggil dari mana pun, dan tiga job lain yang didaftarkannya
  (`BroadcastEpisodeJob`, `SendPremiumReminderJob`, `GenerateVideoThumbnailJob`)
  juga masih kosong.
- **Worker broadcast bisa mendengarkan KONEKSI yang salah, bukan cuma antrean
  yang salah.** Ditemukan 31 Juli 2026 di server: `dramaverse-worker.conf`
  berisi `queue:work redis`, sedangkan `QUEUE_CONNECTION` di `.env` adalah
  `database`. Job masuk ke tabel `jobs`, worker menunggui Redis, dan keduanya
  tidak pernah bertemu — tanpa satu pun galat di mana pun, karena masing-masing
  bekerja normal. Gejalanya persis sama dengan Telegram yang menolak.

  Argumen pertama `queue:work` adalah **nama koneksi**, bukan nama antrean.
  `queue:work redis` berarti "ambil dari koneksi redis", bukan "antrean redis".
  Ini mudah terbaca terbalik.

  Perbaikannya: hilangkan nama koneksi supaya worker mengikuti `.env` —
  `queue:work --queue=default`. Dengan begitu `.env` jadi satu-satunya sumber
  kebenaran, dan mengganti driver antrean tidak lagi perlu menyunting
  supervisor. Worker unggahan tetap menyebut `uploads` secara eksplisit karena
  memang harus berbeda dari yang lain.

  Halaman `/admin/telegram` sekarang menampilkan koneksi, nama antrean, dan
  jumlah pekerjaan yang menunggu supaya ketidakcocokan seperti ini terlihat
  tanpa harus masuk ke server.
- **Pembatas laju Telegram hanya global, belum per-chat.** Sejak 8.9 ada
  `TelegramRateLimiter` yang menahan sekitar 25 permintaan per detik. Telegram
  juga membatasi ~1 pesan/detik per chat, terpisah dari batas global — itu
  belum ada, dan baru terasa pada broadcast ke satu grup besar.
  Pembatas ini juga **bukan jaminan**: cache tanpa operasi atomik lintas proses
  bisa menghitung kurang saat dua worker menambah bersamaan. Yang memberi
  jaminan tetap penanganan 429 di `TelegramClient`.
- **Verifikasi `file_id` belum dijadwalkan.** Tombol Bulk Verify ada di panel,
  tapi tidak ada jadwal yang menjalankannya sendiri. Menjadwalkannya berarti
  memanggil `getFile` untuk seluruh katalog secara berkala, dan itu keputusan
  yang tergantung besar katalognya.
- **Bulk Cancel tidak menghentikan pekerjaan yang sudah berjalan.** Hanya baris
  berstatus Menunggu yang dibatalkan. Memutus pengiriman berkas separuh jalan
  meninggalkan berkas rusak di Telegram yang tidak bisa dibedakan dari yang
  utuh. Yang tersangkut dilepaskan `telegram:auto cleanup` setelah
  `TELEGRAM_STUCK_MINUTES`.
- **Percobaan ulang bisa menduakan pesan.** Bot API tidak punya kunci
  idempoten. Kalau pesan sampai lalu koneksinya putus sebelum jawabannya
  kembali, pengulangan mengirim pesan yang sama dua kali. Ini pilihan sadar —
  pesan ganda lebih ringan daripada pesan yang hilang diam-diam — dan bisa
  dimatikan dengan `TELEGRAM_RETRY_TIMES=1`.
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
- ~~**`STORAGE_TIMEOUT` tidak berpengaruh.**~~ **SELESAI di Phase 12** —
  seluruh blok `storage.limits` dibuang, bukan disambungkan. Konfigurasi yang
  berbohong lebih berbahaya daripada yang tidak ada. Menyambungkannya ke klien
  S3 tetap mungkin nanti, tetapi harus diuji terhadap versi SDK yang
  benar-benar terpasang.
- ~~**`EpisodeVideoService` dan `DramaAssetService` berbagi pola yang sama.**~~
  **SELESAI di Phase 12** — `checksum()` disatukan jadi
  `Support\Concerns\ComputesFileChecksum`.
- **Catatan lama tentang `STORAGE_TIMEOUT` (sudah tidak berlaku):** Ada di `config/storage.php` dan
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
- `php artisan telegram:test` → di **VPS**, setiap kali lapisan Telegram atau
  `TELEGRAM_*` di `.env` disentuh
- `supervisorctl restart dramaverse-worker:*` → di **VPS**, setiap kali job
  atau konfigurasi antrean berubah. Worker memuat kode saat dinyalakan, jadi
  yang lama akan terus menjalankan versi sebelumnya sampai direstart
- `php artisan upload:prune` → di **VPS**, saat berkas staging perlu dibersihkan
- `php artisan telegram:auto all` → di **VPS**, untuk menjalankan perawatan
  Telegram sekarang juga tanpa menunggu scheduler
- **`crontab -e` + baris `schedule:run`** → di **VPS**, SEKALI SAJA setelah
  Sprint 8.9. Tanpa itu seluruh otomatisasi Telegram tidak pernah berjalan, dan
  tidak ada satu pun galat yang memberitahukannya

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
python tools/audit-sprint-8-1.py         # 81 pemeriksaan khusus Sprint 8.1
python tools/audit-sprint-8-2.py         # 125 pemeriksaan khusus Sprint 8.2-8.6
python tools/audit-sprint-8-7.py         # 133 pemeriksaan khusus Sprint 8.7-8.9
python tools/audit-sprint-9-1.py         # 117 pemeriksaan khusus Phase 9
python tools/audit-phase-11.py         # 84 pemeriksaan khusus Phase 11
python tools/audit-final.py            # 43 pemeriksaan seluruh proyek
python tools/audit-phase-10.py           # 164 pemeriksaan khusus Phase 10
```

`audit-sprint-8-2.py` memeriksa **integrasi**, bukan keberadaan berkas:
pengiriman ke pengguna tidak boleh menyentuh storage sama sekali, aturan premium
tidak boleh ditulis ulang di lapisan Telegram, handler tidak boleh menembus
service untuk membaca tabel sendiri, `answerCallbackQuery` hanya boleh dipanggil
dari satu tempat, dan setiap awalan `callback_data` yang dibuat keyboard harus
punya cabang di router.

`audit-sprint-7-8.py` khusus sprint itu, tetapi tetap disimpan: sebagian
pemeriksaannya berlaku terus — nol `Storage::` di controller dan service,
satu jalur pembuatan baris antrean, dan tidak ada method kontrak engine yang
hilang dari implementasinya.

`audit-sprint-8-1.py` sama: sebagian berlaku terus — nol `Http::` dan nol
`api.telegram.org` di luar `TelegramClient`, nol controller yang memanggil
Telegram API langsung, paritas ketiga kontrak Telegram dengan implementasinya,
token tidak bisa masuk konteks log, dan **setiap kunci `config/telegram.php`
benar-benar dibaca kode**. Pemeriksaan terakhir itu ada khusus karena
`STORAGE_TIMEOUT` membuktikan kunci config bisa hidup berbulan-bulan tanpa ada
yang membacanya, dan tidak ada satu pun alat yang menyadarinya.

`verify-consistency.py` memeriksa: route mati di Blade dan PHP, controller,
view, komponen, layout, `$fillable` vs migration, urutan foreign key,
binding repository, PSR-4, import CSS, kolom tanggal, form + CSRF, href
buntu, emoji, kelengkapan `match` enum, dan route di menu sidebar admin.

**Alat verifikasinya sendiri sudah lima belas kali terbukti salah.** Perlakukan
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
- **8.1** — versi pertama `audit-sprint-8-1.py` menghasilkan dua GAGAL palsu
  karena mencari `api.telegram.org` dan kata `previous` **di dalam komentar dan
  string**. Prosa yang menjelaskan aturan justru dituduh melanggarnya: docblock
  yang berbunyi "tidak ada `Http::` ke api.telegram.org di luar TelegramClient"
  terhitung sebagai pelanggaran. Sebab yang sama untuk keempat kalinya.
  Diperbaiki dengan `code_only()` yang membuang komentar dan isi string literal
  sebelum apa pun dicocokkan, dipakai di setiap pemeriksaan.

- **12.1** - tiga skrip penyunting kode di Phase 12 mencocok lebih panjang
  dari yang dimaksud: `use Log;` dibuang padahal `Log::channel()` masih
  dipakai, `config/storage.php` kehilangan 315 dari 322 baris, dan tiga berkas
  kehilangan kurung penutup. Semuanya dikembalikan dari git dan diulang dengan
  penggantian teks harfiah.

  **Pelajaran terpisah untuk skrip yang MENYUNTING kode, bukan yang membacanya:
  verifikasi setelah SETIAP jalan, bukan setelah semuanya selesai.**
  `git diff --stat` adalah pemeriksa paling murah yang ada - "1 file changed,
  315 deletions" pada penghapusan yang seharusnya membuang 30 baris sudah cukup
  untuk berhenti.

Pelajarannya: kalau alat melaporkan GAGAL pada kode yang menurut Anda benar,
periksa alatnya juga — jangan langsung menganggap kodenya yang salah.

Pelajaran keduanya lebih sempit dan sudah terbukti empat kali: **skrip audit
yang mencari token kode wajib membuang komentar dan isi string lebih dulu.**
Setiap kegagalan palsu di daftar di atas, kecuali dua yang pertama, sebabnya
persis itu.

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
- **`TELEGRAM_*` yang baru di `.env` tidak berlaku sampai `config:cache`
  dijalankan ulang** — dan bila `.env` produksi belum memuatnya sama sekali,
  yang berlaku adalah bawaan di `config/telegram.php`, bukan nol. Itu memang
  disengaja; lihat penjagaan nilai kosong di berkas itu
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
