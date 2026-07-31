# Phase 12 — Final Launch & Optimization

Selesai: 31 Juli 2026

Pemeriksaan seluruh proyek, pembersihan, pengerasan, dan dokumentasi. Business
logic yang sudah stabil **tidak diubah** kecuali untuk memperbaiki bug dan
menyatukan kode kembar.

---

## Alat yang mencari masalah, bukan menegaskan yang sudah diketahui

Sembilan alat yang ada sebelumnya semuanya lolos di awal sprint ini — dan itu
memang yang seharusnya, karena masing-masing menegaskan bahwa keputusan
sprint-nya sendiri masih berlaku.

Yang tidak dilakukan satu pun dari sembilan itu: **menyisir seluruh pohon
untuk menemukan yang tidak sengaja tertinggal.** `tools/audit-final.py`
mengerjakan itu — route yang menunjuk method yang tidak ada, kelas yang tidak
dirujuk siapa pun, import mati, kunci config yang tidak pernah dibaca, view
yang tidak pernah dirender, tabel yang tidak disentuh kode, method dengan isi
identik, dan hal-hal keamanan yang bisa dilihat secara statis.

Jalan pertamanya: **21 dari 43 lolos.**

---

## Bug yang ditemukan

### `episodes:publish` tidak pernah dijadwalkan

Perintahnya ada sejak Sprint 6. Scheduler-nya tidak pernah memanggilnya.

Artinya episode yang diberi tanggal tayang **tidak pernah terbit dengan
sendirinya**. Kegagalannya diam total: tidak ada galat, tidak ada log, hanya
episode yang tidak muncul pada tanggal yang dijanjikan ke penonton — dan
satu-satunya yang menyadarinya adalah orang yang menunggunya.

Ditemukan oleh pemeriksaan "setiap perintah artisan dijadwalkan atau
didokumentasikan". Dijadwalkan tiap lima menit.

### Konfigurasi yang berbohong

Blok `limits` di `config/storage.php` berisi tiga kunci — `chunk_mb`,
`timeout`, `max_fallback_attempts` — yang **tidak satu pun pernah dibaca kode
mana pun**. Ditulis di 7.1 "supaya sprint upload nanti tidak menaruh angka
ajaib di tengah kode"; sprint upload datang tiga kali dan tidak memakainya.

`timeout` sudah tercatat sebagai bug diketahui sejak 7.3: ia tampak mengatur
batas waktu S3 padahal tidak berpengaruh. Konfigurasi yang berbohong lebih
berbahaya daripada konfigurasi yang tidak ada — orang menaikkan angkanya,
keadaan tidak berubah, dan mereka mencari penyebabnya di tempat yang salah.

Dibuang, bukan disambungkan. Menyambungkan `timeout` ke klien S3 lewat kunci
`http` harus diuji terhadap versi SDK yang benar-benar terpasang; kunci yang
salah mematikan seluruh provider S3 sekaligus, dan itu bukan perubahan yang
boleh dikirim tanpa pernah dijalankan.

---

## Dead code yang dihapus

**31 kelas**, dalam dua gelombang — gelombang kedua muncul setelah gelombang
pertama membuatnya yatim:

| Kelompok | Isi |
|---|---|
| Controller API | 7 controller yang tidak punya satu pun route |
| Service | ActivityLog, DramaCatalog, Recommendation, Review, Search, Watchlist, Media, Queue |
| Repository + interface | 6 pasang yang ikut yatim |
| Job | 4 job yang `handle()`-nya hanya berisi komentar `TODO`, semuanya diantrekan `QueueService` yang tidak dipanggil dari mana pun |
| Enum | CacheKey, EpisodeAccess, EpisodeStatus, WatchlistStatus |
| Resource, Request, Support | 6 kelas |
| `app/OpenApi/` | Anotasi swagger tanpa paket swagger |

Ditambah **31 import mati** di 20 berkas.

Empat kelas sengaja dipertahankan dan alasannya ditulis di dalam alat auditnya
sendiri: `Media` dan `Review` memetakan tabel yang sudah berisi data di
produksi, `UserResource` dan `DramaFilter` ditemukan Laravel lewat resolusi
otomatis. Daftar itu harus tetap pendek — setiap tambahan adalah utang yang
harus dijelaskan, bukan cara mendiamkan alat.

