# Sprint 1 — Selesai

**Tanggal:** 29 Juli 2026
**Status:** semua pemeriksaan konsistensi lolos

---

## Cara menjalankan

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# atur DB_* di .env, lalu:
php artisan migrate --seed
php artisan storage:link

npm run dev          # atau: npm run build
php artisan serve
```

Buka `http://localhost:8000`. Homepage langsung terisi 26 drama contoh
beserta episode, genre, negara, banner, dan riwayat tontonan.

**Masuk admin:** `/admin/login` — `admin@dramaverse.id` / `dramaverse`
(ubah lewat `ADMIN_SEED_PASSWORD` di `.env` sebelum seeding).

---

## Yang diperbaiki dari hasil audit

### Blocker

**1. Migration inti kosong**
Enam tabel hanya punya `id` + `timestamps` sementara model mendeklarasikan
11 kolom. Sekarang lengkap dengan kolom bisnis, foreign key, index, dan
`softDeletes` pada `dramas`.

Ditemukan tambahan saat perbaikan: urutan migration salah — `dramas`
(timestamp `185828`) berjalan **sebelum** `countries` dan `genres`
(`190015`), sehingga foreign key-nya pasti gagal. Berkas dinomori ulang.

**2. Relasi genre keliru**
`Drama::genre()` adalah `belongsTo` lewat kolom `genre_id`, padahal satu
drama punya banyak genre. Diganti `belongsToMany` lewat pivot baru
`drama_genre`. Seluruh repository, filter, dan resource ikut disesuaikan.

**3. Lapisan API tidak terjangkau**
`routes/api.php` tidak terdaftar di `bootstrap/app.php`, dan `api_v1/v2`
berisi 0 route aktif. Sekarang `api:` terdaftar, dan `routes/api.php`
memuat endpoint yang benar-benar dipakai frontend: pencarian realtime dan
sinkronisasi progres pemutar.

**4. 14 nama route mati**
Navbar dan footer memanggil `web.home`, `web.trending`, `web.membership`
dan lainnya yang tidak pernah didefinisikan — membuat **setiap** halaman
melempar 500. Routing ditulis ulang dengan penamaan konsisten `web.*` dan
`admin.*`; seluruh referensi Blade diperbaiki.

### Ditemukan saat pengerjaan (tidak ada di audit awal)

**5. PSR-4 rusak — `EpisodeRepository`**
Kelas `App\Repositories\EpisodeRepository` disimpan di
`app/Repositories/Contracts/EpisodeRepository.php`. Composer tidak akan
pernah menemukannya, sehingga binding-nya gagal saat dipakai. Dipindahkan
ke lokasi yang benar.

**6. PSR-4 rusak — folder Console**
`app/Console/Command/` (tunggal) berisi kelas dengan namespace
`App\Console\Commands` (jamak). Kedua command di dalamnya tidak dapat
di-autoload. Folder diperbaiki.

**7. `scopeLatest` menimpa `Builder::latest()`**
Scope kustom bernama `latest` akan membajak method bawaan Eloquent di
seluruh codebase. Diganti `scopeLatestRelease()`.

**8. `WebSearchRepository` menyaring status yang tidak ada**
Query memfilter `status = 'published'`, padahal enum status berisi
`ongoing|completed|upcoming`. Hasil pencarian akan selalu kosong.
Diganti scope `published()` yang memeriksa `published_at`.

**9. Palet CSS tidak cocok dengan desain**
`theme.css` memakai ungu `#7C5CFF`, sedangkan preview yang disetujui
memakai emas `#D9AF6E` + crimson `#C1425B`. Token disamakan dengan
`dramaverse-preview.html`.

**10. `PlayerCompletedController` tidak cocok dengan route-nya**
Controller memakai route-model binding `Episode $episode`, tapi route
tidak punya parameter. Route diubah menjadi `/player/completed/{episode}`.

### Struktural

| Masalah | Sebelum | Sesudah |
|---|---|---|
| Komponen yatim | 76 dari 117 (65%) | 0 — dikarantina ke `_staging/` |
| CSS tidak ter-bundle | 95 dari 106 (90%) | 0 — 13 aktif, 94 dikarantina |
| Interface tanpa binding | 20 dari 26 | 0 — 25 ter-bind otomatis |
| View legacy duplikat | 5 berkas + 2 folder | dihapus |
| Model usang | `PremiumSubscription` | dihapus |
| Nama berkas rusak | `...role_user_table.php.php` | diperbaiki |
| Migration telegram ganda | 2 berkas | digabung ke migration `users` |
| Seeder | akun email bawaan Laravel | akun Telegram + 26 drama contoh |

