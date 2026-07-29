# Sprint 2 — Selesai

**Fokus:** buang data karangan, jujur saat kosong, rapikan layout, pastikan semua link hidup.

---

## 1. Data dummy dipisahkan dari data nyata

Sebelumnya `php artisan db:seed` memasukkan 26 judul drama karangan
("Sutra & Baja", "Hujan di Gyeongbokgung", dan seterusnya) beserta ratusan
episode fiktif. Di produksi itu berbahaya: katalog terlihat penuh padahal
tidak ada satu pun video yang bisa diputar.

Sekarang dipisah tegas:

**`DatabaseSeeder` — dijalankan di produksi.** Hanya data referensi yang
memang harus ada:

| Seeder | Isi | Alasan dipertahankan |
|---|---|---|
| `GenreSeeder` | 10 genre | Taksonomi nyata, bukan karangan |
| `CountrySeeder` | 6 negara | Data faktual |
| `MembershipPlanSeeder` | Gratis, VIP, Premium | Struktur harga, perlu disesuaikan admin |
| `AdminSeeder` | 1 akun admin | Tanpa ini panel tidak bisa diakses |

**`Demo\DemoSeeder` — tidak pernah dipanggil otomatis.** Berisi drama,
banner, pengguna contoh, dan riwayat tontonan. Menolak berjalan bila
`APP_ENV=production`.

```bash
# hanya saat pengembangan
php artisan db:seed --class=Database\\Seeders\\Demo\\DemoSeeder
```

---

## 2. Halaman kosong tampil apa adanya

Prinsipnya: **lebih baik halaman kosong yang jujur daripada angka palsu.**

- **Hero** tidak lagi menampilkan judul karangan saat katalog kosong. Berisi
  deskripsi platform, dan bagi admin muncul tombol menuju kelola katalog.
  Pesan lama yang menyuruh menjalankan `php artisan migrate --seed` dihapus —
  sudah tidak relevan dan membingungkan pengguna akhir.
- **Homepage** menampilkan pemberitahuan "Katalog belum diisi" bila seluruh
  blok drama kosong, bukan sekadar halaman melompong. Genre dan negara tetap
  ditampilkan karena keduanya data nyata.
- **Profil** menampilkan arahan bila belum ada aktivitas, bukan blok kosong.
- **Pemutar episode** menjaga sidebar saat drama hanya punya satu episode.
- Halaman katalog, genre, negara, pencarian, favorit, daftar saya, riwayat,
  notifikasi, dan seluruh tabel admin sudah punya penanganan kosong.

---

## 3. Layout dirapikan

| Masalah | Perbaikan |
|---|---|
| Banner membership muncul di **semua** halaman — termasuk `/membership` sendiri, halaman privasi, dan area pengguna | Jadi opt-in lewat `@section('promo')`. Hanya tampil di beranda, katalog, detail drama, genre, dan negara — dan hanya untuk pengunjung yang belum masuk |
| Pagination di `catalog.blade.php` tidak memakai `section-pad`, jaraknya beda dari halaman lain | Diseragamkan; 8 halaman kini identik |
| `style="padding:9px 18px..."` di navbar | Dipindah ke kelas `.btn-sm` |
| `style="margin-bottom:..."` di detail drama | Dipindah ke kelas `.detail-genres` |
| `style="margin-top:32px"` pada pembatas perforasi | Dipindah ke aturan `.perf` |

Satu-satunya gaya inline yang tersisa adalah lebar bar progres — memang nilai
dinamis, tidak bisa dipindah ke CSS.

**Cakupan CSS:** 123 kelas dipakai di Blade, semuanya punya aturan. Tidak ada
kelas yatim.

---

## 4. Verifikasi link

Skrip pemeriksa diperluas dari 13 menjadi **15 titik**. Dua yang baru:

**Cek form** — setiap `<form>` bermetode POST/PUT/PATCH/DELETE wajib memuat
`@csrf`, dan `action`-nya harus menunjuk route yang terdefinisi. Form tanpa
CSRF akan gagal dengan 419 di produksi, dan itu tipe kesalahan yang tidak
muncul saat `APP_DEBUG=true` di lokal.

**Cek href** — tidak boleh ada `href="#"` atau `href=""`. Tautan buntu adalah
bentuk lain dari dead link: tidak error, tapi tidak mengantar ke mana pun.

Hasil lengkap:

```
CEK ROUTE MATI DI BLADE      OK   semuanya terdefinisi
CEK ROUTE MATI DI PHP        OK   semuanya terdefinisi
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
CLASS CSS TANPA ATURAN       OK   0 dari 123
```

Pemeriksaan struktur PHP: 284 berkas, nol bermasalah.

---

## Cara deploy

```bash
# di komputer
git add -A
git commit -m "Sprint 2: pisahkan data demo, empty state jujur, rapikan layout"
git push origin main

# di VPS
cd /var/www/dramaverse && bash deploy.sh
```

**Penting soal database VPS.** Saat ini berisi 26 drama contoh dari seeding
sebelumnya. Untuk membersihkannya:

```bash
php artisan migrate:fresh --seed --force
```

Perintah itu mengosongkan seluruh tabel lalu mengisi ulang hanya dengan genre,
negara, paket membership, dan akun admin. Katalog akan kosong — dan itu memang
yang diinginkan sampai Anda memasukkan judul sungguhan.

Setelah itu ganti sandi admin:

```bash
php artisan admin:password admin@dramaverse.id
```

---

## Batas yang perlu diketahui

Verifikasi di atas seluruhnya **statis** — menganalisis berkas, bukan
menjalankannya. PHP tidak tersedia di lingkungan tempat saya bekerja, jadi
`php artisan route:list` dan pemuatan halaman sungguhan belum pernah saya
jalankan sendiri.

Sprint 1 membuktikan batas ini nyata: tiga kesalahan lolos dari pemeriksaan
statis dan baru muncul saat dieksekusi — kolom timestamp tanpa `nullable`,
seeder yang bergantung pada route cache, dan urutan pembersihan cache.
Pemeriksaan untuk ketiganya sudah ditambahkan, tapi kelas kesalahan lain
tetap mungkin ada.

Setelah deploy, buka halaman-halaman ini di browser untuk memastikan:

- `/` — hero tanpa judul karangan, pemberitahuan katalog kosong
- `/trending`, `/latest`, `/top-rated` — empty state, bukan halaman melompong
- `/genre`, `/country` — pil taksonomi tetap terisi
- `/membership` — banner promo **tidak** muncul dua kali
- `/privacy`, `/terms` — tanpa banner promo
- `/admin/dashboard` — angka statistik nol, bukan error

---

## Berikutnya

**Sprint 3 — Admin CRUD.** Panel admin sekarang hanya bisa menampilkan
daftar. Agar katalog bisa diisi lewat antarmuka, dibutuhkan tambah/ubah/hapus
untuk drama, episode, genre, negara, dan banner — beserta unggah poster.

Tanpa itu, satu-satunya cara mengisi katalog adalah lewat database langsung.
