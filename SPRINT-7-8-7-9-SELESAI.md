# Sprint 7.8–7.9 — Storage Monitoring, File Manager & Batch Upload

Selesai: 31 Juli 2026

Tiga modul terakhir Phase 7. Foundation (7.1), Storage Manager (7.2–7.3),
Storage Engine (7.4), Upload Service (7.5–7.6), dan Queue (7.7) tetap berdiri;
yang dilakukan sprint ini adalah menambah di atasnya. Perubahan pada modul lama
ada tiga, semuanya disebut terbuka di bawah beserta alasannya — tidak ada yang
disembunyikan.

Belum ada Telegram, `telegram_file_id`, deep link, streaming, video player,
CDN, AI compression, dan auto transcoding.

---

## Catatan pembuka: satu kekeliruan saya di awal sesi

Sesi ini dibuka dengan laporan bahwa `SPRINT-7-7-SELESAI.md` tidak ada dan
`STATUS.md` masih berhenti di 7.6. **Itu salah.** Keduanya ada dan sudah benar.

Sebabnya: pembacaan folder di awal sesi mengembalikan keadaan yang sudah basi —
`ls` tidak menampilkan berkas 7.7, dan `STATUS.md` yang terbaca adalah versi
sebelum diperbarui. Baru ketahuan ketika saya hendak menulis dokumen 7.7
"retroaktif" dan penulisannya ditolak karena berkasnya sudah ada.

Tidak ada yang tertimpa — penolakan itu terjadi sebelum satu byte pun ditulis.
Tetapi satu pertanyaan di awal sesi diajukan berdasarkan premis yang keliru,
dan jawabannya jadi tidak berlaku. Dicatat di sini supaya tidak terulang:
**berkas dokumentasi diperiksa ulang tepat sebelum ditulis, bukan hanya di awal
sesi.**

Kode 7.7 sendiri terbaca dengan benar sejak awal — seluruh audit dan seluruh
keputusan sprint ini berdiri di atas kode yang memang sedang berlaku.

---

## Yang dibangun

### 1. Storage Monitoring — `/admin/storage/monitor`

Halaman pengamatan. Total provider, aktif, nonaktif, terhubung, gagal
terhubung, belum pernah diuji, provider default beserta keadaannya, total
berkas, total ruang terpakai, unggahan hari ini, unggahan bulan ini, dan satu
baris tabel per provider dengan jumlah serta ukuran berkasnya.

Dua tombol: **Refresh Status** (membaca ulang database) dan **Test Connection**
per provider.

### 2. File Manager — `/admin/files`

Satu daftar untuk seluruh berkas yang dikenal aplikasi. Pencarian, empat
penyaring (sumber, jenis, provider, ekstensi), empat kolom yang bisa diurutkan,
pagination, pratayang gambar, unduh, salin URL, ganti nama, pindahkan, hapus.

### 3. Batch Upload — `/admin/upload/batch`