---

## Kode kembar yang disatukan

| Yang kembar | Jadi |
|---|---|
| `log()` di 5 kelas pembayaran | `Concerns\LogsPaymentEvents` |
| `normalise()` di CsvExporter dan XlsxWriter | `Concerns\NormalisesExportValues` |
| `checksum()` di DramaAssetService dan EpisodeVideoService | `Concerns\ComputesFileChecksum` |
| `sebagaiPengunggah()` dan `failed()` di dua job unggah | `Concerns\RunsUploadJob` |
| `sizeForHumans` di EpisodeVideo dan StoredFile | `Support\Bytes::forHumans()` |
| `applyBulk()` di 3 controller CRUD | Diangkat ke `AdminCrudController` |
| `providerOptions()`/`autoTarget()` di DramaAssetController | Memakai `StorageChoiceService` yang sudah ada |
| `testMeta()`/`meta()` di 2 controller storage | `StorageTestResult::meta()` |

Yang paling berbahaya di antaranya: `failed()` di dua job unggah. Itu
penanganan batas waktu — hal terakhir yang boleh berbeda antar dua jalur,
karena yang tertinggal meninggalkan baris PROCESSING selamanya tanpa ada yang
menyadarinya.

Utang `checksum()` sudah tercatat di STATUS.md sejak 7.6, ditunda karena
spesifikasi 7.6 melarang menyentuh modul video episode. Phase 12 adalah sprint
yang boleh menyentuh keduanya.

---

## `php artisan env:check`

Perintah baru yang **menolak peluncuran** bila environment belum layak.
Keluar dengan kode 1 bila ada temuan FATAL, sehingga bisa menghentikan skrip
deploy.

Yang diperiksa: `APP_KEY`, `APP_DEBUG`, `APP_ENV`, https, sambungan basis
data, migration yang belum jalan, kolom `users.is_premium`, token dan rahasia
webhook Telegram, provider pembayaran yang masih mode sandbox di produksi,
driver antrean `sync`, **detak scheduler**, dan izin folder.

Detak scheduler adalah yang paling penting di daftar itu. Cron yang lupa
dipasang terlihat persis sama dengan cron yang berjalan normal — tidak ada
galat, tidak ada log, tidak ada apa pun. Sebelum perintah ini, satu-satunya
cara mengetahuinya adalah menyadari bahwa langganan tidak pernah berakhir.

---

## Dokumentasi

Empat belas dokumen di `docs/`, ditambah indeksnya. Disusun untuk dibaca saat
sedang ada masalah, bukan saat sedang santai — `MASALAH.md` disusun dari
**gejala**, karena itu yang dilihat orang lebih dulu.

