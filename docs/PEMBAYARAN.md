# Dokumentasi Pembayaran & Membership

## Pemisahan yang jadi intinya

Business Logic Membership **tidak tahu** provider mana yang dipakai, dan
gateway **tidak tahu** apa itu membership.

Menambah Stripe atau PayPal cukup dengan menulis satu kelas yang memenuhi
`PaymentGatewayInterface` dan menambah satu case di `PaymentDriver` — tanpa
menyentuh `MembershipService`, `InvoiceService`, `PaymentCallbackService`,
controller, maupun view mana pun.

```
CheckoutService          buat invoice, langganan PENDING, transaksi
        v
PaymentGatewayManager    pilih gateway dari baris provider
        v
PaymentGatewayInterface  charge / verify / parseCallback / cancel / refund
        v
PaymentCallbackService   SATU-SATUNYA jalan status boleh berubah
        v
MembershipService        aktifkan, perpanjang, kedaluwarsakan
```

## Driver

| Driver | Keadaan |
|---|---|
| `manual` | **Selesai.** Transfer bank, diverifikasi admin. Tidak butuh API |
| `trakteer` | **Selesai.** Webhook token |
| `midtrans`, `xendit`, `tripay` | Kerangka. Terdaftar, menolak dipakai |

Yang kerangka menolak dengan terang lewat `isImplemented() === false`, bukan
gagal di tengah checkout. Alur charge, bentuk callback, dan cara menghitung
tanda tangan hanya bisa dipastikan dengan akun sungguhan — menulisnya dari
ingatan menghasilkan kode yang lolos pemeriksaan statis lalu gagal pertama
kali ada orang yang benar-benar membayar.

### Menyelesaikan satu driver

1. Isi `charge()`, `verify()`, `parseCallback()` memakai `$this->http()` dan
   `$this->credential($provider, ...)`.
2. Verifikasi tanda tangan dengan `signatureMatches()` — yang memakai
   `hash_equals`, bukan `===`. **Lempar** bila tidak cocok.
3. Ubah `isImplemented()` di `PaymentDriver` untuk case itu.

Tidak ada berkas lain yang perlu disentuh.

## Empat penjagaan callback

1. **Tanda tangan** — diverifikasi di dalam driver, sebelum satu baris pun
   dibaca.
2. **Kunci baris** — `lockForUpdate()`. Provider mengirim ulang callback yang
   tidak dijawab 200, dan dua callback bersamaan tanpa kunci akan sama-sama
   lolos lalu mengaktifkan membership dua kali.
3. **Perpindahan status** — callback terlambat tidak boleh mengembalikan
   transaksi lunas jadi menunggu.
4. **Nominal** — yang kurang tidak diaktifkan, ditandai perlu diperiksa
   manual. Uangnya sudah terlanjur masuk; menolaknya diam-diam lebih buruk.

Verifikasi manual admin melewati jalur yang sama, jadi keempatnya tetap
berlaku.

## Invoice

Nomornya `INV-YYYYMMDD-XXXXXX`. Bagian acaknya bukan hiasan: nomor urut
membuat siapa pun bisa menebak nomor tagihan orang lain dan membukanya.

Nama, durasi, dan harga paket **disalin** ke invoice. Harga bisa berubah dan
paketnya bisa dihapus; invoice lama harus tetap menunjukkan apa yang
benar-benar dibeli.

Satu invoice bisa punya beberapa transaksi — percobaan yang gagal lalu dicoba
lagi dengan provider lain tidak menghapus riwayat percobaan pertama.

## Membership

**Perpanjangan menumpuk, tidak menimpa.** Masa aktif baru ditambahkan ke sisa
yang ada. Menghitung ulang dari hari ini berarti orang yang memperpanjang
lebih awal kehilangan sisa hari yang sudah dibayarnya.

`users.is_premium` dan `users.premium_expired_at` adalah **ringkasan** dari
tabel `subscriptions`, bukan sumber kebenarannya. Disimpan karena dibaca pada
setiap pemeriksaan akses — di pemutar web maupun di bot. Yang menjaganya
sinkron: `MembershipService::syncUserFlags()`, satu tempat.

## Perintah

```bash
php artisan payment:auto verify   # tanyakan ulang yang masih menunggu
php artisan payment:auto expire   # kedaluwarsakan tagihan dan langganan
```

Keduanya dijadwalkan. Lihat [ANTREAN.md](ANTREAN.md).

## Yang belum ada

- **Pengembalian dana dari panel.** Struktur datanya lengkap (`RefundStatus`,
  kolomnya, tampilannya); yang memanggil API pengembalian dana belum ada.
- **Perpanjangan otomatis.** `auto_renew` tersimpan, tetapi tidak ada yang
  menagih ulang saat jatuh tempo.
- **Pembayaran dari dalam bot.** Tombol Upgrade mengantar ke website.
