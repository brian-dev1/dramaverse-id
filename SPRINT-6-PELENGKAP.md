# Sprint 6 — Pelengkap

Enam butir yang sebelumnya saya tandai belum dikerjakan, kini selesai.
**Tanpa satu pun paket tambahan** — semuanya memakai kemampuan bawaan PHP.

127 route, 15 menu sidebar, semua pemeriksaan lolos.

---

## 1. Resize gambar otomatis

`ImageProcessor` memakai ekstensi **GD bawaan PHP**, bukan
`intervention/image`. GD sudah terpasang di VPS Anda (`php8.3-gd`).

Batas dimensi per peruntukan:

| Jenis | Maksimal |
|---|---|
| Poster | 600 × 900 |
| Cover, banner | 1600 × 900 |
| Thumbnail episode | 640 × 360 |
| Logo, favicon | 512 × 512 |

Rasio dipertahankan, transparansi PNG dan WebP dijaga, JPEG dikompres ke
kualitas 82.

**Satu hal yang mudah terlewat:** foto dari ponsel sering tersimpan miring
dengan penanda orientasi EXIF. Tanpa koreksi, poster yang diunggah bisa
tampil terbalik. Processor membaca EXIF dan memutar gambar sebelum
menyimpannya.

Bila GD tidak tersedia atau berkas gagal diproses, **unggahan tetap
berhasil** — gambar hanya tidak dioptimalkan. Kegagalan optimasi tidak
boleh membatalkan pekerjaan admin.

---

## 2. Ekspor Excel dan PDF

### XLSX asli, bukan CSV berganti nama

`XlsxWriter` menulis berkas XLSX sungguhan memakai `ZipArchive` bawaan PHP.
XLSX pada dasarnya arsip ZIP berisi beberapa berkas XML — jadi formatnya
bisa ditulis langsung tanpa `maatwebsite/excel`.

Yang dihasilkan: satu lembar, baris judul tebal, lebar kolom menyesuaikan
isi terpanjang.

**Dua penjagaan pada penulisan angka:**

- Nilai berawalan nol (nomor telepon, kode) ditulis sebagai teks — Excel
  akan memotong nol depannya bila dianggap angka.
- Bilangan lebih dari 15 digit (ID Telegram) juga ditulis sebagai teks —
  Excel mengubahnya menjadi notasi ilmiah.

Keduanya tampak sepele sampai laporan Anda menampilkan `8.94769E+09`
alih-alih ID pengguna.

### PDF lewat halaman cetak

Membuat PDF sungguhan dari PHP membutuhkan paket. Yang tersedia sekarang
adalah **halaman cetak yang dioptimalkan** — buka, tekan tombol cetak,
pilih "Simpan sebagai PDF".

Halaman itu punya gaya sendiri (bukan tema gelap panel), judul tabel
berulang di tiap halaman, dan baris tidak terpotong antar halaman.

Ini kompromi yang jujur, bukan PDF sungguhan. Kalau Anda perlu PDF
otomatis — misalnya dilampirkan ke email terjadwal — itu butuh
`barryvdh/laravel-dompdf`.

---

## 3. Role dan permission

Tabel `roles` dan `permissions` yang selama ini menganggur kini aktif.

**13 izin** dikelompokkan per modul: drama, episode, taksonomi, membership,
pengguna, telegram, laporan, log, pengaturan, peran.

**4 peran bawaan:**

| Peran | Cakupan |
|---|---|
| Super Admin | Seluruh izin, tidak dapat dihapus |
| Editor Konten | Katalog: drama, episode, genre, negara, banner |
| Moderator | Pengguna, langganan, Telegram, laporan |
| Pengamat | Hanya melihat, tanpa mengubah apa pun |

Setiap izin terdaftar sebagai Gate, jadi `@can('drama.manage')` bekerja di
Blade dan `permission:` bekerja sebagai middleware route. Sidebar otomatis
menyembunyikan menu yang tidak boleh diakses — termasuk seluruh kelompok
bila tidak ada satu pun isinya yang terbuka.

**Dua penjagaan terhadap penguncian diri:**

1. Akun dengan penanda `is_admin` **tanpa peran apa pun** diperlakukan
   sebagai super admin. Tanpa ini, menjalankan migration di server yang
   sudah punya admin akan langsung mengunci panel.
2. Peran Super Admin tidak dapat dihapus, dan izinnya tidak dapat dikurangi
   lewat form.

---

## 4. Batch upload episode

Form terpisah untuk membuat banyak episode sekaligus: pilih drama, nomor
awal, jumlah, lalu semua dibuat dalam satu transaksi.

**Pola URL** mendukung penanda: `{n}` untuk nomor, `{nn}` dua digit,
`{nnn}` tiga digit. Contoh `https://cdn.contoh.com/judul/ep-{nn}.mp4`
menghasilkan `ep-01.mp4`, `ep-02.mp4`, dan seterusnya.

**Nomor yang sudah ada dilewati, bukan ditimpa** — dan jumlah yang
dilewati dilaporkan. Menimpa diam-diam akan menghapus URL video yang sudah
Anda isi.

Batas 100 episode sekali jalan.

---

## 5. Urutan episode dengan seret-lepas

