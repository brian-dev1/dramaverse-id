# Sprint 6 — Admin Panel (Bagian 3 dari 3)

Sprint 6 selesai. 115 route terdaftar, 14 menu sidebar, semua pemeriksaan lolos.

---

## Yang ditambahkan di bagian ini

### Membership

CRUD penuh paket membership: nama, slug, harga, durasi, benefit (satu per
baris di textarea, tersimpan sebagai JSON), badge, urutan, status aktif.

**Satu penjagaan penting:** paket yang masih dipakai langganan tidak bisa
dihapus. Menghapusnya akan membuat riwayat pembayaran kehilangan acuan —
jadi sistem menolak dan menyarankan menonaktifkannya saja.

### Subscription

CRUD langganan beserta dua aksi khusus:

- **Perpanjang** — menambahkan durasi paket. Bila langganan masih berlaku,
  dihitung dari tanggal berakhir; bila sudah lewat, dihitung dari hari ini.
  Perbedaan ini penting supaya pengguna tidak kehilangan sisa masa aktif.
- **Batalkan** — mengubah status tanpa menghapus riwayatnya.

Saat status diubah menjadi Aktif tanpa tanggal, sistem mengisi tanggal mulai
dengan waktu sekarang dan menghitung tanggal berakhir dari durasi paket.

### Pengguna

Daftar dengan filter status, blokir, dan peran. Halaman detail menampilkan
riwayat tontonan, favorit, daftar saya, dan seluruh langganan.

Aksi suspend, blokir, dan hapus — dengan **dua penjagaan**: admin tidak bisa
mengubah akunnya sendiri, dan akun admin lain tidak bisa disentuh dari
halaman ini. Tanpa ini, satu klik keliru bisa mengunci Anda dari panel.
Aksi massal juga otomatis mengecualikan seluruh akun admin.

Pengguna tidak bisa ditambah lewat form — mereka dibuat oleh bot saat
mengirim `/start`. Jadi tombol Tambah memang tidak dirender.

### Telegram

Status bot dan webhook dibaca langsung dari API Telegram. Bila token belum
diisi atau jaringan bermasalah, halaman tetap terbuka dan menampilkan
petunjuk — tidak error.

**Broadcast** dengan empat segmen penerima: semua, aktif 30 hari terakhir,
anggota berlangganan, dan yang lama tidak aktif. Jumlah penerima ditampilkan
sebelum dikirim.

Pengiriman lewat antrean, **satu job per penerima**. Alasannya dua: Telegram
membatasi sekitar 30 pesan per detik, dan bila satu pengiriman gagal karena
pengguna memblokir bot, sisanya tetap terkirim. Pengguna yang memblokir bot
otomatis ditandai nonaktif supaya tidak terus dicoba di broadcast berikutnya.

Broadcast **membutuhkan worker antrean berjalan**. Supervisor sudah dipasang
di VPS Anda, jadi ini sudah terpenuhi.

### Settings

15 pengaturan dalam 5 grup: umum, SEO, kontak, media sosial, sistem.
Tersimpan ke tabel `settings` dan **benar-benar dipakai** — bukan form kosong:

- Nama situs dan tagline masuk ke `<title>` setiap halaman
- Deskripsi jadi meta description
- Favicon dan gambar berbagi dipasang di `<head>`
- Deskripsi dan teks footer dipakai komponen footer

Helper `setting('key')` membaca dari cache, jadi aman dipanggil berulang
dari Blade tanpa menambah query.

**Mode pemeliharaan** menutup halaman publik tapi membiarkan panel admin dan
webhook Telegram tetap hidup. Ini sengaja berbeda dari `php artisan down` —
Anda bisa mematikannya kembali lewat panel tanpa perlu masuk ke server.

---

## Perbaikan yang ditemukan saat pengerjaan

**`composer.json` mereferensikan berkas yang tidak ada.** Saat menambahkan
helper `setting()`, skrip saya ikut menuliskan `app/Helpers/format.php` ke
blok `autoload.files` — padahal berkas itu tidak pernah ada. Composer akan
membuat `require` ke path kosong, dan setiap permintaan berakhir fatal error.
Sudah dibuang; sekarang skrip memverifikasi tiap entri benar-benar ada.

**Verifier tidak memahami prefix nama bertingkat.** Route seperti
`admin.user.ban` didaftarkan lewat grup bersarang, dan regex lama hanya
membaca potongan terakhir. Sekarang ada `tools/routeparse.py` yang menelusuri
kurung kurawal dan menyusun nama lengkap. Jumlah route yang terdeteksi naik
dari 87 menjadi 115 — selisihnya bukan route baru, melainkan yang selama ini
luput dari pemeriksaan.

