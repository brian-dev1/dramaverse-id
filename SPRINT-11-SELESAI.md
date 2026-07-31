# Phase 11 — Analytics & Business Intelligence

Selesai: 1 Agustus 2026

Dashboard analitik lima seksi, tujuh jenis laporan, dan pemanas cache
terjadwal. Alur utama aplikasi **tidak diubah satu baris pun** — tidak ada
migration baru, tidak ada tabel baru, tidak ada perubahan pada checkout,
pemutar, storage, maupun bot.

Belum ada AI recommendation, AI prediction, marketing automation, mobile app,
dan public API.

---

## Gagasan yang menentukan seluruh rancangan

**Satu sumber kebenaran untuk setiap angka.**

Analitik adalah lapisan yang paling mudah melahirkan dua jawaban berbeda untuk
pertanyaan yang sama. Dan dua angka pendapatan yang berbeda di dua halaman
lebih buruk daripada tidak ada angka sama sekali: yang pertama membuat orang
berhenti memercayai keduanya, yang kedua setidaknya jujur.

Karena itu setiap angka di Phase 11 punya tepat satu tempat asal:

| Pertanyaan | Satu-satunya yang menjawab |
|---|---|
| Berapa pendapatan | `AnalyticsRepository`, dari `invoices` lunas |
| Rentang waktu "bulanan" itu berapa lama | `AnalyticsPeriod` |
| Laporan X isinya kolom apa | `ReportService::headers()` |
| Laporan X barisnya dari mana | `ReportService::rows()` |

---

## Bug yang ditemukan

### Laporan pendapatan menghitung dari tabel yang salah

`ReportController` dan `StatsService` sama-sama menjumlahkan
`subscriptions.price`. Sejak Phase 10 itu keliru dengan dua cara sekaligus:

1. Langganan yang **diberikan admin** (`source = admin`) punya harga tetapi
   tidak ada uang yang masuk. Kompensasi gangguan dan hadiah ikut terhitung
   sebagai pendapatan.
2. **Biaya layanan provider** hanya tercatat di invoice. Yang benar-benar
   diterima berbeda dari harga paket, dan selisihnya tidak pernah muncul.

Yang membuatnya sulit dicurigai: kedua tempat salah dengan cara yang sama,
jadi angkanya selalu cocok satu sama lain. Kecocokan itulah yang membuatnya
tampak benar.

