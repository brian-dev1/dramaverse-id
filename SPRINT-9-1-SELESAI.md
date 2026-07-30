# Phase 9 — Production Ready (Sprint 9.1–9.5)

Selesai: 31 Juli 2026

Lapisan operasional di atas seluruh arsitektur Phase 7 dan Phase 8 — **tanpa
mengubah satu pun alur bisnis yang sudah berjalan**.

Belum ada payment gateway, subscription billing, revenue report,
recommendation engine, fitur AI, dan mobile app.

---

## Gagasan yang menentukan seluruh rancangan

Phase 9 menambahkan lapisan yang **melihat** sistem, bukan yang mengubahnya.
Dan lapisan seperti itu punya satu cara khas untuk gagal: ia menulis ulang
pemeriksaan yang sudah ada di tempat lain.

Storage sudah punya `StorageMonitorService` sejak 7.8. Telegram sudah punya
`TelegramHealthService` sejak 8.9. Kalau dashboard Phase 9 memeriksanya
sendiri, akan ada dua jawaban tentang sistem yang sama — dan pada hari
keduanya berbeda, tidak akan ada yang tahu mana yang benar.

Karena itu `SystemHealthService` **memanggil** keduanya. Yang benar-benar
baru hanya empat: basis data, cache, scheduler, dan server. Ada pemeriksaan
otomatis yang melarang `StorageProvider::` dan `->getMe(` muncul di dalamnya.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Services/Monitoring/SystemHealthService.php` | Sembilan pemeriksaan, tidak pernah melempar |
| `app/Services/Monitoring/AlertService.php` | Peringatan berpenahan untuk seluruh sistem |
| `app/Services/Backup/BackupService.php` | Buat, verifikasi, pangkas |
| `app/Support/LogFileReader.php` | Pembaca ekor berkas log, dipakai bersama |
| `app/Jobs/RunBackupJob.php` | Cadangan di antrean |
| `app/Console/Commands/BackupRun.php` | `backup:run [--verify\|--prune\|--list]` |
| `app/Listeners/LogAuthenticationEvents.php` | Jejak audit masuk, keluar, gagal, terkunci |
| `app/Http/Controllers/Admin/MonitoringController.php` | Dashboard + pengelolaan cadangan |
| `app/Http/Controllers/Admin/SystemLogController.php` | Log seluruh sistem |
| `config/backup.php` | Tiga kunci, ketiganya dibaca kode |
| `database/migrations/..._add_production_indexes.php` | Enam indeks, seluruhnya aditif |
| `resources/views/web/pages/admin/monitoring.blade.php` | Dashboard |
| `resources/views/web/pages/admin/system-log.blade.php` | Log sistem |
| `tools/audit-sprint-9-1.py` | 117 pemeriksaan |

## Berkas yang disunting

| Berkas | Perubahan |
|---|---|
| `app/Services/Telegram/TelegramAlertService.php` | Meneruskan ke `AlertService`, penahannya dibuang |
| `app/Http/Controllers/Admin/TelegramLogController.php` | Memakai `LogFileReader` |
| `app/Providers/AppServiceProvider.php` | Empat listener autentikasi |
| `routes/console.php` | Detak scheduler + jadwal cadangan harian |
| `routes/web.php`, layout admin | 6 route + 2 menu |
| `.env.example` | Tiga kunci cadangan |

---

## Keputusan desain

### Detak scheduler — pemeriksaan yang paling sering terlupa dipasang

Scheduler yang **tidak pernah berjalan sama sekali** terlihat persis sama
dengan scheduler yang berjalan normal: tidak ada galat, tidak ada log, tidak
ada apa pun. Sementara seluruh otomatisasi Telegram (8.9) dan seluruh cadangan
(9.3) bergantung padanya.

`Schedule::call()` tiap menit menulis satu nilai ke cache. Dashboard membaca
umurnya dan menandai merah bila lebih dari sepuluh menit. Ini satu-satunya
cara membedakan "cron belum dipasang" dari "semuanya baik-baik saja", dan
keduanya tampak identik tanpa penanda ini.

### Cache diperiksa dengan menulis lalu membaca

Membaca `config('cache.default')` tidak membuktikan apa pun. Redis yang mati
tetap terkonfigurasi dengan benar sampai ada yang mencoba memakainya. Jadi
pemeriksaannya benar-benar `put`, `get`, bandingkan, `forget`.

Hal yang sama berlaku untuk basis data: `getPdo()` lalu `select 1`, bukan
sekadar membaca nama koneksinya.

### Video TIDAK dicadangkan, dan itu keputusan

Video ada di storage provider yang sudah punya ketahanannya sendiri,
ukurannya ratusan gigabyte, dan menyalinnya ke disk VPS yang sama justru
menghabiskan ruang yang dibutuhkan operasi normal.

Yang dicadangkan adalah **petanya**: tabel `episode_videos` dan `drama_assets`
berisi `provider_id` dan `object_key`. Itulah yang benar-benar tidak bisa
dibangun ulang kalau hilang — berkas di bucket tanpa petanya adalah ratusan
gigabyte objek bernama UUID yang tidak ada yang tahu milik episode mana.

### `.env` ikut dicadangkan, dan itu berbahaya

Tanpa `APP_KEY` yang sama, basis data hasil restore tidak bisa dibuka:
seluruh kredensial storage provider tersimpan terenkripsi, dan kunci yang
berbeda membuatnya jadi sampah yang tidak bisa dipulihkan dengan cara apa pun.

Jadi `.env` masuk ke arsip. Konsekuensinya disebut terus terang di tiga
tempat — docblock service, `config/backup.php`, dan halaman monitoring:
**berkas cadangan memuat APP_KEY, kredensial basis data, dan token bot dalam
bentuk teks polos.** Arsipnya di-`chmod 0600`, foldernya di luar `public/`,
dan setiap unduhan tercatat di `activity_logs`.

### Kata sandi mysqldump lewat environment, bukan argumen

Argumen baris perintah terlihat di `ps aux` oleh **setiap** pengguna di
server. `mysqldump --password=rahasia` adalah cara paling mudah membocorkan
kata sandi basis data tanpa menyadarinya.

`MYSQL_PWD` di environment proses. Ada pemeriksaan otomatis yang melarang
string `--password` muncul di service ini.

### Cadangan diverifikasi tepat setelah dibuat

Cadangan yang tidak pernah diperiksa bukan cadangan — ia baru diketahui rusak
pada satu-satunya kali ia dibutuhkan.

`tar -tzf` membongkar seluruh daftar isi tanpa menuliskannya: kalau gzip-nya
rusak atau arsipnya terpotong, ini yang menangkapnya. Murah, dan dijalankan
otomatis.

Dump yang terlalu kecil juga ditolak sebelum dibungkus. `mysqldump` yang gagal
di tengah jalan kadang tetap keluar dengan status sukses dan meninggalkan
berkas beberapa ratus byte — yang terlihat persis seperti cadangan sah di
daftar berkas.

### Kegagalan cadangan adalah peringatan KRITIS

Satu-satunya jenis peringatan yang melewati penahan. Cadangan yang gagal
diam-diam adalah cadangan yang dikira ada sampai hari ia dibutuhkan, dan
menahannya selama 30 menit berarti tiga kegagalan berturut-turut hanya
menghasilkan satu pesan.

### Penahan peringatan diangkat, bukan disalin

Sprint 8.9 membangun penahan di dalam `TelegramAlertService`. Phase 9 butuh
peringatan yang sama untuk cadangan, antrean, penyimpanan, dan basis data.

Menyalinnya berarti empat salinan dari satu aturan. Jadi aturannya pindah ke
`AlertService`, dan `TelegramAlertService` jadi kosakata khusus Telegram yang
meneruskan ke sana. **Tanda tangan seluruh method-nya tidak berubah**, jadi
pemanggil sejak 8.9 tidak perlu disentuh sama sekali.

### Pembaca log diangkat dengan alasan yang sama

Sprint 8.9 menaruh pembacaan ekor berkas di dalam `TelegramLogController`.
Begitu Log Sistem membutuhkannya, pilihannya dua: menyalin, atau mengangkat.

`LogFileReader` sekarang memegang parsernya. Dua controller memakainya,
bedanya cuma penyaring awalan. Ada pemeriksaan otomatis yang melarang `fseek`
muncul di controller mana pun.

### Nama berkas cadangan: tiga penjagaan, bukan satu

Nama yang datang dari luar dan langsung digabung ke path adalah cara membaca
berkas mana pun di server lewat `../`.

1. Pola nama yang ketat (`dramaverse-<angka>.tar.gz`).
2. `realpath` pada hasil gabungannya.
3. Hasil `realpath` wajib berada di dalam folder cadangan.

Yang ketiga menangkap symlink yang menunjuk keluar — yang lolos dari dua yang
pertama.

### Jejak audit autentikasi ditulis langsung, bukan lewat ActivityLogger

`ActivityLogger::log()` mengambil `Auth::id()` sendiri. Pada peristiwa
`Logout` pengguna sudah dilepas, dan pada `Failed` tidak pernah ada pengguna
yang masuk — keduanya akan tercatat dengan `user_id` kosong, yaitu justru
bagian yang paling ingin diketahui.

Empat peristiwa dicatat: masuk, keluar, gagal masuk, dan **terkunci**. Yang
terakhir yang membedakan salah ketik dari serangan: `Failed` wajar terjadi,
`Lockout` tidak.

Email percobaan gagal ikut dicatat. **Kata sandinya tidak** — tidak pernah,
dalam keadaan apa pun, dan ada pemeriksaan otomatis untuk itu.

Pencatatan yang gagal ditangkap dan tidak dilempar ulang: pengguna yang tidak
bisa login karena tabel audit bermasalah adalah akibat yang jauh lebih buruk
daripada satu baris audit yang hilang. Log berkas tetap ditulis lebih dulu,
jadi jejaknya tidak hilang seluruhnya.

### Migration indeks dibungkus pemeriksaan keberadaan

Seluruhnya aditif: tidak ada kolom yang berubah, tidak ada data yang
disentuh, dan aplikasi berjalan persis sama tanpanya — hanya lebih lambat
seiring bertambahnya baris.

Setiap indeks diperiksa dulu keberadaannya, begitu juga tabel dan kolomnya.
`CREATE INDEX` yang menabrak indeks yang sudah ada akan menghentikan
`deploy.sh` di langkah migrate — kegagalan yang tidak perlu untuk perubahan
yang seharusnya tidak berisiko sama sekali.

### Alat pemeriksa tidak boleh melempar

Seluruh method `SystemHealthService` menangkap galatnya sendiri. Dashboard
yang mati saat basis data bermasalah menghilangkan satu-satunya halaman yang
bisa memberi tahu apa yang sedang terjadi.

Ada pemeriksaan otomatis yang melarang `throw` muncul di kelas itu.

### Route cache dan kawan-kawannya sudah ada

`deploy.sh` sudah menjalankan `optimize:clear`, lalu `config:cache`,
`route:cache`, `view:cache`, dan `event:cache` sejak sebelum sprint ini.
Menambahkannya lagi hanya akan menghasilkan dua tempat yang harus dijaga
tetap sama. Yang dilakukan sprint ini adalah **memeriksanya** lewat audit
otomatis, supaya tidak diam-diam hilang.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    75 blade, 0 bermasalah
python tools/check-css-coverage.py        257 kelas, semua punya aturan
python tools/check-php-structure.py       405 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
python tools/audit-sprint-8-1.py          81/81 lolos
python tools/audit-sprint-8-2.py          125/125 lolos
python tools/audit-sprint-8-7.py          133/133 lolos
python tools/audit-sprint-9-1.py          117/117 lolos
```

