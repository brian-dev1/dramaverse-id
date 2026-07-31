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

---

## Menguji webhook tanpa membayar sungguhan

```bash
php artisan payment:webhook-test INV-20260801-AB12CD
```

Payload tiruan diserahkan ke `PaymentCallbackService` **yang sama persis**
dengan yang dipakai callback sungguhan — verifikasi tanda tangan, penjagaan
nominal, penjagaan perpindahan status, idempotensi, dan aktivasi membership
semuanya berjalan.

| Opsi | Untuk apa |
|---|---|
| `--amount=50000` | Uji penolakan nominal yang tidak cocok |
| `--bad-signature` | Uji penolakan tanda tangan. **Harus ditolak** |
| `--message="tanpa nomor"` | Uji pembayaran yang tidak tersambung ke tagihan |
| `--dry` | Hanya tampilkan payloadnya |
| `--provider=<slug>` | Paksa provider tertentu |

Yang **tidak** diuji perintah ini: routing, CSRF, dan rate limit. Ketiganya di
lapisan HTTP. Perintah `curl` untuk mengujinya dicetak di akhir keluaran —
pakai itu, karena CSRF pernah menolak seluruh callback pembayaran dengan 419
sebelum satu baris kode pun berjalan.

## Memasang webhook Trakteer

1. Buka dashboard Trakteer, bagian **Integrasi / Webhook**.
2. Isi URL: `https://dracinverse.cloud/payment/callback/<slug-provider>`
   — slug-nya ada di `/admin/payment/provider`.
3. Salin **Webhook Token** dari Trakteer ke kolom kredensial provider di
   panel admin.
4. Aktifkan providernya, tandai sebagai default bila perlu.
5. Uji: `php artisan payment:webhook-test <nomor-invoice>`

### Cara Trakteer menyambungkan pembayaran ke tagihan

Trakteer **tidak** menyediakan field referensi. Satu-satunya tempat adalah
**pesan pendukung**, dan itu diketik manusia.

Nomor tagihan sudah diisikan otomatis ke tautan checkout, dan halaman tagihan
menampilkannya besar-besar dengan peringatan supaya tidak dihapus. Pembacaannya
toleran: huruf kecil, spasi, dan tanda hubung yang hilang tetap dikenali.

Pembayaran yang tetap tidak tersambung dicatat sebagai
`payment.callback.unmatched` di `/admin/payment/log` beserta nama pendukung,
nominal, dan seluruh payloadnya — supaya bisa dicocokkan manual. **Tidak ada
uang yang hilang tanpa jejak**, tetapi ada yang butuh dicocokkan tangan.

### Nominal yang dibaca

Yang dipakai nominal **kotor** (`amount`, atau `price` dikali `quantity`),
bukan `net_amount`. `net_amount` adalah yang diterima setelah potongan
Trakteer — memakainya membuat setiap pembayaran tampak kurang bayar dan
ditolak penjagaan nominal.

---

## Pembayaran bertahap dengan satuan (Trakteer)

Trakteer menjual per satuan, bukan per nominal. Satu paket berharga beberapa
kali harga satuannya — paket Rp 25.000 dengan cendol Rp 5.000 berarti
**5 cendol**.

### Cara mengaturnya

Di `/admin/payment/provider`, kredensial Trakteer sekarang punya dua field
tambahan:

Trakteer mengizinkan kreator membuat **beberapa unit dengan harga berbeda**.
Karena itu kredensialnya satu field bebas, bukan sepasang nama-harga:

| Field | Wajib? | Isi |
|---|---|---|
| `webhook_token` | ya | Token dari dashboard Trakteer |
| `page_url` | ya | `https://trakteer.id/namaanda` |
| `units` | **tidak** | Daftar unit, satu per baris |

Isi `units` begini:

```
Cendol=5000
Kopi=2000
Boba=10000
```

Titik dan koma sebagai pemisah ribuan boleh dipakai (`5.000` sama dengan
`5000`). Baris yang bentuknya salah dilewati, tidak menggagalkan seluruh
daftar.

Halaman tagihan lalu menampilkan **semua pilihan**, yang membagi habis lebih
dulu:

| Kirim | Harga satuan | Total |
|---|---|---|
| 5 Cendol **pas** | Rp 5.000 | Rp 25.000 |
| 13 Kopi | Rp 2.000 | Rp 26.000 *(lebih Rp 1.000)* |
| 3 Boba | Rp 10.000 | Rp 30.000 *(lebih Rp 5.000)* |

Pembulatannya **ke atas**. Mengirim kurang satu unit berarti tagihan tidak
pernah lunas, dan itu jauh lebih menjengkelkan daripada kelebihan sedikit.

### `units` hanya untuk tampilan

Ini yang paling penting: **pencocokan pembayaran tidak bergantung pada daftar
itu sama sekali.** Nominal dibaca dari payload webhook, apa pun unit yang
dipakai pendukung.

Artinya daftar yang basi — karena Anda mengubah harga di Trakteer dan lupa
memperbaruinya di panel — tidak pernah membuat pembayaran yang sah jadi
ditolak. Yang terjadi hanya saran jumlahnya meleset. Itu pemisahan yang
disengaja: konfigurasi yang salah tidak boleh menghalangi uang masuk.

Field ini juga **boleh dikosongkan**. Provider tetap bisa dipakai; halaman
tagihan hanya menampilkan nominal rupiahnya saja.

### Boleh dicicil

Pengguna bisa mengirim 3 cendol sekarang dan 2 lagi nanti. Setiap webhook
menambah `invoices.paid_amount`, dan membership aktif **otomatis** begitu
jumlahnya cukup — tanpa admin menyentuh apa pun.

Selama belum cukup:

- Tagihan tetap berstatus Menunggu.
- Halaman tagihan menampilkan sudah masuk berapa, persen, dan sisanya.
- **Menu Profil di bot** menampilkan hal yang sama, sehingga pengguna bisa
  memeriksanya tanpa membuka website.
- Log mencatat `payment.callback.partial` setiap kali ada cicilan masuk.

Syaratnya satu: **nomor tagihan harus selalu ikut di kolom pesan**, di setiap
cicilan. Tanpa nomor itu, pembayarannya tidak tersambung ke tagihan mana pun.

### Yang tetap ditolak

| Keadaan | Diterima? |
|---|---|
| Kurang bayar, driver bertahap (Trakteer) | Ya, dijumlahkan |
| Kurang bayar, gateway biasa | **Tidak** — mereka menagih nominal pasti |
| **Lebih bayar**, driver mana pun | **Tidak** |

Lebih bayar ditolak termasuk untuk Trakteer. Uang yang masuk lebih banyak dari
yang ditagih perlu diputuskan manusia — dikembalikan, atau dianggap dukungan
tambahan. Menerimanya diam-diam menghilangkan kesempatan itu. Transaksinya
ditandai perlu diperiksa manual, dengan sebabnya tertulis di panel.

### Mengujinya

```bash
# cicilan pertama: setengahnya
php artisan payment:webhook-test INV-xxxx --amount=12500

# cek: tagihan masih Menunggu, paid_amount terisi
# cicilan kedua: sisanya
php artisan payment:webhook-test INV-xxxx --amount=12500

# sekarang harus lunas dan langganan aktif
```