---

## Routing

50 route terdaftar, semuanya punya controller dan view yang benar-benar ada.

**Publik (23)** — beranda, pencarian + hasil, trending, terbaru, rating
tertinggi, baru rilis, populer, VIP, genre (indeks + detail), negara
(indeks + detail), detail drama, pemutar episode, membership, tentang,
bantuan, privasi, ketentuan, login Telegram, webhook.

**Pengguna (13, butuh login)** — profil, riwayat, lanjutkan menonton,
favorit (+toggle), daftar saya (+toggle), notifikasi (+tandai dibaca),
pengaturan (+simpan), keluar.

**Admin (16)** — login, dashboard, drama, episode, genre, negara, banner,
pengguna, membership, langganan, laporan, log, pengaturan.

---

## Struktur

```
app/Http/Controllers/
    Web/      HomeController, CatalogController, GenreController,
              CountryController, DramaController, EpisodeController,
              WebSearchController, ProfileController, HistoryController,
              FavoriteController, MyListController, NotificationController,
              SettingController, MembershipController, PageController
    Admin/    AuthController, DashboardController, ResourceController (abstrak)
              + 9 turunan konfigurasi saja, ReportController, SettingController
    Api/      Progress, PlayerResume, PlayerCompleted, Notification
    Auth/     TelegramAuthController

resources/views/
    web/layouts/     app, admin
    web/pages/       home, catalog, drama, episode, search, membership,
                     profile, history, favorites, my-list, notifications,
                     settings, genre/, country/, static/, admin/
    components/web/home/   13 komponen aktif

_staging/            103 komponen + 94 CSS menunggu sprint berikutnya
```

**Penghapusan duplikasi:** 9 halaman indeks admin berbagi satu
`ResourceController` abstrak dan satu view `resource.blade.php` — tiap
turunan hanya mendeklarasikan model, judul, dan kolom. Rail dan grid
homepage juga memakai dua komponen generik, bukan satu komponen per
section seperti sebelumnya.

---

## Hasil verifikasi

Dijalankan lewat skrip pemeriksa konsistensi:

```
CEK ROUTE MATI DI BLADE      OK   35 nama route dipakai, semuanya terdefinisi
CEK CONTROLLER ADA           OK   semua controller + method tersedia
CEK VIEW ADA                 OK   semua view yang dirender tersedia
CEK KOMPONEN BLADE           OK   semua komponen yang dipanggil tersedia
CEK LAYOUT                   OK   semua layout yang di-extend tersedia
CEK MODEL vs MIGRATION       OK   semua $fillable cocok dengan kolom
CEK URUTAN FOREIGN KEY       OK   semua FK menunjuk tabel yang sudah ada
CEK BINDING REPOSITORY       OK   25/25 interface ter-bind
CEK PSR-4                    OK   namespace cocok dengan lokasi berkas
CEK IMPORT CSS               OK   semua @import menunjuk berkas yang ada
CLASS CSS TANPA ATURAN       OK   0 dari 121
```

**Batas verifikasi yang jujur:** PHP tidak tersedia di lingkungan tempat
saya bekerja, jadi `php -l`, `php artisan route:list`, dan `migrate --seed`
**belum pernah dijalankan**. Pemeriksaan di atas bersifat statis —
menganalisis berkas, bukan mengeksekusinya. Sebelum menyatakan Sprint 1
benar-benar tuntas, jalankan di mesin Anda:

```bash
php artisan route:list          # harus menampilkan 50 route tanpa error
php artisan migrate:fresh --seed
php artisan config:clear && php artisan view:clear
npm run build
```

---

## Sprint berikutnya

**Sprint 2 — Pencarian & katalog.** Tarik 11 komponen `_staging/search/`,
sambungkan pencarian realtime ke `/api/v1/search`, tambah infinite scroll.

Sebelum memasang komponen dari `_staging/`, periksa referensi `route()`
di dalamnya terhadap `php artisan route:list` — komponen tersebut masih
memakai nama route lama.