### Satu GAGAL palsu dari skrip audit saya sendiri

`audit-sprint-9-1.py` versi pertama melaporkan "monitoring tidak memanggil
Telegram API sendiri" GAGAL. Kodenya benar — yang salah pemeriksaannya: ia
mencari substring `getMe`, dan **`$e->getMessage()` memuat huruf-huruf itu**.

Kegagalan palsu kedelapan yang tercatat di proyek ini, dan yang kedua
disebabkan pencocokan substring pada nama method. Diperbaiki dengan
mencocokkan `->getMe(` beserta tanda kurungnya.

### Lima GAGAL di `audit-sprint-8-7.py`, dan itu bukan regresi

Refactor sprint ini memindahkan penahan peringatan ke `AlertService` dan
pembacaan log ke `LogFileReader`. Audit 8.7 masih memeriksanya di berkas
lama.

Kelimanya diperbarui untuk memeriksa **invariannya**, bukan lokasi kodenya —
sama seperti tiga asersi audit 8.2 yang diperbarui di sprint sebelumnya.
Pemeriksaan yang menguji lokasi akan selalu menghalangi refactor yang benar,
dan itu pola yang sekarang sudah muncul dua kali berturut-turut.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
`mysqldump` yang benar-benar berjalan, tidak ada cadangan yang benar-benar
dibuat, tidak ada scheduler yang berdetak.