Banyak berkas sekali jalan, seluruhnya lewat antrean yang sama dengan unggahan
satuan. Video episode (dipetakan ke episode per berkas) dan aset drama (gambar
dan subtitle). Progress per berkas, dan kegagalan satu berkas tidak
menghentikan yang lain.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/StoredFileSource.php` | Abstraksi dua tabel sumber berkas |
| `app/Services/Storage/StorageMonitorService.php` | Angka untuk halaman monitoring |
| `app/Services/Storage/FileManagerService.php` | Daftar gabungan + rename/move/delete |
| `app/Services/Storage/StorageChoiceService.php` | Pilihan provider untuk form unggah |
| `app/Http/Controllers/Admin/StorageMonitorController.php` | Halaman monitoring |
| `app/Http/Controllers/Admin/FileManagerController.php` | Halaman File Manager |
| `app/Http/Controllers/Admin/BatchUploadController.php` | Halaman Batch Upload |
| `app/Http/Requests/Admin/StoreBatchUploadRequest.php` | Validasi satu berkas batch |
| `app/Jobs/ProcessDramaAssetUpload.php` | Job aset drama |
| `database/migrations/2026_07_31_120000_add_batch_targets_to_upload_jobs_table.php` | 4 kolom tujuan batch |
| `resources/views/web/pages/admin/storage-monitor.blade.php` | Halaman monitoring |
| `resources/views/web/pages/admin/file-manager.blade.php` | Halaman File Manager |
| `resources/views/web/pages/admin/batch-upload.blade.php` | Halaman Batch Upload |
| `tools/audit-sprint-7-8.py` | Self-audit sprint ini, 143 pemeriksaan |

## Berkas yang disunting

- `app/Services/Storage/Contracts/StorageEngineInterface.php` — **satu method baru**, `readStream()`
- `app/Services/Storage/StorageEngine.php` — implementasi `readStream()`
- `app/Services/UploadQueueService.php` — `createJob()`, `queueDramaAsset()`, `markAssetSuccess()`, `finishSuccess()`, `activeForAsset()`
- `app/Models/UploadJob.php` — 4 kolom, 2 relasi, scope `batch()`, label tujuan sadar-jenis
- `app/Http/Controllers/Admin/EpisodeVideoController.php` — dua helper dipindah ke `StorageChoiceService`
- `routes/web.php` — tiga grup route baru
- `resources/views/web/layouts/admin.blade.php` — tiga menu sidebar
- `resources/js/admin.js` — modul `storageMonitor()`, `fileManager()`, `batchUpload()`
- `resources/css/web/admin/admin.css` — 14 kelas baru

## Berkas yang dihapus

- `app/Models/EpisodeSubtitle.php` — kelas kosong tanpa tabel, tanpa migration, tanpa satu pun rujukan di seluruh proyek. Dead code sejak dibuat. Bisa dipulihkan dari git kalau ternyata memang disiapkan untuk sesuatu.

---

## Tiga perubahan pada modul lama, beserta alasannya

Spesifikasi berbunyi "tanpa mengubah Foundation, Storage Manager, Storage
Engine, Upload Service, maupun Queue yang sudah ada". Tiga hal di bawah
melanggarnya. Saya menyebutnya lebih dulu, bukan menyembunyikannya di tengah
dokumen.

### a. `readStream()` ditambahkan ke Storage Engine

**Aturan yang dilanggar:** Storage Engine tidak boleh diubah.

**Kenapa tetap dilakukan.** File Manager harus bisa mengunduh dan
memratayangkan berkas. Sebelum ini engine hanya bisa menyebutkan ALAMAT berkas
(`url()`, `temporaryUrl()`), bukan isinya. Untuk provider `local` — yang justru
dipakai setiap pemasangan baru, dan yang akan Anda uji lebih dulu — keduanya
menghasilkan `null`: tidak ada URL publik yang bisa ditebak, dan tidak ada
tanda tangan yang bisa dibuat. Tombol Unduh dan pratayang gambar akan mati
persis pada provider yang paling mungkin sedang dipakai.

Tiga pilihan yang ada, dan kenapa dua di antaranya lebih buruk:

1. Memanggil `Storage` atau `StorageManager::build()` dari controller. Itu
   membuat pintu kedua ke penyimpanan, dan menghancurkan satu-satunya aturan
   yang menjaga seluruh Phase 7 tetap konsisten sejak 7.4.
2. Membiarkan Unduh mati untuk provider lokal. Fitur yang diminta spesifikasi,
   mati pada konfigurasi bawaan.
3. Menambah satu method ke kontrak. Aditif — **tidak ada satu pun method lama
   yang berubah bentuk maupun perilakunya**, dan audit memverifikasi itu.

Yang ketiga dipilih. Pintu yang kurang satu daun bukan alasan membuat pintu
kedua.

### b. `UploadQueueService` diberi jalur kedua

**Aturan yang dilanggar:** Queue tidak boleh diubah.

**Kenapa tetap dilakukan.** Spesifikasi yang sama juga berbunyi "Tidak ada
duplicate upload logic". Menambah jenis unggahan kedua tanpa menyentuh
`UploadQueueService` berarti menyalin seluruh pementasan berkas, pembuatan
baris, pencatatan, dan pengiriman ke antrean ke tempat baru. Dua salinan itulah
duplicate upload logic yang dilarang.

Dua permintaan itu bertentangan, dan saya memilih yang mana pun akan melanggar
salah satunya. Yang dipilih adalah yang menghasilkan kode lebih sedikit.

**Yang berubah tepatnya:** isi `queueEpisodeVideo()` dipindahkan ke
`createJob()` dan `queueEpisodeVideo()` memanggilnya. Urutan langkahnya, isi
barisnya, dan isi log `queued`-nya sama persis dengan sebelumnya. Tanda
tangannya bertambah satu parameter opsional di ujung (`?string $batchUuid =
null`), sehingga semua pemanggil lama tetap benar tanpa disunting.

Hal yang sama berlaku pada `markSuccess()`: tanda tangannya utuh, isinya
diteruskan ke `finishSuccess()` yang juga dipakai `markAssetSuccess()`.

### c. Dua helper `EpisodeVideoController` dipindah ke service

**Aturan yang dilanggar:** Upload Service tidak boleh diubah.

**Kenapa tetap dilakukan.** `providerOptions()` dan `autoTarget()` menjawab dua
pertanyaan yang harus dijawab sama di setiap halaman unggah: provider mana yang
boleh dipilih, dan ke mana mode Auto mengirim. Batch Upload menanyakan
keduanya. Menyalinnya berarti dua definisi "provider yang boleh dipilih", dan
begitu salah satu diperbaiki, satu halaman akan menawarkan provider yang
halaman lain tolak.

**Yang berubah tepatnya:** kedua method tetap ada dengan nama yang sama dan
kini meneruskan ke `StorageChoiceService`. Syarat penyaringannya dan bentuk
labelnya disalin apa adanya. `EpisodeVideoService` — Upload Service yang
sebenarnya — **tidak disentuh sama sekali**.

---

## Keputusan desain

### File Manager membaca dua tabel, bukan tabel registry baru

Berkas tersebar di `episode_videos` (7.5) dan `drama_assets` (7.6). Bentuknya
hampir identik. Tiga cara menyatukannya di satu daftar:

1. **Tabel registry baru** yang ditulis semua modul. Paling rapi jangka
   panjang, tetapi mengharuskan `EpisodeVideoService` dan `DramaAssetService`
   diubah — dan berkas yang sudah terunggah sebelum registry ada tidak akan
   pernah terdaftar di sana. Daftar yang tidak lengkap lebih berbahaya daripada
   tidak ada daftar: yang tidak muncul akan disangka tidak ada.
2. **Membaca isi bucket.** Menampilkan juga berkas yatim, tetapi kehilangan
   nama asli, tanggal unggah, dan pemiliknya — semuanya hanya ada di database —
   sekaligus membuat halaman bergantung pada provider yang bisa saja sedang
   tidak bisa dihubungi.
3. **Membaca kedua tabel lewat satu abstraksi.** Nol tabel baru, nol duplikasi
   data, nol perubahan pada modul unggah.

Yang ketiga dipilih. `StoredFileSource` adalah abstraksinya: menambah modul
berkas ketiga nanti berarti menambah satu case beserta empat method-nya, dan
tidak ada tempat lain yang perlu tahu.

**Konsekuensinya disebut terus terang di halamannya:** berkas yatim — objek di
bucket yang barisnya sudah hilang — tidak muncul di File Manager. "File
Manager" mudah disangka penjelajah isi bucket, dan halaman itu mengatakan
sendiri bahwa ia bukan.

### UNION di database, bukan penggabungan di PHP

Mengambil seluruh baris kedua tabel, menggabungkannya di memori,
mengurutkannya, lalu memotong dua puluh baris pertama akan berhenti bekerja
begitu katalognya benar-benar terisi — dan berhentinya diam-diam, sebagai
halaman yang makin lama makin lambat. UNION membiarkan database yang
mengurutkan dan memotong, dan indeks `uploaded_at` yang sudah ada di kedua
tabel tetap terpakai.

Dua penjagaan di dalamnya:

- **Tidak ada satu pun binding (`?`) di dalam kedua sisi UNION.** Binding
  subquery dan binding query luar disusun terpisah oleh Laravel, dan
  mencampurnya adalah cara paling mudah mendapatkan nilai yang tertukar antar
  penyaring.
- **Pemecah seri pada pengurutan.** Tanpa `orderBy('source')` dan
  `orderBy('source_id')` sebagai tie-breaker, dua berkas dengan `uploaded_at`
  yang sama persis — yang justru biasa terjadi pada unggahan galeri — bisa
  muncul di urutan berbeda tiap kali halaman dimuat, dan satu di antaranya
  menghilang dari kedua halaman.

### Ganti nama tidak boleh mengganti ekstensi

Ekstensinya selalu dipertahankan, apa pun yang diketik admin. Dua sebabnya:
`mime_type` yang sudah tersimpan tidak ikut berubah sehingga baris yang
dihasilkan isinya saling bertentangan; dan daftar ekstensi terlarang di Storage
Engine (`php`, `phar`, `sh`, dan seterusnya) tidak boleh bisa dilewati lewat
jalur ganti nama.

### Hapus di File Manager berbeda kebijakan dari hapus di Asset Manager

Sengaja, dan keduanya benar untuk tempatnya masing-masing.

`DramaAssetService::delete()` (7.6) menghapus barisnya **meskipun** objeknya
gagal dihapus — di sana yang dipentingkan adalah admin tidak melihat aset yang
dikiranya sudah hilang.

`FileManagerService::delete()` **tidak** menghapus baris kalau objeknya gagal
dihapus, dan galatnya dilempar. Di sini baris adalah satu-satunya catatan bahwa
objek itu ada; membuangnya berarti membuang satu-satunya cara menemukan berkas
yang gagal dihapus tadi.

Keduanya bukan duplikasi — kebijakannya memang berbeda.

### Angka monitoring dari database, bukan dari bucket

Menghitung dari bucket berarti operasi list ke setiap provider setiap kali
halaman dibuka: lambat, berbayar pada sebagian provider, dan halamannya macet
total begitu satu provider tidak bisa dihubungi. Angka dari database selalu
bisa ditampilkan, dan yang diukur pun lebih tepat — yang ingin diketahui admin
adalah "berapa berkas yang dikenal aplikasi ini", bukan "berapa objek yang
kebetulan ada di bucket".

### Refresh Status tidak menguji koneksi

Membaca ulang database saja. Menguji koneksi adalah tombol yang berbeda, dengan
biaya dan waktu tunggu yang berbeda pula. Menggabungkannya berarti tombol
"segarkan angka" diam-diam mengirim permintaan jaringan ke sembilan provider.

Refresh juga tidak memuat ulang halaman: hasil Test Connection yang sedang
dibaca ada di panel yang menetap, dan memuat ulang akan membuangnya justru
ketika pesannya paling perlu dibaca.

### Batch: satu berkas, satu permintaan HTTP, satu pekerjaan antrean

Ini yang memenuhi dua permintaan spesifikasi sekaligus:

- **"Tampilkan progress upload per file"** memerlukan
  `XMLHttpRequest.upload.onprogress`, dan itu berlaku per permintaan. Satu
  permintaan berisi dua puluh berkas hanya punya satu angka, dan angkanya
  melompat tanpa ada yang tahu berkas mana yang sedang jalan.
- **"Jika satu file gagal, file lainnya tetap diproses"** dijamin di dua tahap:
  saat diterima (berkas yang ditolak menghasilkan satu respons 422 untuk berkas
  itu saja) dan saat dikirim ke provider (job yang gagal ditandai di barisnya
  sendiri). Dalam satu permintaan besar, validasi Laravel menolak SELURUH
  payload begitu satu elemen `files.*` tidak lolos.

Berurutan, bukan bersamaan: dua puluh unggahan paralel berebut lebar pita yang
sama sehingga semuanya lambat, dan sebagian server menutup koneksi yang terlalu
banyak dari satu klien.

Harganya disebut juga: `post_max_size` tetap berlaku per permintaan, dan dua
puluh permintaan berurutan lebih lambat daripada satu. Untuk berkas yang
ukurannya memang besar, yang menentukan adalah waktu pengiriman, bukan jumlah
permintaannya.

### Kolom `batch_uuid`, bukan pasangan polymorphic

`target_type` + `target_id` akan lebih pendek, tetapi menghilangkan foreign
key. Tanpa foreign key, menghapus sebuah drama meninggalkan baris antrean yang
menunjuk drama yang tidak ada — sementara `episode_id` yang sudah ada di tabel
itu justru ikut terhapus lewat cascade. Dua bentuk penanganan yang berbeda pada
satu tabel yang sama tidak sebanding dengan dua kolom yang dihemat.

### Dua kelas job, bukan satu yang bercabang

Sempat dicoba dan hasilnya lebih buruk: satu `handle()` yang bercabang pada
`type`, dua service yang di-inject padahal hanya satu dipakai tiap kali, dua
bentuk hasil yang harus dibedakan sebelum disimpan, dan dua pesan galat yang
harus dipilih. Yang benar-benar sama di antara keduanya — perpindahan status,
pembacaan ulang di dalam kunci, pementasan berkas, pencatatan, penanganan batas
waktu — sudah berada di `UploadQueueService` dan tidak ditulis dua kali.

### Penanda batch diperiksa kepemilikannya

Nilai `batch` dari peramban hanya diterima kalau batch itu memang milik admin
yang sedang masuk. Tanpa pemeriksaan itu, siapa pun yang bisa membuka halaman
ini bisa menempelkan unggahannya ke batch milik admin lain — bukan kebocoran
data, tetapi riwayat yang berbohong tentang siapa melakukan apa.

### Route memakai dua segmen, bukan referensi bergabung

`/admin/files/{source}/{id}`, bukan `/admin/files/episode_video:12`. Titik dua
di dalam segmen URL selamat melewati router, tetapi `route()` meng-encode-nya
menjadi `%3A` dan perlakuan proxy terhadapnya berbeda-beda. Bentuk dua segmen
tidak punya masalah itu sama sekali, dan `whereIn` menjaga agar sumber yang
tidak dikenal ditolak router alih-alih menjadi query ke database.

---

## Bug yang ditemukan dan diperbaiki

### `public_url` bisa terhapus saat berkas dipindahkan

Ditemukan saat membaca ulang jalur rename/move, sebelum sempat dijalankan.

`StorageEngine::relocate()` menyusun hasilnya lewat `describe()`, yang membaca
visibility dari penyimpanan — dan sebagian provider tidak melaporkannya. Ketika
itu terjadi, engine jatuh ke `private`, `StoredFile::url` menjadi `null`, dan
menyalinnya apa adanya ke kolom `public_url` akan **mengosongkan** URL sebuah
poster yang tadinya terisi, hanya karena berkasnya diganti nama.

Gejalanya sangat buruk untuk ditelusuri: gambar hilang dari beranda, tanpa satu
pun pesan galat di panel, dan penyebabnya adalah tindakan yang kelihatannya
tidak berbahaya.

Diperbaiki di `FileManagerService::urlSetelahPindah()`: berkas yang tadinya
punya URL publik disusunkan URL barunya lewat `StorageEngine::url()`, yang
tidak bergantung pada visibility yang dilaporkan disk.

### `episodes.video_url` ikut tertinggal

Kolom itu dipakai pemutar yang sudah ada sejak sebelum multi storage. Rename,
move, dan delete di File Manager semuanya menyentuhnya sekarang — tanpa itu,
memindahkan berkas video membuat pemutar tetap meminta alamat lama, dan yang
muncul di pengguna adalah pemutar yang gagal memuat.

### Skrip self-audit saya sendiri melapor GAGAL palsu

Versi pertama `tools/audit-sprint-7-8.py` memeriksa "setiap route punya
middleware permission" dengan mengambil 3000 karakter setelah nama grupnya.
Jendela tetap itu menembus ke grup berikutnya, dan hasilnya:

```
GAGAL  files.: 9 middleware permission untuk 17 route
```

Grup `files.` sebenarnya berisi **6 route dengan 6 middleware**. Yang salah
alatnya, bukan route-nya.

Diperbaiki dengan mencocokkan kurung, bukan menghitung karakter. Ini kegagalan
palsu keempat dari alat pemeriksa proyek ini — dan yang kedua yang disebabkan
oleh penghitungan kurung yang naif, setelah `routeparse.py` di 7.6.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       362 berkas, 0 bermasalah
python tools/check-css-coverage.py        241 kelas, semua punya aturan
python tools/check-blade-directives.py    70 blade, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
node (sintaks admin.js)                   valid
```

