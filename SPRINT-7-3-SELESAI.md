# Sprint 7.3 — Connection Test

Selesai: 30 Juli 2026

Tombol Test Connection di panel admin, untuk keenam provider berprotokol S3.
Tidak ada upload.

---

## Langkah setelah deploy

`deploy.sh` menjalankan `migrate`, jadi kolom baru terpasang sendiri. Tidak
ada langkah manual.

---

## Yang sebenarnya dikerjakan sprint ini

Mesin pengujiannya **sudah ada sejak Sprint 7.1** dan dipakai
`php artisan storage:test`: `StorageManager::test()` menulis satu berkas kecil,
membacanya kembali, lalu menghapusnya, dan mengembalikan `StorageTestResult`.

Sprint ini menyambungkannya ke tombol. Tidak ada logika pengujian yang ditulis
ulang — kalau ada, itu justru tanda ada yang salah.

Yang benar-benar baru:

1. Route `POST /admin/storage/{id}/test` dan method `test()` di controller
2. Tombol di kolom Aksi
3. **Panel hasil yang menetap** di layout admin
4. **Kolom durasi di database**, supaya Response Time tidak hilang saat halaman
   dimuat ulang
5. Kolom "Uji Terakhir" di tabel

---

## Keenam provider lewat jalur yang sama

| Provider | Driver Flysystem | Ditangani |
|---|---|---|
| Cloudflare R2 | `s3` | endpoint + path-style dipaksa |
| Amazon S3 | `s3` | endpoint diturunkan dari region |
| Backblaze B2 | `s3` | endpoint + region wajib |
| Wasabi | `s3` | endpoint + region wajib |
| MinIO | `s3` | endpoint + path-style dipaksa |
| DigitalOcean Spaces | `s3` | endpoint + region wajib |

Tidak ada percabangan per provider di jalur pengujian, dan itu memang
rancangannya sejak 7.1: keenamnya memakai driver `s3` yang sama, dan yang
berbeda hanya endpoint, region, serta gaya path — seluruhnya sudah diurus
`DiskConfigFactory`. Diverifikasi: di `StorageDriver::flysystemDriver()` hanya
`LOCAL`, `GCS`, dan `AZURE` yang menyimpang; enam sisanya jatuh ke `default`.

Konsekuensinya, menambah provider S3-compatible ke-7 nanti langsung bisa diuji
tanpa menyentuh kode Test Connection sama sekali.

---

## Kegagalan adalah jawaban, bukan kerusakan

`StorageManager::test()` menangkap **seluruh** `Throwable` dan
mengembalikannya sebagai hasil. Karena itu `test()` di controller tidak perlu
`try/catch` sama sekali.

Ini keputusan yang dibuat sejak 7.1 dan baru terasa gunanya sekarang: sebuah
tombol yang bernama "uji koneksi" memang bertugas melaporkan kegagalan. Kalau
kredensial salah lalu yang muncul halaman 500, orang akan melaporkannya sebagai
bug aplikasi, bukan membacanya sebagai jawaban.

---

## Panel hasil, bukan toast

Toast di panel admin menghilang sendiri setelah 4 detik
(`resources/js/admin.js` baris 164-167). Itu tepat untuk "berhasil disimpan",
tetapi salah untuk hasil Test Connection: pesan galat SDK penyimpanan bisa
sepanjang satu paragraf, dan justru di situ petunjuknya. Empat detik tidak
cukup untuk membacanya, apalagi menyalinnya.

Jadi hasil test dikirim lewat `session('detail')` dan dirender sebagai panel
yang menetap di `admin.blade.php`, di atas isi halaman:

- judul menyebut nama provider
- badge Berhasil / Gagal
- baris keterangan: nama driver, **waktu respons**, dan apakah siklus
  tulis-baca-hapus selesai
- pesan galat asli dari SDK, apa adanya
- petunjuk penyebab yang paling mungkin (`StorageTestResult::hint()`)

Bentuk `session('detail')` sengaja umum — judul, `ok`, `meta`, `message`,
`hint` — supaya modul lain bisa memakainya untuk hasil yang terlalu penting
buat toast. Tidak ada CSS baru: seluruhnya memakai `.panel`, `.panel-head`,
`.panel-meta`, `.detail-body-admin`, `.field-hint`, `.field-error`, dan
`.badge` yang sudah ada.

---

## Response Time disimpan, bukan hanya ditampilkan sesaat

Sprint 7.1 sudah menyimpan hasil dan pesan test terakhir, tapi **tidak
durasinya** — durasi hanya hidup selama satu permintaan lalu hilang.

Padahal justru angka itu yang paling berguna dibandingkan antar-provider:
bucket yang menjawab dalam 40 ms dan yang 3 detik sama-sama "berhasil", tapi
hanya salah satunya layak dipakai menyajikan video.

Migration baru menambah `last_test_duration_ms`. `unsignedInteger`, bukan
`smallInteger`: memberi ruang jauh lebih murah daripada nilai yang dipotong
diam-diam.

Pemformatan durasinya dibagi lewat `StorageTestResult::formatDuration()` yang
statis, dipakai baik oleh objek hasil maupun oleh model saat membaca kolom.
Menulisnya dua kali berarti dua tempat yang harus diingat bersamaan setiap kali
ambang "ms atau s" diubah.

---

## Kolom "Uji Terakhir"

Accessor `last_test_summary` menggabungkan tiga hal: hasil, waktu respons, dan
kapan — misalnya `Berhasil, 412 ms, 2 jam lalu`. Ketiganya hanya bermakna
bersama-sama: "Berhasil" tanpa waktu tidak memberi tahu provider mana yang
layak dipakai, dan keduanya tanpa "kapan" tidak memberi tahu apakah angka itu
masih menggambarkan keadaan sekarang.

