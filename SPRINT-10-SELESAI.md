# Phase 10 — Payment & Membership System

Selesai: 1 Agustus 2026

Sistem membership dan pembayaran yang modular: **provider tidak dipatok di
kode**, dan Business Logic Membership tidak tahu provider mana yang dipakai.

Belum ada analytics dashboard, recommendation system, fitur AI, mobile app,
dan marketing automation.

---

## Dua bug fatal yang ditemukan saat audit, dan diperbaiki

Keduanya diam sepenuhnya — tanpa galat, tanpa log, tanpa satu pun alat statis
yang menyadarinya.

### 1. Tidak ada satu pun episode yang bisa ditonton siapa pun

`EpisodeAccessRepository::canWatch()` memeriksa `$episode->access_type === 'free'`.
Kolom `access_type` **tidak pernah ada** di migration mana pun — yang ada
`is_vip`. Eloquent mengembalikan null untuk atribut yang tidak ada, tanpa
galat, sehingga cabang itu tidak pernah bernilai benar.

Baris berikutnya memeriksa `$user->is_premium`. Kolom itu **juga tidak pernah
ada**.

Hasilnya: method ini selalu mengembalikan `false`. Episode gratis pun ditolak.
Ini dipakai pemutar website **dan** bot Telegram sejak Sprint 8.5.

Diperbaiki: migration menambahkan `users.is_premium` dan
`users.premium_expired_at`, dan repository-nya ditulis ulang memakai `is_vip`.
Admin ikut ditambahkan sebagai yang selalu boleh — ia harus bisa memeriksa isi
berbayar tanpa membeli langganannya sendiri.

### 2. `PaymentService` tidak pernah bisa dibangun

Ia menyuntik `PaymentGatewayInterface` yang **tidak pernah di-bind** di
container. Setiap upaya membangunnya berakhir dengan
`BindingResolutionException`.

Tidak pernah terlihat karena tidak ada satu pun kode yang memanggilnya. Dead
code sejak dibuat, bersama tiga gateway yang seluruh method-nya hanya berisi
komentar `TODO`.

Sekarang `PaymentGatewayInterface` sengaja **tetap tidak** punya binding
tunggal — gateway mana yang dipakai ditentukan baris `payment_providers`,
bukan container. Itulah yang membuat dua provider dengan driver berbeda bisa
hidup berdampingan.

---

## Gagasan yang menentukan seluruh rancangan

**Business Logic Membership tidak boleh tahu provider mana yang dipakai.**

Menambah Stripe atau PayPal harus cukup dengan:

1. satu kelas yang memenuhi `PaymentGatewayInterface`,
2. satu case di `PaymentDriver`.

Tanpa menyentuh `MembershipService`, `InvoiceService`, `PaymentCallbackService`,
controller, route, maupun view mana pun. Ada pemeriksaan otomatis yang
melarang nama kelas gateway muncul di luar enum dan manager, dan melarang
`MembershipService` menyebut satu pun nama provider.

Itu sebabnya setiap method kontrak menerima `PaymentProvider` sebagai argumen
alih-alih membacanya dari config: satu driver bisa dipasang **dua kali**
dengan kredensial berbeda — sandbox dan live berdampingan — dan gateway tidak
boleh menyimpan keadaan milik salah satunya.

Pola yang sama dengan `StorageEngineInterface` (7.4) dan
`TelegramServiceInterface` (8.1).

---

## Alur lengkap

```
Pengguna → POST /checkout
              │
              ▼
     CheckoutService::start()
        │  1. Invoice          ┐
        │  2. Subscription     │ satu DB::transaction
        │     (PENDING)        │
        │  3. PaymentTransaction ┘  ← reference TERSIMPAN
        │
        │  4. gateway->charge()     ← baru menyentuh jaringan
        ▼
   halaman provider / instruksi transfer

Provider → POST /payment/callback/{slug}
              │
              ▼
     driver->parseCallback()   ← tanda tangan diverifikasi, gagal = dilempar
              │
              ▼
   PaymentCallbackService::apply()
        │  lockForUpdate + DB::transaction
        │  idempotensi  → status sama? selesai, jawab 200
        │  perpindahan  → canTransitionTo()
        │  nominal      → cocok? kalau tidak, TOLAK + alert kritis
        │
        ├─► InvoiceService::markPaid()
        └─► MembershipService::activateFromInvoice()
                 └─► syncUserFlags() → users.is_premium
                          └─► EpisodeAccessService (web + Telegram)
```