Yang diperiksa self-audit, ringkasnya:

- Controller, service, dan job baru: nol `Storage::`, nol
  `->store()`/`->storeAs()`, nol `->disk(`, nol `file_put_contents()`, nol
  `move_uploaded_file()` — diperiksa setelah komentar dibuang
- `FileManagerService` memakai `engine->rename/move/delete/readStream/
  temporaryUrl/url`, dan menerima `StorageEngineInterface` bukan implementasi
- Seluruh method lama kontrak engine masih diimplementasikan
- Hanya satu `stage()`, satu `createJob()`, satu `UploadJob::create()`, satu
  `finishSuccess()` di `UploadQueueService`
- Tepat dua job unggah, keduanya memanggil service modulnya sendiri
- `EpisodeVideoController` tidak lagi menyusun daftar provider sendiri
- Controller batch mengantrekan lewat `UploadQueueService`, bukan memanggil
  service unggah langsung
- Empat kolom migration ada di `$fillable`, dan `down()` melepas foreign key
  sebelum kolomnya dibuang
- Tiga grup route: setiap route punya middleware permission (6/6, 3/3, 3/3)
- Setiap method yang dirujuk route benar-benar ada di controllernya
- Tiap view dirender controllernya; tiap menu sidebar menunjuk route yang ada
- Tiga modul JS ada dan dipanggil `admin()`
- Tidak ada path `/admin/` yang ditulis tangan di `admin.js`
- Tidak ada import yang tidak terpakai di berkas baru
- Semua `match()` di `StoredFileSource` menangani seluruh case