Diperbaiki: pendapatan dihitung dari `invoices` berstatus lunas, di satu
tempat. Ada pemeriksaan otomatis yang melarang `Subscription::query()` dipakai
menjumlahkan `price` di repository analitik.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/AnalyticsPeriod.php` | Harian, mingguan, bulanan, tahunan |
| `app/Repositories/Contracts/AnalyticsRepositoryInterface.php` | 18 pertanyaan agregat |
| `app/Repositories/AnalyticsRepository.php` | Seluruh query, satu tempat |
| `app/Services/Analytics/AnalyticsService.php` | Cache per seksi + bentuk grafik |
| `app/Services/Analytics/ReportService.php` | 7 laporan: kolom, baris, rentang |
| `app/Console/Commands/AnalyticsRefresh.php` | `php artisan analytics:refresh` |
| `config/analytics.php` | Cache dan batas ekspor |
| `tools/audit-phase-11.py` | 84 pemeriksaan |

## Berkas yang disunting

| Berkas | Perubahan |
|---|---|
| `app/Http/Controllers/Admin/AnalyticsController.php` | Ditulis ulang: 5 seksi bertab + periode |
| `app/Http/Controllers/Admin/ReportController.php` | Definisi laporan pindah ke service |
| `resources/views/web/pages/admin/analytics.blade.php` | Ditulis ulang |
| `resources/views/web/pages/admin/report.blade.php` | Grafik ganda dibuang |
| `app/Providers/AppServiceProvider.php` | Binding repository |
| `routes/console.php` | Jadwal pemanas cache |
| `.env.example` | 4 variabel |

Nol migration, nol tabel, nol CSS baru, nol komponen grafik baru.

---

## Keputusan desain

### Grafik memakai komponen yang sudah ada

`x-admin.chart` ada sejak Sprint 6 dan menerima `labels` serta `values`
terpisah. `AnalyticsService::chart()` menyesuaikan diri dengan bentuk itu,
bukan sebaliknya.

Hasilnya: **nol komponen grafik baru dan nol kelas CSS baru** di seluruh
Phase 11 — jumlah kelas CSS tetap 257, sama persis dengan sebelum sprint ini.
Line, bar, pie, dan area semuanya sudah didukung komponen itu lewat atribut
`type`.

### Periode kosong diisi nol, bukan dilewati

`AnalyticsPeriod::buckets()` menghasilkan seluruh kunci dalam rentang, lalu
`AnalyticsRepository::bucket()` mengisi yang tidak punya data dengan nol.

Ini bukan kosmetik. Grafik yang hanya menampilkan hari yang kebetulan ada
datanya akan melompati hari kosong, dan garis yang melompat menyembunyikan
justru hal yang paling ingin dilihat: **kapan berhentinya**.

### Mingguan memakai tahun-minggu ISO

`DATE_FORMAT` dengan `%x-%v`, bukan `%Y-%u`. Minggu pertama Januari sering
masih milik tahun sebelumnya menurut ISO; memakai `%Y` menghasilkan dua
kelompok berbeda untuk minggu yang sama, dan grafiknya menampilkan satu minggu
sebagai dua batang pendek.

### Cache di service, bukan di repository

Repository menjawab pertanyaan tunggal; halaman butuh belasan jawaban
sekaligus. Yang mahal bukan satu query, melainkan dibukanya halaman itu
berulang kali oleh beberapa admin dalam menit yang sama.

Karena itu yang di-cache adalah **seksi utuh** — satu kunci per seksi per
periode. Satu kali baca cache untuk seluruh kartu dan grafik di satu tab.

TTL-nya lima menit dengan sengaja. Angka analitik yang telat lima menit tidak
merugikan siapa pun; yang telat satu jam membuat orang berhenti memercayai
dashboard-nya, dan dashboard yang tidak dipercaya sama saja dengan tidak ada.

### Hanya seksi yang dibuka yang dihitung

Tab dan periode berupa **tautan biasa**, bukan JavaScript. Setiap kombinasi
jadi URL tersendiri yang bisa di-bookmark dan dikirim ke orang lain.

Yang lebih menentukan: memuat kelima seksi sekaligus berarti belasan query
agregat berjalan untuk empat tab yang mungkin tidak akan dilihat sama sekali.

### Definisi laporan pindah dari controller ke service

Tiga jalur — layar, ekspor, cetak — memanggil `rows()` dan `headers()` yang
sama persis. Selama definisinya ada di controller, menambah satu laporan
berarti menyunting tiga tempat yang kebetulan bersebelahan. Itu jenis
duplikasi yang paling mudah lolos, karena terlihat rapi.

Buktinya langsung terasa: **tiga laporan baru** — tagihan, sinkronisasi
Telegram, dan penyimpanan — masuk tanpa satu baris pun ditambahkan di
controller.

### Ekspor dibatasi jumlah barisnya

`ANALYTICS_REPORT_MAX_ROWS`, bawaan 20.000. Tanpa batas, ekspor setahun penuh
membaca seluruh tabel ke memori dan menghentikan proses PHP di tengah unduhan
— yang sampai ke admin sebagai **berkas rusak**, bukan sebagai pesan galat.

### Jenis laporan yang tidak dikenal jatuh ke bawaan, bukan 404

Tautan lama yang jenisnya sudah dihapus lebih baik membuka laporan bawaan
daripada memberi halaman galat kepada orang yang cuma membuka bookmark.

### Pengguna gratis dihitung sebagai selisih

`gratis = total - premium`, bukan query terpisah dengan `is_premium = false`.
Keduanya seharusnya sama; kalau berbeda, yang benar adalah yang menjumlah utuh
— pengguna tidak boleh hilang dari kedua kelompok karena kolom penandanya
basi.

### PDF lewat dialog cetak

Tidak berubah dari sebelumnya, dan disebut lagi karena spesifikasi memintanya:
halaman cetak dirender bersih, dan "Simpan sebagai PDF" di peramban
menghasilkan PDF yang sama baiknya tanpa satu pun paket tambahan.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    80 blade, 0 bermasalah
python tools/check-css-coverage.py        257 kelas, semua punya aturan
python tools/check-php-structure.py       441 berkas, 0 bermasalah
python tools/audit-phase-10.py            164/164 lolos
python tools/audit-phase-11.py            84/84 lolos
```