Verifikasi terjadwal dan tombol Verifikasi Manual admin masuk di
`PaymentCallbackService::apply()` yang sama. **Tiga jalur, satu aturan.**

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/PaymentDriver.php` | 5 driver + field kredensial + `isImplemented()` |
| `app/Enums/PaymentStatus.php` | 6 status + `canTransitionTo()` |
| `app/Enums/SubscriptionStatus.php` | Pending, Active, Expired, Cancelled |
| `app/Enums/RefundStatus.php` | Struktur data pengembalian dana |
| `app/Models/{PaymentProvider,Invoice,PaymentTransaction}.php` | Tiga tabel baru |
| `app/Services/Payments/Contracts/PaymentGatewayInterface.php` | Kontrak gateway |
| `app/Services/Payments/PaymentGatewayManager.php` | Registry driver |
| `app/Services/Payments/InvoiceService.php` | Buat, lunasi, batalkan, kedaluwarsakan |
| `app/Services/Payments/CheckoutService.php` | Alur tombol bayar |
| `app/Services/Payments/PaymentCallbackService.php` | Satu-satunya jalur perubahan status |
| `app/Services/Payments/PaymentAlertService.php` | Peringatan pembayaran |
| `app/Services/Payments/{PaymentCharge,PaymentResult}.php` | Value object seragam |
| `app/Services/Payments/Exceptions/PaymentException.php` | Satu jenis kegagalan |
| `app/Services/Payments/Drivers/AbstractGateway.php` | Bagian yang benar-benar sama |
| `app/Services/Payments/Drivers/ManualTransferGateway.php` | **Jalan penuh** |
| `app/Services/Payments/Drivers/TrakteerGateway.php` | **Jalan penuh** |
| `app/Services/Payments/Drivers/{Midtrans,Xendit,Tripay}Gateway.php` | Kerangka, menolak dipakai |
| `app/Services/Membership/MembershipService.php` | Aturan membership, nol pengetahuan gateway |
| `app/Jobs/VerifyPaymentTransaction.php` | Verifikasi untuk callback yang hilang |
| `app/Console/Commands/PaymentAutomation.php` | `payment:auto verify\|expire\|all` |
| `app/Http/Controllers/PaymentCallbackController.php` | Satu route untuk semua provider |
| `app/Http/Controllers/Web/CheckoutController.php` | Checkout & tagihan pengguna |
| `app/Http/Controllers/Admin/{PaymentProvider,Invoice,PaymentLog}Controller.php` | Panel |
| 5 migration, 4 view, 1 seeder, `config/payment.php` | |
| `tools/audit-phase-10.py` | 164 pemeriksaan |

## Berkas yang dihapus

- `app/Services/PaymentService.php` — tidak pernah bisa dibangun
- `app/Services/MembershipService.php` — digantikan `App\Services\Membership\MembershipService`
- `app/Enums/PaymentGateway.php` — digantikan `PaymentDriver`
- `app/Services/Payments/{Midtrans,Xendit,Tripay}Gateway.php` — dipindah ke `Drivers/`

---

## Keputusan desain

### `manual` selesai lebih dulu, dan itu disengaja

Transfer bank manual tidak memanggil API mana pun, jadi ia bisa diuji **hari
ini juga** — tanpa akun gateway, tanpa kredensial, tanpa sandbox.

Itu yang membuat seluruh alur lain bisa dibuktikan bekerja: invoice terbentuk,
transaksi tercatat, membership aktif otomatis setelah verifikasi, langganan
berakhir pada waktunya, `users.is_premium` ikut berubah, dan episode premium
benar-benar terbuka.

Tanpa satu pun driver yang jalan, semua yang dibangun Phase 10 hanya bisa
dibaca — dan sistem pembayaran yang belum pernah dijalankan adalah sistem
pembayaran yang belum diketahui benar.

Verifikasinya manusia, tetapi tetap lewat `PaymentCallbackService` yang sama.

### Midtrans, Xendit, dan Tripay dibiarkan kosong, bukan ditulis dari ingatan

Alur charge, bentuk callback, dan cara menghitung tanda tangan setiap gateway
hanya bisa dipastikan dengan akun sungguhan dan dokumentasi yang sedang
berlaku.

Menulisnya dari ingatan menghasilkan kode yang terlihat selesai, lolos seluruh
pemeriksaan statis, lalu gagal pertama kali ada orang yang benar-benar
membayar — dan di sistem pembayaran, kegagalan pertama itu terjadi pada uang
orang lain.

Karena itu `isImplemented()` mengembalikan false, panel menolak
mengaktifkannya, dan mereka tidak akan pernah muncul sebagai pilihan di
checkout. Docblock masing-masing menyebut tiga langkah untuk menyelesaikannya.

Pola yang sama dengan `StorageDriver` di 7.1, yang menyebut paket composer
mana yang belum terpasang.

### Invoice dan transaksi dipisah

Satu invoice bisa punya beberapa percobaan pembayaran. Pengguna yang gagal
bayar lalu mencoba lagi dengan provider berbeda tidak boleh kehilangan riwayat
percobaan pertamanya — menimpanya berarti tidak ada yang bisa menjawab "kenapa
dia bilang sudah bayar tapi tidak masuk".

Nomor invoice juga **tidak** dibuat ulang saat mencoba lagi: nomor itu sudah
disebut pengguna ke dukungan dan mungkin sudah dicatat di sisi mereka.

### Nama, durasi, dan harga paket disalin ke invoice

Harga paket bisa berubah dan paketnya bisa dihapus. Invoice lama harus tetap
menunjukkan apa yang benar-benar dibeli, bukan keadaan paket hari ini. Relasi
ke `membership_plans` memakai `nullOnDelete`.

### Transaksi tersimpan SEBELUM gateway dipanggil

Provider bisa mengirim callback lebih cepat daripada jawaban charge sampai
kembali ke kita. Kalau referensinya belum ada di basis data saat itu, callback
yang sah akan ditolak sebagai "referensi tidak dikenal" — dan pengguna yang
sudah membayar tidak mendapat apa-apa.

Panggilan gateway sengaja **di luar** `DB::transaction`: ia menyentuh jaringan,
dan menahan transaction selama permintaan HTTP berlangsung akan mengunci baris
selama gateway lambat.

Ada pemeriksaan otomatis yang membandingkan posisi kedua baris itu.

### Empat penjagaan di jalur callback

| Penjagaan | Kenapa ada |
|---|---|
| Tanda tangan | Callback tidak sah = orang yang mengaktifkan membership tanpa membayar |
| `lockForUpdate()` | Dua callback bersamaan tanpa kunci sama-sama lolos "belum lunas" |
| `canTransitionTo()` | Callback terlambat tidak boleh mengembalikan lunas jadi menunggu |
| Cocokkan nominal | Bayar kurang tidak diaktifkan — ditandai perlu diperiksa, bukan ditolak diam-diam |

Status yang sama datang lagi **bukan kesalahan**: dijawab 200 tanpa mengerjakan
apa pun, supaya provider berhenti mengirim ulang.

### Nominal verifikasi manual diambil dari tagihan, bukan diketik admin

Membiarkan admin mengetik angkanya berarti penjagaan pencocokan nominal bisa
dilewati dengan mengetik ulang angka yang salah.

### Perpanjangan menumpuk, tidak menimpa

Pengguna yang membeli lagi sementara langganannya masih berjalan mendapat masa
aktif yang **ditambahkan** ke sisa yang ada. Menghitung ulang dari hari ini
berarti orang yang memperpanjang lebih awal kehilangan sisa hari yang sudah
dibayarnya — dan itu keluhan yang benar, bukan salah paham.

### `users.is_premium` adalah ringkasan, bukan sumber kebenaran

Sumbernya tabel `subscriptions`. Kolom ringkas ada karena dibaca pada **setiap**
pemeriksaan akses — pemutar web dan bot Telegram — dan join ke subscriptions
setiap kali orang menekan play adalah biaya yang tidak perlu.

Yang menjaganya sinkron: `MembershipService::syncUserFlags()`, satu tempat,
dipanggil setiap kali langganan berubah.

`EpisodeAccessRepository` **tetap** membandingkan `premium_expired_at` sendiri
meski scheduler juga mengedaluwarsakan langganan — scheduler berjalan berkala
dan bisa terlambat beberapa menit.

### Kode HTTP callback dipilih per jenis kegagalan

Provider memutuskan mengirim ulang berdasarkan kode yang kita kembalikan:

- **200** — berhasil, dan callback ganda
- **400** — tanda tangan tidak sah, referensi tidak dikenal (mengulang tidak akan mengubah apa pun)
- **404** — provider tidak dikenal
- **500** — kegagalan kita sendiri (di sinilah pengiriman ulang justru berguna)

### Tagihan orang lain dijawab 404, bukan 403

Menjawab "dilarang" memberi tahu bahwa nomor tagihannya benar ada — sudah satu
keterangan lebih banyak daripada yang perlu diketahui orang yang bukan
pemiliknya. Nomor invoice memuat bagian acak, tetapi itu bukan pengganti
pemeriksaan kepemilikan.

### Kredensial kosong tidak menimpa yang tersimpan

Form tidak pernah menampilkan nilai lamanya — menampilkan secret key di
atribut `value` HTML sama saja dengan tidak mengenkripsinya. Jadi kosong
berarti "tidak diubah", bukan "dikosongkan".

### Trakteer: pengakuan yang harus dibaca

Trakteer tidak punya API pembuatan transaksi. Penyambungan ke tagihan lewat
**pesan bebas** pendukung, dengan pola `INV-YYYYMMDD-XXXXXX`.

Itu tidak sekuat referensi yang dijamin gateway. Pendukung yang salah ketik
akan menghasilkan pembayaran yang tidak tersambung ke tagihan mana pun. Itu
bukan kegagalan yang bisa dihilangkan kode — Trakteer memang tidak menyediakan
tempat lain untuk menaruhnya.

Yang bisa dilakukan, dan dilakukan: pembayaran yang tidak dikenali dicatat
lengkap dengan seluruh payload-nya sebagai `payment.callback.unmatched`,
plus peringatan ke operator. **Tidak ada uang yang hilang tanpa jejak.**

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    80 blade, 0 bermasalah
python tools/check-css-coverage.py        257 kelas, semua punya aturan
python tools/check-php-structure.py       431 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
python tools/audit-sprint-8-1.py          81/81 lolos
python tools/audit-sprint-8-2.py          125/125 lolos
python tools/audit-sprint-8-7.py          133/133 lolos
python tools/audit-sprint-9-1.py          117/117 lolos
python tools/audit-phase-10.py            164/164 lolos
```

