# Sprint 7.7 — Queue & Background Upload

Selesai: 31 Juli 2026

Pengiriman video episode ke storage provider dipindahkan ke background lewat
Laravel Queue. Storage Engine (7.4) dan modul unggah video (7.5) **tidak
diubah satu baris pun** — yang berpindah hanyalah siapa yang memanggilnya.
Belum ada Telegram, monitoring, streaming, dan retry otomatis berlapis.

---

## Yang sebenarnya dipindahkan ke background

Perlu ditegaskan lebih dulu, karena mudah disalahpahami dan menentukan seluruh
rancangan sprint ini.

Sebuah unggahan punya dua bagian:

1. **Peramban ke server.** Byte-nya datang lewat request HTTP itu sendiri.
   Bagian ini **tidak bisa** dipindahkan ke background oleh rancangan mana pun.
   Yang ada hanyalah progress bar, dan itu sudah ada sejak 7.5.
2. **Server ke storage provider.** Inilah yang lambat: berkas 4 GB ke bucket
   R2 bisa memakan puluhan menit, dan selama itu request admin menggantung,
   satu proses PHP-FPM terpakai penuh, dan `max_execution_time` mengintai.

Sprint ini memindahkan bagian kedua. Setelah berkasnya sampai di server,
respons dikirim dalam hitungan milidetik dan sisanya dikerjakan worker.

Kalau ada yang mengatakan "upload sekarang tidak blocking sama sekali", itu
tidak benar, dan saya tidak ingin dokumen ini ikut mengatakannya.

---

## Alur lengkap

```
Peramban  --XHR-->  EpisodeVideoController::store()
                        |  validasi (StoreEpisodeVideoRequest)
                        |  tolak kalau episode ini sudah punya pekerjaan berjalan
                        v
                    UploadQueueService::queueEpisodeVideo()
                        |  pindahkan berkas ke storage/app/upload-queue
                        |  buat baris upload_jobs (status: pending)
                        |  catat log `queued`
                        v
                    dispatch ProcessEpisodeVideoUpload  --> antrean `uploads`
                        |
                    202 Accepted  -->  peramban mulai polling status
                        |
   [worker]         ProcessEpisodeVideoUpload::handle()
                        |  markProcessing()  (lockForUpdate: pending -> processing)
                        |  bangun UploadedFile dari berkas staging
                        |  Auth::setUser(pengunggah)
                        v
                    EpisodeVideoService::upload()      <-- MODUL 7.5, TIDAK DIUBAH
                        v
                    StorageEngineInterface::upload()   <-- MODUL 7.4, TIDAK DIUBAH
                        v
                    StorageManager -> disk provider
```