---

## Yang belum tersambung, dan saya sebutkan terus terang

Anda menulis "nanti finishing baru dibenerin yang belum sinkron", jadi
berikut daftarnya apa adanya.

- **`mysqldump` dan `tar` belum pernah dijalankan dari sini.** Keduanya wajib
  ada di server. Kalau tidak ada, `backup:run` akan gagal dengan pesan yang
  menyebutkan persis itu — tetapi saya tidak bisa membuktikannya tanpa
  menjalankannya.
- **Migration indeks belum pernah dijalankan.** Ia memakai `SHOW INDEX`, yang
  hanya berlaku di MySQL. Di driver lain pemeriksaannya dilewati dan
  `Schema::table` yang memutuskan.
- **Notifikasi belum memakai Laravel Notification.** Yang dipakai
  `AlertService` — log + pesan Telegram. Membangunnya di atas
  `Illuminate\Notifications` berarti tabel `notifications`, channel, dan
  halaman yang membacanya; itu pekerjaan tersendiri, dan yang ada sekarang
  sudah sampai ke operator.
- **Policy belum dibuat.** Otorisasi masih lewat middleware `permission:*`
  yang sudah ada sejak Sprint 6, dan itu memang menjaga seluruh route admin.
  Policy per-model baru berguna kalau ada aturan kepemilikan per baris —
  belum ada di sini.