### Satu GAGAL palsu dari skrip audit saya sendiri

`audit-phase-10.py` versi pertama melarang konstanta `PaymentStatus::PAID`
muncul di luar dua service, dan melaporkan `TrakteerGateway` melanggarnya.

Kodenya benar. Driver memang **harus** boleh melaporkan lunas — itu tugasnya
membaca jawaban provider. Yang tidak boleh adalah driver **menulis** keadaan
itu ke basis data.

Diperbaiki jadi dua pemeriksaan yang lebih tepat: driver tidak boleh memanggil
`save()`/`update()`/`forceFill()` sama sekali, dan hanya dua service yang boleh
menulis status lunas.

### Empat audit lama ikut diperbaiki, dan itu bukan regresi

`audit-sprint-8-1`, `8-2`, `8-7`, dan `9-1` melaporkan GAGAL pada
"Http:: hanya ada di TelegramClient", karena `AbstractGateway` memakai
`Http::`.

Asersinya yang kedaluwarsa. Invarian yang dijaga bukan "tidak ada HTTP di mana
pun" melainkan **"tidak ada HTTP ke Telegram di luar TelegramClient"** — dan
lapisan pembayaran tidak menyentuh Telegram sama sekali. Keempatnya dipersempit
ke invarian sebenarnya.