Rantainya tetap satu arah dan tetap punya satu pintu. `UploadQueueService`,
`ProcessEpisodeVideoUpload`, `UploadQueueController`, dan
`EpisodeVideoController` sama-sama nol `Storage::`, nol `->store()`,
nol `disk(`.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/UploadStatus.php` | Lima status + aturan perpindahannya |
| `database/migrations/2026_07_31_090000_create_upload_jobs_table.php` | Tabel antrean |
| `database/migrations/2026_07_31_090100_create_upload_job_logs_table.php` | Log per pekerjaan |
| `app/Models/UploadJob.php` | Model |
| `app/Models/UploadJobLog.php` | Model log |
| `app/Services/UploadQueueService.php` | Siklus hidup pekerjaan |
| `app/Jobs/ProcessEpisodeVideoUpload.php` | Job worker |
| `app/Http/Controllers/Admin/UploadQueueController.php` | Halaman + endpoint |
| `app/Console/Commands/UploadQueuePrune.php` | `php artisan upload:prune` |
| `resources/views/web/pages/admin/upload-queue.blade.php` | Halaman Upload Queue |

## Berkas yang disunting

- `app/Http/Controllers/Admin/EpisodeVideoController.php` — `store()` mengantrekan
- `app/Providers/AuthServiceProvider.php` — izin `upload.view`, `upload.manage`
- `database/seeders/RoleSeeder.php` — Editor mendapat kedua izin itu
- `routes/web.php` — lima route `admin.upload.*`
- `config/queue.php` — koneksi `uploads` dengan `retry_after` panjang
- `config/storage.php` — bagian `queue`
- `.env.example` — enam variabel baru
- `resources/views/web/layouts/admin.blade.php` — menu sidebar
- `resources/views/web/pages/admin/episode-video.blade.php` — keadaan antrean
- `resources/js/admin.js` — polling status + modul `uploadQueue()`
- `resources/css/web/admin/admin.css` — 12 kelas baru
- `tools/verify-consistency.py` — dua model baru masuk pemeriksaan fillable

## Berkas yang dihapus

- `app/Jobs/UploadMediaJob.php` — job kosong dari sprint lama. Namanya
  menjanjikan persis pekerjaan sprint ini, tapi `handle()`-nya tidak berisi
  apa pun dan tidak ada satu pun yang memanggilnya. Job yang tampak ada tetapi
  diam-diam tidak melakukan apa-apa adalah jebakan; siapa pun yang menemukannya
  akan wajar mengira unggahan sudah punya jalur antrean.

---

## Lima status

| Status | Arti | Bisa dibatalkan | Bisa diulang |
|---|---|---|---|
| Menunggu (`pending`) | Sudah di antrean, belum diambil worker | ya | tidak |
| Diproses (`processing`) | Worker sedang mengirim ke provider | tidak | tidak |
| Berhasil (`success`) | Video ada di provider, metadata tersimpan | tidak | tidak |
| Gagal (`failed`) | Percobaan habis | tidak | ya |
| Dibatalkan (`cancelled`) | Admin membatalkan sebelum diproses | tidak | tidak |

Perpindahan yang sah hanya lima: `pending->processing`, `pending->cancelled`,
`processing->success`, `processing->failed`, dan `failed->pending` (Retry).

---

## Keputusan desain

### `CANCELLED` ditambahkan, tidak digabung ke `FAILED`

Spesifikasi menyebut empat status. Yang kelima lahir dari permintaan yang sama:
"Cancel Upload sebelum diproses" harus punya keadaannya sendiri.

Menandai pembatalan sebagai Gagal mencampur dua hal yang berbeda. Kegagalan
perlu ditelusuri dan diulang; pembatalan adalah keputusan sadar admin dan tidak
memerlukan tindakan siapa pun. Kalau disatukan, daftar "yang gagal" akan penuh
baris yang sebenarnya baik-baik saja, dan yang benar-benar gagal jadi
tenggelam di antaranya.

### Berkas staging, dan kenapa tidak bisa dihindari

PHP menghapus berkas unggahan sementara begitu request berakhir. Worker yang
berjalan beberapa detik kemudian akan menemukan berkas yang sudah tidak ada.
Jadi berkasnya dipindahkan lebih dulu ke `storage/app/upload-queue/{uuid}.{ext}`.

Ini satu-satunya tempat di seluruh sprint yang menulis berkas langsung, dan itu
memang bukan pekerjaan Storage Engine: engine menulis ke storage provider,
sedangkan yang dibutuhkan di sini justru sebaliknya — berkas harus **tetap di
server** sampai worker sempat mengirimnya.

Foldernya sengaja **tidak** di bawah `storage/app/public`. Folder itu tersaji
ke publik lewat symlink `public/storage`, dan video berbayar yang sedang
menunggu antrean akan bisa diunduh siapa pun yang menebak namanya.

Konsekuensi yang harus diketahui: **selama pekerjaan belum berhasil, videonya
tersimpan dua kali** — satu di staging, satu (nanti) di bucket. Disk VPS harus
punya ruang untuk itu.

### Berkas staging dipertahankan setelah Gagal

Kalau dihapus saat gagal, tombol Retry tidak punya bahan dan admin harus
mengunggah ulang 4 GB dari komputernya untuk kegagalan yang mungkin hanya soal
kredensial provider. Yang dihapus adalah berkas milik pekerjaan Berhasil dan
Dibatalkan — keduanya tidak akan diulang.

Harganya: berkas milik pekerjaan Gagal menumpuk kalau tidak pernah ada yang
mengulangnya. Itulah yang dibersihkan `php artisan upload:prune`.

### `markProcessing()` di dalam `lockForUpdate`

Inilah satu-satunya tempat yang memisahkan "dibatalkan" dari "sedang diproses".

Admin menekan Cancel pada milidetik yang sama saat worker mengambil
pekerjaannya adalah kejadian yang pasti terjadi cepat atau lambat. Tanpa kunci,
keduanya membaca status `pending` lalu sama-sama melanjutkan: berkasnya
terunggah **sekaligus** barisnya ditandai dibatalkan, dan yang tertinggal
adalah objek di bucket yang tidak dikenali baris mana pun.

Alasan yang sama dipakai `lockAll()` di Sprint 7.2D untuk menjaga invarian
tepat-satu-default. Transaction sendirian tidak cukup — yang diperlukan adalah
kunci baris.

Karena itu pula pembatalan **tidak** menghapus payload dari tabel `jobs`. Job
tetap diambil worker, lalu ditolak `markProcessing()` dan berhenti tanpa
melakukan apa pun. Menghapus payload memerlukan pengetahuan tentang bentuknya,
dan bentuk itu berbeda antara driver database, redis, dan sqs.

### Koneksi antrean sendiri: `uploads`

Isinya sama persis dengan `database` kecuali satu angka — `retry_after` —
dan angka itulah alasan koneksi ini ada.

`retry_after` menentukan berapa lama pekerjaan boleh dipegang worker sebelum
antrean menganggapnya hilang dan menyerahkannya ke worker lain. Bawaan Laravel
90 detik: benar untuk mengirim satu pesan Telegram, jauh terlalu pendek untuk
mengirim berkas 4 GB yang bisa memakan puluhan menit.

Dengan 90 detik, pekerjaan unggah akan diambil ulang berkali-kali selagi yang
pertama masih berjalan. `markProcessing()` menahan yang kedua supaya tidak ikut
mengunggah — tapi antrean tetap menghabiskan jatah percobaan dan menandai
pekerjaannya gagal di tengah unggahan yang sebenarnya berjalan lancar.

Menaikkan `retry_after` pada koneksi `database` juga bisa, tetapi akibatnya
kena ke semua pekerjaan: pesan Telegram yang benar-benar hilang karena worker
mati baru akan diulang satu jam kemudian.

### Antrean bernama `uploads`, bukan `default`

Video 4 GB bisa menahan satu worker selama puluhan menit. Kalau broadcast
Telegram dan notifikasi mengantre di belakangnya, keduanya baru terkirim
setelah videonya selesai.

**Konsekuensi yang paling mudah menjebak di seluruh sprint ini:** worker wajib
mendengarkan antrean itu. Worker yang hanya mendengarkan `default` membuat
setiap unggahan menggantung di status Menunggu **selamanya, tanpa satu pun
pesan galat di mana pun**. Karena itu nama antrean dan koneksinya ditampilkan
terang-terangan di halaman Upload Queue beserta perintah worker yang benar.

### `tries` bawaan satu

Spesifikasi meminta tombol Retry — pengulangan yang diputuskan admin. Mencoba
ulang sendiri sampai tiga kali berarti mengirim berkas 4 GB tiga kali ke
provider berbayar untuk kegagalan yang sebabnya mungkin kredensial salah, dan
itu tidak akan berubah pada percobaan kedua maupun ketiga.

Nilainya tetap bisa dinaikkan lewat `UPLOAD_QUEUE_TRIES`, dan jalurnya
ditangani: percobaan yang gagal sementara masih ada jatah dikembalikan ke
`pending` (`markRetrying`) supaya percobaan berikutnya bisa masuk lewat pintu
yang sama. Tanpa itu, percobaan kedua akan menemukan baris berstatus `failed`,
ditolak, lalu berhenti diam-diam.

### `Auth::setUser()` di dalam job, bukan parameter baru di service

Worker tidak punya sesi, sehingga `Auth::id()` bernilai null. Dua tempat di
jalur unggah membacanya: kolom `uploaded_by` di `episode_videos` dan `user_id`
di `activity_logs`. Keduanya akan kosong untuk setiap unggahan lewat antrean,
dan riwayat yang tidak bisa menjawab "siapa yang mengunggah ini" kehilangan
sebagian besar gunanya.

Pilihannya menambah parameter aktor ke `EpisodeVideoService` **dan**
`ActivityLogger`, atau memasang penggunanya di guard selama satu pemanggilan.
Yang kedua dipilih: modul 7.5 tidak perlu diubah sama sekali, dan
`ActivityLogger` yang dipakai seluruh panel juga tidak.

Penggunanya dilupakan lagi di `finally`. Proses worker berumur panjang dan
mengerjakan banyak job berurutan; identitas yang tertinggal akan menempel pada
pekerjaan milik admin lain sesudahnya.

### Respons `202 Accepted`, bukan `200 OK`

202 berarti persis apa yang terjadi: permintaannya diterima, pekerjaannya belum
selesai. Membalas 200 akan mengatakan hal yang tidak benar — belum ada satu
byte pun yang sampai ke storage provider saat respons itu dikirim.

Pesan di layar mengikuti hal yang sama. Yang muncul bukan "video tersimpan",
melainkan "masuk antrean", dan baru berubah jadi "tersimpan di storage
provider" setelah polling melihat statusnya Berhasil.

### Satu episode, satu pekerjaan berjalan

Dua pekerjaan untuk episode yang sama akan saling menimpa:
`EpisodeVideoService` memakai `updateOrCreate` pada `episode_id` lalu menghapus
objek yang digantikannya. Yang selesai belakangan bisa menghapus berkas yang
baru saja ditulis yang satunya, dan yang tersisa adalah baris yang menunjuk
objek yang sudah tidak ada.

Permintaan kedua ditolak 422 dengan pesan yang menyebut pekerjaan mana yang
sedang berjalan.

### Tabel `upload_jobs` bukan pengganti tabel `jobs`

Keduanya menyimpan hal yang berbeda dan hidupnya berbeda. Baris di `jobs`
**hilang** begitu worker selesai — tidak ada jejak apa pun untuk pekerjaan yang
berhasil, dan itu memang bukan tugasnya. `upload_jobs` menyimpan riwayat yang
bisa dibuka admin: apa, ke mana, oleh siapa, berhasil atau tidak, dan kalau
gagal karena apa.

Tanpa tabel ini, satu-satunya cara mengetahui nasib sebuah unggahan adalah
membuka `failed_jobs` dan membaca payload terserialisasi — yang tidak bisa
ditampilkan di panel dan tidak menyebut episode mana pun.

### Log ditulis dua kali, dan itu disengaja

Tabel `upload_job_logs` menjawab "apa yang terjadi pada unggahan ini" dan bisa
dibuka di panel. Berkas `storage/logs/laravel.log` menjawab "apa yang terjadi
di server pada jam itu" beserta konteks di sekitarnya — termasuk galat yang
sama sekali tidak dikenali kode ini.

Kegagalan menulis log tidak boleh menggagalkan unggahan. Baris log yang hilang
mengganggu; unggahan 4 GB yang batal karena baris log gagal ditulis jauh lebih
buruk. Keduanya dibungkus `try/catch` dengan `report()`.

Peristiwa yang tercatat: `queued`, `started`, `success`, `failed`, `retrying`,
`retried`, `cancelled`, `orphan`.

### Progress: status, bukan persentase

Spesifikasi menulis "Progress Status" lalu menyebut empat status. Yang dibuat
memang itu — bukan angka persen.

Alasannya bukan kemalasan: setelah berkasnya diserahkan ke worker, tidak ada
cara jujur mengukur kemajuannya. `Storage::putFileAs` mengalirkan berkas ke
adapter dan tidak melaporkan berapa banyak yang sudah terkirim. Persentase yang
ditampilkan hanya bisa berupa tebakan berbasis waktu, dan progress bar yang
menebak lebih buruk daripada tidak ada — ia berhenti di 93% dan orang menunggu
sesuatu yang tidak pernah datang.

Yang ditampilkan justru yang benar-benar diketahui: status, jumlah percobaan,
dan durasi setelah selesai.

### Halaman tidak memuat ulang sendiri

Badge status disegarkan tiap 4 detik lewat polling. Ketika ada pekerjaan yang
selesai, yang muncul hanyalah catatan beserta tautan Muat ulang — bukan reload
otomatis. Admin bisa sedang mengetik di kotak pencarian atau membaca log yang
terbuka, dan halaman yang tiba-tiba berganti membuang keduanya.

### Tidak ada form bersarang di halaman ini

STATUS.md mencatat bug `crud/index.blade.php`: form bulk yang melingkupi tabel
membuat form tombol per baris menjadi bersarang, dan parser HTML membuang tag
`<form>` bersarang. Halaman ini tidak punya aksi massal, jadi tidak ada form
yang membungkus tabel — setiap tombol berdiri di form-nya sendiri. Diperiksa
eksplisit di self-audit.

### Parameter route berupa UUID

`show` dipanggil berulang kali sebagai polling. Id berurut di sana membocorkan
jumlah unggahan seluruh sistem kepada siapa pun yang bisa membuka satu halaman
panel. `whereUuid()` sekaligus membuat bentuk yang salah ditolak router, bukan
menjadi query ke database.

---

## Yang tidak dikerjakan, dan alasannya

- **Telegram** — dilarang spesifikasi.
- **Monitoring** — dilarang spesifikasi. Batasnya diambil di sini: daftar dan
  tindakan boleh, pengamatan tidak. Tidak ada grafik, penghitung, ringkasan,
  maupun ambang yang memicu peringatan. Polling status baris yang belum selesai
  tetap dibuat karena tanpa itu halaman berbohong, dan ia berhenti sendiri
  begitu semuanya final.
- **Storage Engine tidak disentuh** — dilarang spesifikasi, dan diperiksa
  lewat `git status` di self-audit.
- **Antrean untuk aset drama (7.6)** — spesifikasi menyebut "Upload Video
  menggunakan Queue". Kolom `type` di `upload_jobs` sudah disiapkan supaya
  modul aset bisa ikut nanti tanpa migration yang mengubah bentuk tabel berisi
  data, tetapi jalurnya belum dibuat dan tidak berpura-pura ada.
- **Cancel saat Diproses** — berkasnya sedang dikirim ke provider, dan
  menghentikannya di tengah jalan meninggalkan objek separuh di bucket.
- **Chunked upload / resumable** — pekerjaan tersendiri, dan bukan yang diminta
  sprint ini.
- **Penyatuan `EpisodeVideoService` dan `DramaAssetService` jadi
  `StoredFileWriter`** — masih terbuka. Spesifikasi sprint ini melarang
  mengubah Storage Engine, dan menyentuh keduanya sekaligus bukan bagian dari
  Queue.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       352 berkas, 0 bermasalah
python tools/check-css-coverage.py        227 kelas, semua punya aturan
python tools/check-blade-directives.py    67 blade, 0 bermasalah
node --check resources/js/admin.js        valid
```