`audit-phase-11.py` memeriksa antara lain:

- pendapatan dihitung dari invoice, dan **tidak** dari `subscriptions.price`
- controller tidak menjalankan query agregat sendiri
- layar, ekspor, dan cetak memakai definisi laporan yang sama
- halaman laporan memakai sumber grafik yang sama dengan dashboard
- repository tidak ikut menyimpan cache
- setiap kunci config benar-benar dibaca kode
- pembagian selalu dijaga dari penyebut nol

### Tiga GAGAL palsu, semuanya dari skrip audit

Sesuai kebiasaan proyek ini, dilaporkan apa adanya.

1. **"ReportController tidak menghitung angka sendiri"** — pemeriksaannya
   mencari `->count()`, dan menemukan `$rows->count()` yang menghitung baris
   sebuah **Collection**, bukan query. Diperbaiki dengan hanya mencari penanda
   query database: facade `DB`, pemanggilan statis pada model, dan
   `selectRaw`.
2. **"AnalyticsRefresh dipakai, bukan kelas yatim"** — pemeriksaannya
   menghitung kemunculan nama kelas. Perintah artisan tidak pernah dirujuk
   lewat nama kelasnya: Laravel memindai direktori `Commands`, dan yang
   menyebutnya adalah *signature*-nya di scheduler. Diganti pemeriksaan
   signature.
3. **`audit-phase-10.py`: "hanya PaymentCallbackService dan InvoiceService
   yang MENULIS status lunas"** — pemeriksaan milik Phase 10 menandai setiap
   kemunculan `PaymentStatus::PAID->value` apa pun konteksnya, sehingga
   `where('status', PaymentStatus::PAID->value)` — pembacaan biasa — ikut
   dituduh menulis. Lapisan analitik membaca invoice lunas di beberapa tempat
   dan langsung memicunya. Dipersempit ke bentuk penulisan `'status' => ...`.

Ketiganya sebab yang sama: **memeriksa bentuk tulisan, bukan maksudnya.**
Yang ketiga sekaligus contoh bahwa skrip audit lama pun perlu diperiksa ulang
saat lapisan baru mulai membaca datanya.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
query yang benar-benar dieksekusi, tidak ada halaman yang dirender.

---

## Belum dikerjakan (sengaja)

- **`StatsService` belum dipindahkan.** Dashboard utama masih memakainya, dan
  `summary()['revenue']` di sana masih menjumlahkan `subscriptions.price` —
  **angka yang sama salahnya** dengan yang diperbaiki di laporan. Tidak
  disentuh karena `StatsService` juga memberi makan dashboard, dan
  memindahkannya berarti mengubah halaman yang tidak diminta sprint ini.
  Dicatat sebagai bug diketahui.
- **Laporan terjadwal ke email atau Telegram.** Perintah `analytics:refresh`
  hanya memanaskan cache. Mengirimkan laporan berkala adalah keputusan
  operasional yang belum diminta.
- **Ekspor PDF dari PHP.** Tetap lewat dialog cetak peramban.
- **Grafik pie dan area belum dipakai di halaman.** Komponennya sudah
  mendukung keduanya lewat atribut `type`; yang ada sekarang line dan bar,
  karena data yang ditampilkan semuanya deret waktu. Memakai pie untuk deret
  waktu akan salah.
- **Perbandingan antar rentang bebas.** Yang ada perbandingan otomatis dengan
  periode sebelumnya yang sama panjang. Memilih dua rentang sembarang perlu
  antarmuka tersendiri.