Ini pola yang sama dengan tiga GAGAL di audit 8.2 saat Sprint 8.9: pemeriksaan
yang menguji **lokasi** kode, bukan **invariannya**, akan selalu menghalangi
perluasan yang benar.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
migration yang dijalankan, tidak ada pembayaran yang benar-benar diproses.

---

## Belum dikerjakan (sengaja)

- **Tiga driver gateway** — alasannya di keputusan desain di atas.
- **Auto renewal.** Kolom `auto_renew` ada dan bisa diisi; yang memperpanjang
  otomatis saat jatuh tempo belum ada. Perpanjangan otomatis memerlukan
  penyimpanan token kartu di sisi provider dan alur pembatalan yang bisa
  diandalkan — keduanya keputusan yang lebih besar daripada satu kolom boolean.
- **Alur pengembalian dana.** `RefundStatus` lengkap, kolomnya ada, panel
  menampilkannya. Yang belum ada: yang memanggil API pengembalian dana provider.
  Spesifikasi menyebut "Refund Status (struktur data)", dan itu yang dikerjakan.
- **Kupon dan diskon.** Tidak diminta, dan menambahkannya sekarang berarti
  menebak bentuk aturannya.
- **Faktur PDF.** Halaman tagihan bisa dicetak peramban. PDF otomatis dari PHP
  masih tercatat sebagai "belum ada sama sekali" di STATUS.md sejak Sprint 6.
- **Pembayaran dari dalam bot Telegram.** Tombol Premium mengantar ke website.