**Seluruh verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
migration yang dijalankan, tidak ada halaman yang dirender, dan tidak ada form
yang dikirim. Yang hanya bisa dibuktikan di browser ada di bagian pengujian.

---

## Belum dikerjakan (sengaja)

### Subtitle banyak sekaligus

Spesifikasi meminta "Multiple Subtitle". Yang bisa dikerjakan hanya sebagian,
dan sebabnya struktural.

Subtitle yang ada sekarang adalah subtitle **tingkat drama**
(`DramaAssetType::SUBTITLE`), dan jenis itu hanya boleh punya satu berkas per
drama — `DramaAssetService` memakai `updateOrCreate` pada `(drama_id,
asset_type)`. Mengunggah sepuluh subtitle ke sana berarti sembilan di antaranya
menimpa yang sebelumnya. Batch Upload karena itu menerimanya, tetapi menolak
lebih dari satu berkas dengan pesan yang menyebutkan sebabnya.

"Multiple Subtitle" yang benar-benar berguna adalah subtitle **per episode** —
dan modul itu tidak ada: tidak ada tabel `episode_subtitles`, tidak ada
migration, tidak ada service. Yang ada hanyalah `app/Models/EpisodeSubtitle.php`
berisi kelas kosong tanpa satu pun rujukan, yang saya hapus di sprint ini.
Keberadaannya justru menunjukkan modul itu pernah direncanakan lalu tidak
pernah dibangun.