Self-audit khusus sprint ini: **80 pemeriksaan, semuanya lolos** — setelah
skrip auditnya sendiri diperbaiki.

### Skrip audit saya sendiri salah dua kali

Versi pertama melaporkan dua GAGAL di `upload-queue.blade.php`: "tag form
tidak berpasangan" dan "ada form bersarang". Keduanya palsu.

Sebabnya: skrip menghitung `<form` dengan regex **tanpa membuang komentar
Blade** lebih dulu. Di dalam komentar view itu ada kalimat yang menjelaskan bug
`crud/index.blade.php` — "parser HTML membuang tag `<form>` bersarang" — dan
kata `<form>` di dalam kalimat itu terhitung sebagai tag pembuka yang tidak
pernah ditutup.

Ini pengulangan pola yang sudah tiga kali tercatat di STATUS.md: alat
verifikasi proyek ini sendiri perlu diaudit. Diperbaiki dengan membuang
`{{-- --}}` sebelum tag dihitung, dan hasilnya 80/80.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
migration yang dijalankan, tidak ada worker yang dinyalakan, dan tidak ada satu
halaman pun yang dirender. Yang hanya bisa dibuktikan di browser dan di server
ada di bagian pengujian.

---

## Yang harus diuji sendiri di server

Bagian ini tidak bisa saya buktikan dari sini. Urutannya penting.