- **Restore belum otomatis.** Prosedurnya ada di bawah, tetapi tidak ada
  tombol yang menjalankannya. Restore yang bisa dipicu dari panel adalah
  tombol yang bisa menghapus seluruh basis data produksi karena salah klik.
- **Cadangan hanya ada di server yang sama.** Ia melindungi dari tabel yang
  terhapus dan migration yang salah, **bukan** dari server yang hilang.
  Menyalinnya keluar server belum otomatis.
- **Statistik galat dibaca dari 2 MB terakhir berkas log**, bukan dari seluruh
  riwayat. Itu memang yang dimaksud — yang penting apakah galat sedang
  terjadi sekarang — tetapi angkanya bukan total sejak dulu.

---

## Prosedur restore

Ditulis di sini, bukan sebagai tombol. Baca seluruhnya sebelum menjalankan
baris pertama.

```bash
# 1. Salin arsipnya ke server, lalu bongkar di folder sementara
mkdir -p /tmp/restore && cd /tmp/restore
tar -xzf dramaverse-2026-07-31_023000.tar.gz
ls -la          # harus ada satu berkas .sql dan env.backup

# 2. Bandingkan APP_KEY lebih dulu — INI LANGKAH YANG PALING MUDAH TERLEWAT
grep APP_KEY env.backup
grep APP_KEY /var/www/dramaverse/.env
# Kalau berbeda: kredensial storage di dump TIDAK akan bisa dibaca oleh
# aplikasi yang sedang berjalan. Pakai APP_KEY dari env.backup, atau
# masukkan ulang seluruh kredensial provider lewat panel setelah restore.

# 3. Cadangkan keadaan SEKARANG sebelum menimpanya
cd /var/www/dramaverse && php artisan backup:run

# 4. Matikan situs dan worker supaya tidak ada yang menulis saat restore
php artisan down
supervisorctl stop all

# 5. Restore
mysql -u <user> -p <nama_database> < /tmp/restore/dramaverse-*.sql

# 6. Nyalakan kembali
php artisan optimize:clear
php artisan config:cache
supervisorctl start all
php artisan up

# 7. Buktikan
php artisan storage:test
php artisan telegram:test
```

Buka `/admin/monitoring` sesudahnya. Seluruh bagian harus hijau, dan
`/admin/storage` harus menampilkan provider yang kredensialnya masih terbaca.