Membangunnya sekarang berarti satu modul baru penuh — migration, model,
service, validasi, jalur antrean — di sprint yang sudah membangun tiga modul
dan tidak bisa menjalankan satu baris PHP pun untuk mengujinya. Saya memilih
mengatakannya daripada menumpuk kode yang tidak teruji.

### Perpindahan berkas antar provider

File Manager memindahkan berkas antar direktori **di provider yang sama**.
Antar provider adalah operasi yang berbeda sifatnya: perlu mengalirkan isi
berkas dari satu penyimpanan ke penyimpanan lain, tahan terhadap kegagalan di
tengah jalan, dan untuk video berukuran gigabyte berjalan cukup lama sehingga
tidak boleh berada di dalam sebuah request. Tempatnya di antrean, sebagai
pekerjaannya sendiri.

### Lainnya

- Pratayang video dan audio — `<video>` yang menunjuk berkas gigabyte akan
  membuat peramban mulai mengunduhnya hanya karena panelnya dibuka
- Aksi massal di File Manager (hapus banyak berkas sekaligus)
- Test Connection massal dan Test Connection di antrean
- Verifikasi checksum terhadap berkas di bucket
- Pembersihan berkas yatim di bucket
- Telegram, `telegram_file_id`, deep link, streaming, video player, CDN, AI
  compression, auto transcoding