`CHECKLIST-PRODUCTION.md` menandai mana yang FATAL dan mana yang bisa
menyusul, dan dibuka dengan `env:check` supaya yang bisa diperiksa mesin tidak
dikerjakan tangan.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 lolos
python tools/check-blade-directives.py    80 blade, 0 bermasalah
python tools/check-css-coverage.py        257 kelas, semua punya aturan
python tools/check-php-structure.py       397 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143
python tools/audit-sprint-8-1.py          81/81
python tools/audit-sprint-8-2.py          125/125
python tools/audit-sprint-8-7.py          133/133
python tools/audit-sprint-9-1.py          117/117
python tools/audit-phase-10.py            164/164
python tools/audit-phase-11.py            84/84
python tools/audit-final.py               43/43
```

---

## Tiga kali saya merusak kode sendiri di sprint ini

Ini bagian yang paling penting dari dokumen ini, dan alasannya bukan
kejujuran belaka — ketiganya berasal dari satu sebab yang sama, dan sebab itu
sudah tujuh kali tercatat di STATUS.md.

### 1. `use Log;` dibuang padahal `Log::channel()` masih dipakai

Skrip pembuang import saya memakai `/\*.*?\*/` untuk membuang blok komentar.
Pada `TelegramClient.php` pola itu menelan bagian berkas sampai
`Log::channel()` ikut hilang dari pandangan, sehingga import-nya terlihat
tidak dipakai. Ia dibuang. Kelasnya jadi fatal error.

Ketahuan karena saya memeriksa hasilnya sebelum melanjutkan. Dikembalikan
seluruhnya, lalu diulang dengan pengupas yang hanya membuang docblock `/** */`
dan komentar baris — blok `/* */` biasa dibiarkan utuh.

### 2. `config/storage.php` kehilangan 315 dari 322 baris

Regex penghapus blok `limits` cocok jauh lebih panjang daripada yang saya
maksud. Ketahuan karena audit berikutnya tiba-tiba melaporkan sebelas variabel
`STORAGE_*` tidak terbaca — variabel yang sebelumnya baik-baik saja.

Dikembalikan dari git, lalu dihapus dengan penggantian teks harfiah.

### 3. Tiga berkas kehilangan kurung penutup

Pemotong method saya memakai `(?:/\*\*.*?\*/\s*)?` dengan `re.S` di depan nama
method. Pada tiga berkas, `.*?` menjangkau mundur sampai docblock kelas dan
ikut membuang bagian yang tidak dimaksud. `check-php-structure.py` menangkapnya
sebagai kurung tidak seimbang.

### Dan satu yang lebih halus

Trait `use LogsPaymentEvents;` gagal tersisip di lima kelas karena regex
`class \w+[^\n{]*\{` mengharuskan `{` di baris yang sama, sedangkan gaya PSR-12
menaruhnya di baris berikutnya. Method `log()`-nya sudah terlanjur dibuang.
Lima kelas pembayaran berjalan tanpa `log()` sama sekali — fatal error pada
panggilan pertama.

Tidak tertangkap pemeriksa kurung, karena kurungnya memang seimbang. Yang
menangkapnya: pemeriksaan "import tidak dipakai" yang melaporkan
`use ...LogsPaymentEvents;` tidak terpakai — laporan yang benar, dengan
sebab yang sama sekali berbeda dari yang saya duga.

### Pelajarannya

**Skrip yang menyunting kode dengan regex harus diverifikasi setelah setiap
jalan, bukan setelah semuanya selesai.** Ketiga kerusakan di atas tertangkap
karena `check-php-structure.py` dan `git diff --stat` dijalankan segera
sesudahnya; kalau ditunda sampai akhir, ketiganya akan bercampur dan sumbernya
jauh lebih sulit ditemukan.

Dan yang kedua: **`git diff --stat` adalah pemeriksa paling murah yang ada.**
"1 file changed, 315 deletions" pada penghapusan yang seharusnya membuang 30
baris sudah cukup untuk menghentikan langkah berikutnya.

---

## Yang sengaja tidak dikerjakan

- **Menyambungkan `STORAGE_TIMEOUT` ke klien S3.** Dibuang, bukan
  disambungkan. Alasannya di atas.
- **Menghapus tabel `media` dan `reviews`.** Keduanya berisi data di produksi.
  Modelnya dipertahankan sebagai satu-satunya jalan menjangkaunya.
- **Menyelesaikan driver Midtrans, Xendit, Tripay.** Tetap kerangka yang
  menolak dipakai. Alur callback dan tanda tangannya hanya bisa dipastikan
  dengan akun sungguhan.
- **Pengujian otomatis.** Proyek ini tidak punya test suite, dan membangunnya
  bukan pekerjaan satu sprint. Yang ada adalah sepuluh alat verifikasi statis
  dengan 1.000+ pemeriksaan — bermanfaat, tetapi **tidak menjalankan satu baris
  PHP pun**.

---

## Peringatan yang tidak boleh hilang

Seluruh verifikasi di dokumen ini **statis**. Tidak ada PHP yang dijalankan,
tidak ada migration yang dijalankan, tidak ada halaman yang dirender, tidak
ada pembayaran yang diproses, tidak ada video yang dikirim.

Empat bug paling serius yang ditemukan sepanjang Phase 8 sampai 12 —
`users.is_premium` yang tidak pernah ada, `episodes.access_type` yang salah
nama, `ActivityLogger` yang dipanggil dengan `int`, dan `episodes:publish` yang
tidak pernah dijadwalkan — **semuanya lolos dari seluruh alat statis** sampai
ada yang memeriksanya dengan pertanyaan yang tepat.

`docs/CHECKLIST-PRODUCTION.md` bagian 10 berisi jalur yang harus benar-benar
dijalani di browser. Itu bagian yang tidak bisa digantikan alat mana pun.