---

## Hasil verifikasi

```
CEK ROUTE MATI DI BLADE      OK   115 route terdefinisi
CEK ROUTE MATI DI PHP        OK
CEK CONTROLLER ADA           OK
CEK VIEW ADA                 OK
CEK KOMPONEN BLADE           OK
CEK LAYOUT                   OK
CEK MODEL vs MIGRATION       OK
CEK URUTAN FOREIGN KEY       OK
CEK BINDING REPOSITORY       OK   25/25
CEK PSR-4                    OK
CEK IMPORT CSS               OK
CEK KOLOM TANGGAL            OK
CEK FORM                     OK   @csrf + action valid
CEK HREF                     OK   tidak ada tautan buntu
CEK IKON                     OK   nol emoji / SVG inline
DIREKTIF BLADE               OK   60 berkas seimbang
MENU SIDEBAR                 OK   14 menu, semua punya route
CLASS CSS                    OK   0 tanpa aturan dari 192
STRUKTUR PHP                 OK   296 berkas
VIEW CONTROLLER ADMIN        OK   semua ada
```

---

## Deploy

```
git add -A
git commit -m "Sprint 6 bagian 3: membership, subscription, user, telegram, settings"
git push origin main
```

Di VPS:

```bash
cd /var/www/dramaverse
bash deploy.sh
php artisan migrate --force
composer dump-autoload
supervisorctl restart dramaverse-worker:*
```

`composer dump-autoload` diperlukan karena helper `setting()` baru
didaftarkan di `composer.json`. Tanpa itu, fungsi tersebut tidak ditemukan
dan setiap halaman akan error.

Restart worker diperlukan supaya job broadcast yang baru dikenali.

---

## Urutan uji

1. `/admin/settings` — ubah nama situs, simpan, buka beranda dan cek judul tab
2. `/admin/membership` — ubah paket VIP, tambahkan benefit
3. `/admin/subscription` — tambah langganan manual, lalu coba Perpanjang
4. `/admin/user` — buka detail satu pengguna, coba nonaktifkan
5. `/admin/telegram` — cek status bot terbaca, kirim broadcast ke segmen kecil
6. `/admin/settings` — nyalakan mode pemeliharaan, buka beranda di jendela
   penyamaran, lalu matikan lagi

Nomor 6 patut dicoba dengan hati-hati: bila mode pemeliharaan menyala dan
Anda keluar dari sesi admin, satu-satunya cara mematikannya adalah masuk
kembali ke `/admin/login` — halaman itu sengaja tidak ikut ditutup.

---

## Batas yang tetap berlaku

Semua verifikasi statis. PHP tidak tersedia di lingkungan tempat saya
bekerja, jadi tidak ada halaman yang pernah dirender dan tidak ada form yang
pernah dikirim.

Yang paling mungkin bermasalah di bagian ini:

1. **Helper `setting()`** — bila `composer dump-autoload` terlewat, seluruh
   situs mati. Ini risiko terbesar dari deploy ini.
2. **Broadcast Telegram** — bergantung pada worker antrean. Bila pesan tidak
   sampai, periksa `supervisorctl status` dan
   `storage/logs/worker.log`.
3. **Mode pemeliharaan** — middleware membaca dari cache. Bila tidak langsung
   berpengaruh, jalankan `php artisan config:cache`.

---

## Sisa spesifikasi Sprint 6 yang belum dikerjakan

Supaya tidak ada klaim berlebihan:

- **Ekspor Excel dan PDF** — hanya CSV yang tersedia. Keduanya butuh paket
  tambahan yang Anda pilih untuk dilewati.
- **Resize gambar otomatis** — gambar disimpan apa adanya, hanya dibatasi
  4 MB lewat validasi.
- **Role dan permission bertingkat** — saat ini hanya ada penanda `is_admin`.
  Tabel `roles` dan `permissions` sudah ada di database tapi belum dipakai.
- **Batch upload episode** — episode ditambahkan satu per satu.
- **Sort episode drag-and-drop** — urutan diatur lewat kolom nomor.
- **Rate limit khusus panel admin** — belum dipasang.

Semuanya bisa dikerjakan kalau Anda perlukan; tidak ada yang saya
sembunyikan sebagai "sudah jadi".