---

## Batasan yang perlu Anda tahu

- **Batch video memerlukan izin `episode.manage`.** Daftar episodenya dibaca
  dari `admin.episode.video.episodes`, endpoint milik modul 7.5 yang dilindungi
  izin itu. Peran yang hanya punya `upload.manage` akan melihat pesan galat di
  halaman batch, bukan dropdown kosong tanpa penjelasan. Dalam `RoleSeeder`
  saat ini tidak ada peran seperti itu — Editor punya keduanya — jadi
  praktisnya tidak terasa.
- **Halaman Batch Upload memerlukan JavaScript.** Tidak ada jalur cadangan, dan
  itu disengaja: pemetaan berkas ke episode dan pengiriman satu-per-satu memang
  tidak mungkin tanpa JavaScript, dan form yang tampak bisa dikirim tetapi
  hanya mengunggah satu berkas pertama akan lebih menyesatkan daripada tombol
  yang mati.
- **Salin URL memerlukan konteks aman.** `navigator.clipboard` hanya tersedia
  di HTTPS atau localhost. Ada jalur cadangan `document.execCommand('copy')`
  untuk panel yang diakses lewat HTTP biasa, dan bila keduanya gagal tombolnya
  mengatakan begitu — URL-nya tetap tampil sebagai teks yang bisa disalin
  manual.

---

## Siap dipakai Phase 8 (Telegram)

- `UploadQueueService::queueDramaAsset()` dan `queueEpisodeVideo()` menerima
  `UploadedFile`. Berkas dari Telegram perlu dibungkus jadi `UploadedFile`
  lebih dulu, lalu jalur antreannya sama persis.
- Kolom `type` di `upload_jobs` sudah menampung dua nilai dan siap menampung
  yang ketiga tanpa migration yang mengubah bentuk tabel.
- `StorageEngineInterface::readStream()` menyediakan isi berkas untuk dikirim
  ke Telegram tanpa perlu URL publik — yang penting untuk video privat.
- `StoredFileSource` menyediakan tempat menambahkan sumber berkas ketiga.