1. **Worker mendengarkan antrean `uploads`.** Kalau tidak, semuanya tampak
   normal kecuali statusnya tidak pernah berpindah dari Menunggu.
2. **Unggah satu video kecil.** Responsnya harus kembali cepat, dan status
   berpindah Menunggu → Diproses → Berhasil dalam beberapa detik.
3. **Cancel.** Unggah, lalu tekan Batalkan sebelum worker mengambilnya (paling
   mudah dengan worker dimatikan sementara). Berkas staging harus hilang.
4. **Retry.** Nonaktifkan sementara provider default supaya unggahan gagal,
   lalu aktifkan lagi dan tekan Ulangi. Berkasnya tidak perlu diunggah ulang.
5. **Berkas besar sungguhan.** Yang menguji batas PHP dan Nginx dari 7.5,
   `UPLOAD_QUEUE_TIMEOUT`, dan ruang disk untuk berkas staging.
6. **`php artisan upload:prune --dry-run`.** Lihat dulu apa yang akan dihapus.

---

## Risiko yang saya ketahui dan belum diselesaikan

- **Ruang disk VPS.** Selama pekerjaan belum berhasil, videonya ada dua kali.
  Sepuluh unggahan 3 GB yang gagal berbarengan berarti 30 GB tertahan sampai
  ada yang mengulang atau menjalankan `upload:prune`.
