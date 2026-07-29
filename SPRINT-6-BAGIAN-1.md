# Sprint 6 — Admin Panel (Bagian 1 dari 3)

**Fokus bagian ini:** CRUD katalog. Setelah ini Anda bisa mengisi drama,
episode, genre, negara, dan banner lewat antarmuka — tanpa menyentuh database.

---

## Kejujuran soal ruang lingkup

Spesifikasi Sprint 6 mencakup 15 menu, 8 grafik, ekspor Excel/CSV/PDF,
broadcast Telegram, dan sistem role-permission. Itu tidak muat dalam satu
sesi. Memaksakannya akan menghasilkan kode yang kelihatan jadi tapi tidak
berfungsi — persis yang Anda larang.

Jadi dipecah tiga:

| Bagian | Isi | Status |
|---|---|---|
| **1** | Layout admin, fondasi CRUD, CRUD Drama/Episode/Genre/Country/Banner, upload gambar, bulk action | **Selesai** |
| 2 | Dashboard statistik, 8 grafik, Analytics, Report + ekspor CSV | Berikutnya |
| 3 | CRUD Membership/Subscription, manajemen User, Telegram broadcast, Settings, role-permission | Menyusul |

Menu bagian 2 dan 3 **sudah ada dan bisa diklik** — menampilkan daftar
baca-saja yang berfungsi, bukan halaman kosong. Tombol tambah/ubah/hapus
sengaja tidak dirender untuk modul itu, karena rutenya memang belum ada.
Tidak ada dead link.

---

## Yang bisa dipakai sekarang

### Fondasi tanpa duplikasi

`AdminCrudController` menangani seluruh siklus CRUD: daftar dengan pencarian,
filter, urutan, pagination, form tambah/ubah, simpan, hapus, pulihkan, dan
aksi massal. Sembilan controller turunan hanya mendeklarasikan konfigurasi —
model, kolom, aturan validasi. Tidak ada logika yang ditulis dua kali.

Ini juga berarti menambah entitas baru nanti cukup ~60 baris konfigurasi.

### CRUD Drama

Judul, judul asli, slug otomatis, sinopsis, poster, cover, gradien cadangan,
trailer, negara, genre ganda, tahun, jumlah episode, durasi, status, rating,
penanda VIP/unggulan/trending, skor trending, jadwal terbit.

Aksi massal: terbitkan, jadikan draf, tandai VIP, tandai gratis, hapus.
Filter: status, negara, akses. Soft delete dengan pemulihan.

Slug dijamin unik — bila bentrok, ditambahkan sufiks angka otomatis.

### CRUD Episode

Nomor episode terisi otomatis saat drama dipilih, tapi tidak menimpa bila
Anda sudah mengetik manual. Sumber video MP4 atau embed, thumbnail, durasi,
penanda VIP, jadwal tayang dan kedaluwarsa.

`total_episode` pada drama disinkronkan otomatis setiap episode ditambah,
dihapus, atau diproses massal — jadi angkanya tidak pernah meleset.

Nomor episode unik per drama, divalidasi di tingkat database dan form.

### CRUD Genre, Country, Banner

Genre: ikon dari pustaka SVG, warna aksen, urutan, jumlah drama.
Country: kode ISO dua huruf (penanda visual, bukan emoji), jumlah drama.
Banner: gambar, posisi, tautan, jadwal mulai dan berhenti tayang.

### Unggah gambar

`MediaService` menyimpan ke disk `public`, memberi nama UUID, dan
**menghapus berkas lama saat diganti** supaya storage tidak menumpuk sampah.
Form mendukung seret-dan-lepas dengan pratinjau langsung sebelum dikirim.

Tanpa paket tambahan — sesuai pilihan Anda. Gambar disimpan apa adanya,
jadi unggah file besar akan tetap besar. Batas 4 MB diterapkan lewat validasi.

### Catatan aktivitas

Setiap tambah, ubah, hapus, pulihkan, dan aksi massal tercatat ke
`activity_logs` beserta pengguna, IP, dan waktu. Tercatat otomatis dari base
controller, jadi tidak mungkin terlewat di salah satu modul.

---

## Antarmuka

Sidebar terkelompok empat bagian dengan 12 menu, bisa dilipat, dan posisinya
diingat lewat `localStorage`. Di layar sempit berubah jadi panel geser.

Tabel: pencarian, filter kombinasi, urutan per kolom yang bisa diklik,
pilih-semua, bilah aksi massal yang muncul saat ada yang dipilih, thumbnail
inline, badge status.

Form: tata letak kartu, sakelar untuk boolean, unggah dengan pratinjau,
pesan galat per field. Penghapusan lewat dialog konfirmasi, bukan
`confirm()` bawaan browser.

Semua ikon SVG lewat `<x-web.home.icon>` — 31 ikon, nol emoji.

---

## Perubahan database

Satu migration baru menambah kolom yang dibutuhkan panel:

- `genres.icon`, `genres.color`
- `countries.description`

Jalankan `php artisan migrate` — bukan `migrate:fresh`, data Anda aman.

---

## Hasil verifikasi

Skrip pemeriksa kini 16 titik, ditambah pemeriksa direktif Blade yang baru:

```
CEK ROUTE MATI DI BLADE      OK   85 route terdefinisi
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
DIREKTIF BLADE               OK   52 berkas, semua seimbang
MENU SIDEBAR                 OK   12 menu, semua punya route
CLASS CSS                    OK   0 tanpa aturan dari 178
STRUKTUR PHP                 OK   287 berkas
```

Dua alat baru di `tools/`:

- `check-blade-directives.py` — mendeteksi `@if`/`@foreach` yang tidak
  tertutup. Kesalahan ini menggagalkan kompilasi Blade dan tidak terlihat
  sampai halaman dibuka. Alat ini langsung menemukan satu di berkas yang
  baru saya tulis.
- Verifier diperluas agar mengenali route yang didaftarkan lewat perulangan.

---

## Deploy

```bash
git add -A
git commit -m "Sprint 6 bagian 1: fondasi CRUD admin + katalog"
git push origin main
```

Di VPS:

```bash
cd /var/www/dramaverse
bash deploy.sh
php artisan migrate --force
php artisan storage:link
```

`storage:link` wajib — tanpa itu gambar yang diunggah tidak akan tampil.

Lalu masuk `/admin/login` dan coba tambah satu drama.

---

## Batas yang perlu diketahui

Semua verifikasi bersifat **statis**. PHP tidak tersedia di lingkungan tempat
saya bekerja, jadi tidak ada satu pun halaman yang pernah benar-benar
dirender, dan tidak ada satu pun form yang pernah benar-benar dikirim.

Rekam jejak sprint sebelumnya menunjukkan batas ini nyata — beberapa
kesalahan hanya muncul saat dieksekusi. Yang paling mungkin bermasalah di
bagian ini:

1. **Unggah gambar** — perlu `storage:link` dan izin tulis pada
   `storage/app/public`. Bila gambar tidak tampil, itu penyebab pertama.
2. **Aksi massal** — form-nya membungkus tabel; pastikan checkbox terkirim.
3. **Nomor episode otomatis** — bergantung pada JavaScript; bila Vite belum
   di-build ulang, tidak akan jalan.

Uji berurutan: tambah genre → tambah negara → tambah drama dengan poster →
tambah episode → cek tampil di beranda.

Kirim pesan galat apa pun yang muncul.