Memakai HTML5 Drag and Drop bawaan peramban, tanpa pustaka. Aktif hanya
saat daftar disaring ke satu drama — mengurutkan campuran episode dari
banyak drama tidak bermakna.

**Penomoran ulang dilakukan dua tahap.** Semua nomor dinaikkan 10000 dulu,
baru diturunkan ke urutan final. Tanpa itu, menukar episode 1 dan 2 akan
melanggar batasan unik `(drama_id, episode_number)` di tengah proses.

Bila penyimpanan gagal, urutan **dikembalikan seperti semula**. Tampilan
tidak boleh berbeda dari isi database.

---

## 6. Rate limit dan pengerasan

**Empat pembatas:**

| Nama | Batas | Sasaran |
|---|---|---|
| `admin-login` | 5/menit per email+IP, 20/menit per IP | Penebakan kata sandi |
| `admin-write` | 60/menit per pengguna | Skrip otomatis di panel |
| `broadcast` | 6/jam per pengguna | Penyalahgunaan broadcast |
| `api` | 90/menit | Endpoint publik |

Login dibatasi per **kombinasi email dan IP**, bukan email saja. Kalau
hanya per email, penyerang bisa mengunci akun Anda hanya dengan menebak
berulang dari IP mana pun.

**Header keamanan** dipasang di tingkat aplikasi, bukan hanya Nginx —
supaya ikut terbawa bila server web diganti. Panel admin mendapat tambahan
`X-Robots-Tag: noindex`, `X-Frame-Options: DENY`, dan `Cache-Control:
no-store`.

---

## Alat verifikasi bertambah

`tools/` kini berisi 5 skrip:

| Berkas | Fungsi |
|---|---|
| `verify-consistency.py` | 16 pemeriksaan: route, view, komponen, model, PSR-4, form, ikon |
| `check-blade-directives.py` | `@if`/`@foreach` yang tidak tertutup |
| `check-css-coverage.py` | Kelas CSS tanpa aturan |
| `check-php-structure.py` | Keseimbangan kurung |
| `routeparse.py` | Parser nama route bertingkat |

**Parser route diperbaiki dua kali** selama sesi ini. Awalnya tidak
memahami prefix nama bertingkat, lalu tidak memahami rantai method yang
membentang beberapa baris. Setiap perbaikan menaikkan jumlah route yang
terdeteksi — dari 87, ke 115, ke 127. Selisihnya bukan route baru,
melainkan yang selama ini luput dari pemeriksaan.

---

## Deploy

```
git add -A
git commit -m "Sprint 6 pelengkap: resize GD, XLSX, role-permission, batch episode, rate limit"
git push origin main
```

Di VPS:

```bash
cd /var/www/dramaverse
bash deploy.sh
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RoleSeeder --force
composer dump-autoload
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
supervisorctl restart dramaverse-worker:*
```

`RoleSeeder` perlu dijalankan terpisah supaya peran dan izin terisi tanpa
menyentuh data lain. Aman diulang.

Pastikan GD aktif:

```bash
php -m | grep -i gd
```

Kalau kosong: `apt install -y php8.3-gd && systemctl restart php8.3-fpm`.

---

## Urutan uji

1. `/admin/role` — buka peran Editor, lihat izinnya tercentang
2. `/admin/drama` — unggah poster besar, periksa ukuran berkas hasilnya di
   `storage/app/public/drama/poster`
3. `/admin/episode/batch` — buat 12 episode dengan pola URL
4. `/admin/episode?drama_id=1` — seret baris, muat ulang, cek urutan tersimpan
5. `/admin/report` — unduh Excel, buka di spreadsheet
6. `/admin/report` — klik PDF, tekan cetak
7. Coba login admin dengan sandi salah 6 kali — harus diblokir sementara

---

## Yang masih benar-benar belum ada

Supaya tidak ada klaim berlebihan:

- **PDF otomatis dari PHP** — hanya lewat dialog cetak peramban
- **Halaman kelola izin terpisah** — izin dikelola dari dalam form peran
- **Log masuk terpisah** — semua tercatat di satu tabel `activity_logs`
- **Impor data dari CSV/Excel** — hanya ekspor yang tersedia
- **Notifikasi in-app untuk admin** — hanya toast setelah aksi

Semuanya bisa dikerjakan bila Anda perlukan.

---

## Batas yang tetap berlaku

Verifikasi seluruhnya statis. PHP tidak tersedia di lingkungan tempat saya
bekerja — tidak ada halaman yang pernah dirender, tidak ada gambar yang
pernah benar-benar di-resize, tidak ada berkas XLSX yang pernah dibuka di
Excel.

Yang paling mungkin bermasalah:

1. **XLSX** — format ini ketat. Bila Excel menolak membukanya, kirim pesan
   galatnya; kemungkinan besar soal escaping XML pada karakter tertentu.
2. **Resize GD** — bila `php-gd` tidak aktif, gambar tersimpan tanpa
   diperkecil (tidak error, hanya tidak optimal).
3. **Role-permission** — bila `RoleSeeder` belum jalan, akun admin lama
   tetap punya akses penuh berkat penjagaan nomor 1 di atas.