- **Baris tersangkut di Diproses.** Worker yang dimatikan di tengah jalan tidak
  sempat menjalankan penanganan galat mana pun. `failed()` menangkap kasus
  batas waktu, tetapi tidak menangkap proses yang dibunuh paksa. Yang
  melepaskannya adalah `upload:prune`, dan itu harus dijalankan manual — belum
  ada penjadwalan.
- **Berkas staging yatim.** Kalau baris `upload_jobs` dihapus langsung dari
  database (bukan lewat panel), berkasnya tertinggal tanpa ada yang menunjuk.
  `upload:prune` tidak menemukannya karena ia bekerja dari baris, bukan dari
  isi folder.
- **Polling tidak berhenti kalau tab ditinggalkan terbuka pada baris yang belum
  selesai.** Ia berhenti sendiri setelah lima kegagalan berturut-turut atau
  ketika statusnya final, tetapi pekerjaan yang benar-benar menggantung akan
  terus ditanyakan setiap 4 detik selama tab itu terbuka.

---

## Siap dipakai sprint berikutnya

- **Aset drama (7.6) lewat antrean** — kolom `type` sudah ada; tinggal job
  kedua dan cabang di `UploadQueueService`.
- **`Admin\MediaService`** — masih melewati multi-storage sepenuhnya, dan kini
  juga melewati antrean.
- **Penjadwalan `upload:prune`** — `routes/console.php` sudah ada.
- **Notifikasi in-app saat unggahan selesai** — barisnya sudah menyimpan
  `created_by`, jadi sudah jelas siapa yang perlu diberi tahu.