Pesan galatnya sengaja **tidak** ikut ke tabel; panjangnya bisa satu paragraf
dan akan merusak tata letak. Tempatnya di panel hasil.

`last_test_summary` sengaja tidak masuk `sortable()` — ia accessor, bukan
kolom, jadi `orderBy` atasnya akan menghasilkan galat SQL. Untuk mengurutkan
menurut kapan terakhir diuji, `last_tested_at` yang dipakai.

---

## Keputusan kecil yang perlu dicatat

**POST, bukan GET.** Terasa seperti "membaca", tapi Test Connection menulis
lalu menghapus berkas di bucket. Sebagai GET, ia bisa terpicu prefetch peramban
atau perayap yang mengikuti tautan.

**Tombol dirender untuk setiap baris hidup**, termasuk provider nonaktif dan
yang belum lengkap. Justru di situ gunanya: mengujinya adalah cara mengetahui
apa yang masih kurang, dan hasil gagal menyebutkan alasannya. Bandingkan dengan
tombol Set Default dan Disable, yang memang disembunyikan karena pasti ditolak.

**Batas laju** ikut `throttle:admin-write` (60/menit) yang sudah dipasang di
grup admin. Tiap penekanan memicu panggilan jaringan keluar dan satu
tulis-hapus di bucket, jadi ia memang tidak boleh bisa ditekan beruntun tanpa
batas.

**Pesan galat bisa memuat Access Key ID.** Galat `SignatureDoesNotMatch` dari
AWS kadang menyertakan `AWSAccessKeyId` di dalamnya, dan pesan itu ditampilkan
apa adanya di panel serta disimpan di `last_test_message`. Secret key tidak
pernah ikut — ia dipakai untuk menandatangani, tidak pernah dikirim. Access Key
ID bukan rahasia (ia muncul di URL presigned), dan yang melihat panel ini sudah
memegang izin `storage.manage`. Dicatat di sini supaya keputusannya sadar,
bukan kebetulan.

---

## Temuan: `STORAGE_TIMEOUT` tidak pernah dibaca kode

`config/storage.php` punya `limits.timeout` dari `STORAGE_TIMEOUT`, dan
`.env.example` mendokumentasikannya. **Tidak ada satu baris kode pun yang
membacanya.** Nilai itu tidak pernah disambungkan ke klien S3, jadi yang
berlaku adalah batas waktu bawaan AWS SDK.

Yang membuatnya lebih dari sekadar konfigurasi menganggur: `hint()` untuk galat
timeout **menyuruh admin menaikkan STORAGE_TIMEOUT**. Petunjuk yang
mengarahkan ke tindakan tanpa efek lebih buruk daripada tidak ada petunjuk —
orangnya akan mengubah `.env`, deploy ulang, dan mendapati tidak ada yang
berubah.

Yang saya lakukan di sprint ini:

- pesan `hint()` diperbaiki, tidak lagi menyebut STORAGE_TIMEOUT
- `config/storage.php` dan `.env.example` diberi catatan "BELUM BERPENGARUH"

Yang **tidak** saya lakukan: menyambungkannya. Caranya lewat kunci `http` pada
config disk S3, tapi itu harus diuji langsung terhadap versi SDK yang terpasang
di server — kunci yang salah menggagalkan pembuatan klien dan mematikan
**seluruh** provider S3 sekaligus. Sikap yang sama saya ambil di Sprint 7.1
untuk penyesuaian R2/B2.

Sekarang justru waktu yang tepat mengerjakannya, karena tombol Test Connection
membuat akibatnya langsung terlihat. Kandidat sprint berikutnya.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       330 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    64 blade, 0 bermasalah
```

Self-audit:

- keenam provider yang diminta terdaftar di enum, dan terbukti melewati jalur
  `s3` yang sama (hanya LOCAL/GCS/AZURE yang menyimpang)
- `StorageManager::test()` menangkap seluruh `Throwable`; controller tidak
  perlu `try/catch`
- keempat hal yang diminta terkirim ke panel: Success/Failed, Error Message,
  Response Time, plus petunjuk
- panel hasil tidak memakai `data-toast`, jadi tidak hilang sendiri
- kolom tabel tidak memuat kredensial; `test()` tidak menyentuhnya
- `last_test_summary` tidak masuk `sortable()`; semua kolom sortable nyata ada
  di migration
- route memakai POST

**Semua verifikasi ini statis.** Yang hanya bisa dibuktikan di browser dan
terhadap bucket sungguhan:

- tombol denyut muncul di tiap baris, dan menekannya memunculkan panel hasil
- provider `local` berhasil dengan waktu respons beberapa milidetik
- provider R2/B2/Wasabi/S3/MinIO/Spaces sungguhan berhasil, atau gagal dengan
  pesan yang bisa ditindaklanjuti
- kolom "Uji Terakhir" terisi setelah test, dan tetap ada setelah halaman
  dimuat ulang
- panel hasil tidak menghilang sendiri setelah beberapa detik

---

## Belum dikerjakan (sengaja)

- Upload apa pun
- Test Connection massal dari panel (masih lewat `php artisan storage:test`)
- Menyambungkan `STORAGE_TIMEOUT` ke klien S3
- Menjalankan test di antrean; saat ini sinkron, jadi provider yang lambat
  menahan permintaan admin sampai SDK menyerah
- Hapus permanen provider
- GCS dan Azure — masih kerangka, akan gagal dengan pesan "composer require ..."
