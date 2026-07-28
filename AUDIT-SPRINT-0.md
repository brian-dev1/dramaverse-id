# AUDIT STRUKTUR PROJECT — DramaVerse ID
**Sprint 0 · Pre-Implementation Audit**
Tanggal: 29 Juli 2026 · Auditor: Lead Developer
Status: **MENUNGGU PERSETUJUAN — belum ada kode baru yang ditulis**

---

## 1. RINGKASAN EKSEKUTIF

Project ini **tidak dapat dijalankan** dalam kondisi sekarang. Bukan karena bug kecil,
tapi karena tiga lapisan fondasi terputus satu sama lain:

| Lapisan | Kondisi | Dampak |
|---|---|---|
| Database | 6 migration inti **kosong** (hanya `id` + `timestamps`) | Model punya 11 kolom yang tidak ada di tabel → query gagal total |
| API | `routes/api.php` **tidak terdaftar** di `bootstrap/app.php`, dan `api_v1/v2` berisi **0 route** (semua dikomentari) | 22 controller + 40 service + 54 file repository tidak bisa dijangkau sama sekali |
| View | 14 nama route dipanggil di Blade tapi **tidak pernah didefinisikan** | Setiap halaman yang memuat navbar/footer → `RouteNotFoundException` (HTTP 500) |

**Volume kode yang ada:** 211 file PHP di `app/`, 129 Blade, 107 CSS, 29 migration, 22 model.
**Volume kode yang benar-benar hidup:** kurang dari 20%.

Diagnosisnya bukan "kurang kode" — justru kelebihan kode yang tidak tersambung.
Yang dibutuhkan adalah **menyambungkan dan merampingkan**, bukan menulis fitur baru.

---

## 2. TEMUAN KRITIS (BLOCKER)

### 2.1 — Migration inti kosong 🔴 BLOCKER

Enam tabel utama dibuat tanpa satu pun kolom bisnis:

```
create_dramas_table.php               → id, timestamps saja
create_episodes_table.php             → id, timestamps saja
create_favorites_table.php            → id, timestamps saja
create_genres_table.php               → id, timestamps saja
create_countries_table.php            → id, timestamps saja
create_premium_subscriptions_table.php → id, timestamps saja
```

Padahal `app/Models/Drama.php` mendeklarasikan:
```php
protected $fillable = [
    'title', 'slug', 'description', 'poster', 'cover',
    'country_id', 'genre_id', 'release_year',
    'total_episode', 'status', 'is_trending',
];
```

Sebelas kolom ini **tidak ada di database**. Tidak ada migration `add_*_to_dramas_table`
yang menambalnya. Akibatnya `php artisan migrate --seed` menghasilkan skema kosong dan
homepage langsung error begitu menyentuh data.

**Catatan tambahan:** relasi genre dipakai sebagai `belongsTo` (`genre_id` di tabel dramas),
padahal spesifikasi menuntut satu drama punya banyak genre. Perlu pivot `drama_genre`.
Tidak ada migration pivot sama sekali.

### 2.2 — Seluruh lapisan API tidak terjangkau 🔴 BLOCKER

`bootstrap/app.php`:
```php
->withRouting(
    web:      __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health:   '/up',
)
// tidak ada baris  api: ...
```

Dan isi `routes/api_v1.php` (36 baris) seluruhnya komentar:
```php
Route::prefix('v1')->group(function () {
    // require auth.php
    // require drama.php
    // require episode.php
});
```

Hasil hitung route aktif di `api_v1.php` + `api_v2.php` = **0**.

Yang jadi mati total karena ini:
- 22 controller di `app/Http/Controllers/Api/`
- 26 interface repository, 54 file implementasi
- 40 service class
- Seluruh `app/Http/Resources/`

Ini bagian terbesar dari codebase, dan tidak satu barisnya pernah dieksekusi.

### 2.3 — 14 nama route mati dipanggil di Blade 🔴 BLOCKER

Route yang **terdefinisi** di `routes/web.php` hanya 8:
```
home · search · drama.show · episode.show
telegram.login · telegram.webhook · web.history · dashboard
```

Route yang **dipanggil dari Blade tapi tidak ada**:

| Route mati | Dipanggil di |
|---|---|
| `web.home` | `navbar.blade.php` (2×), `footer.blade.php` (2×) |
| `web.trending` | `trending.blade.php`, `footer.blade.php` |
| `web.latest` | `latest-release.blade.php`, `footer.blade.php` |
| `web.popular` | `popular.blade.php`, `footer.blade.php` |
| `web.top-rated` | `top-rated.blade.php` |
| `web.membership` | `membership-banner.blade.php` (3×), `footer.blade.php` |
| `web.account` | `membership-banner.blade.php`, `footer.blade.php` |
| `web.detail` | `drama-card.blade.php`, `hero.blade.php` |
| `web.watch` | `hero.blade.php` |
| `admin.drama.index` | `admin/drama/create.blade.php` |
| `admin.episode.index` | `admin/episode/create.blade.php`, `episode-form-publish.blade.php` |
| `admin.episode.create` | `admin/episode/index.blade.php`, `episode-empty.blade.php` |
| `login` · `register` | `welcome.blade.php` |

Karena `navbar` dan `footer` dipanggil dari `layouts/app.blade.php`, **setiap halaman**
di aplikasi ini akan melempar 500 sebelum sempat merender apa pun.

Perhatikan juga penamaan tidak konsisten: route asli bernama `home`, tapi Blade memanggil
`web.home`. Prefix `web.` dipakai setengah jalan.

### 2.4 — 20 dari 26 interface repository tidak di-bind 🟠 TINGGI

`AppServiceProvider` hanya mengikat 6 interface. Yang belum terikat:

```
ActivityLog · AdminCountry · AdminDrama · AdminEpisode · AdminGenre · Admin
Banner · DramaCatalog · EpisodeAccess · EpisodeScheduler · Favorite
Media · Membership · Recommendation · Review · Search · Setting
Telegram · Watchlist
```

Saat ini dorman (karena API mati). Begitu route API diaktifkan tanpa binding →
`BindingResolutionException` di 19 endpoint.

---

## 3. TEMUAN STRUKTURAL

### 3.1 — 76 dari 117 komponen Blade yatim (65%)

Komponen yang dibuat tapi tidak pernah dipanggil dari mana pun:

| Kelompok | Jumlah | Contoh |
|---|---|---|
| `web.profile.*` | 13 | profile-header, profile-menu, profile-device… |
| `web.membership.*` | 13 | membership-plan, membership-invoice, membership-faq… |
| `web.search.*` | 11 | search-result, search-empty, search-pagination… |
| `web.drama.*` | 12 | drama-cast, drama-review, drama-gallery… |
| `web.admin.*` | 14 | admin-sidebar, admin-dashboard-stat… |
| `web.about.*` | 8 | about-hero, about-team, about-timeline… |
| `web.contact.*` | 6 | contact-form, contact-map, contact-faq… |
| lain-lain | 3 | web-search-header, skeleton-card |

**Ini bukan sampah** — ini UI yang sudah jadi tapi belum dipasang ke halaman.
Rekomendasi: **karantina, jangan hapus**, lalu sambungkan per sprint sesuai jadwal.

Pengecualian: `web-search-header.blade.php` berada di root `components/` (bukan di dalam
folder `web/search/`) — ini pelanggaran konvensi dan duplikat dari `web/search/search-box`.

### 3.2 — 95 dari 106 file CSS tidak pernah di-bundle (90%)

`resources/css/app.css` hanya meng-import 11 file:
```
web/home/{theme,navbar,hero,card,section,animation,responsive}.css
web/search/search.css
web/detail/{detail,episode,responsive}.css
```

95 file sisanya (`profile-*.css`, `membership-*.css`, `admin-*.css`, `about-*.css`,
`contact-*.css`, `drama-*.css`, `search-*.css`) tidak masuk Vite → tidak pernah sampai ke browser.

Pola "satu komponen = satu file CSS" ini juga bermasalah: 106 file CSS untuk 117 komponen
membuat maintenance berat dan bertabrakan dengan TailwindCSS yang sudah dipakai.

### 3.3 — View legacy duplikat

Ada dua sistem view yang hidup berdampingan:

| Legacy (hapus) | Konvensi baru (pakai) |
|---|---|
| `resources/views/home.blade.php` | `resources/views/web/pages/home.blade.php` ✅ |
| `resources/views/search.blade.php` | `resources/views/web/pages/web-search.blade.php` ✅ |
| `resources/views/drama/` | (belum ada — perlu dibuat) |
| `resources/views/episode/` | (belum ada — perlu dibuat) |
| `resources/views/welcome.blade.php` | — hapus, memanggil `login`/`register` |

`SearchController` masih menunjuk ke view legacy: `view('search')` — bukan `web.pages.web-search`.

### 3.4 — Model duplikat / konsep tumpang tindih

Tiga model untuk satu domain yang sama:
```
PremiumSubscription.php   ← migration-nya kosong, konsep lama
Subscription.php          ← konsep baru
MembershipPlan.php        ← konsep baru
```
`PremiumSubscription` adalah sisa iterasi lama dan harus dibuang.

### 3.5 — Masalah migration lain

| Berkas | Masalah |
|---|---|
| `2026_07_27_073654_create_role_user_table.php.php` | Ekstensi ganda `.php.php` — salah nama |
| `..._184436_add_telegram_columns_to_users_table.php` | Duplikat konsep dengan… |
| `..._184730_add_telegram_fields_to_users_table.php` | …migration ini. Dua migration untuk hal yang sama |

### 3.6 — Seeder bertentangan dengan spesifikasi

`DatabaseSeeder.php` masih bawaan Laravel:
```php
User::factory()->create([
    'name'  => 'Test User',
    'email' => 'test@example.com',
]);
```
Spesifikasi menyatakan **tidak ada login email, tidak ada register** — semua via Telegram.
Seeder ini harus diganti dengan seeder berbasis `telegram_id`, plus seeder konten
(drama, episode, genre, country, banner) yang saat ini **tidak ada sama sekali**.

---

## 4. KLASIFIKASI FILE

### 4.1 ✅ DIPERTAHANKAN — layak pakai, sedikit/tanpa perubahan

| Path | Alasan |
|---|---|
| `resources/views/web/layouts/app.blade.php` | Struktur layout benar, hanya perlu perbaikan referensi route |
| `resources/views/web/pages/home.blade.php` | Susunan section sudah persis sesuai desain preview |
| `resources/views/components/web/home/*` (13 file) | Komponen homepage yang benar-benar terpasang |
| `resources/css/web/home/*.css` + `web/detail/*` + `search.css` | 11 file yang sudah masuk bundle |
| `app/Models/*` (22 file) | Relasi Eloquent sudah benar — perlu diselaraskan dengan migration |
| `app/Telegram/*` | Lapisan bot mandiri dan koheren |
| `config/telegram.php` | Konfigurasi valid |
| `app/Http/Controllers/{Home,Drama,Episode}Controller.php` | Pola invokable + service injection sudah rapi |
| `app/Services/HomeService.php` + 6 repository ter-bind | Service layer yang aktif |
| `bootstrap/app.php`, `vite.config.js`, `package.json`, `artisan`, `.env.example` | Skeleton Laravel 12 standar |
| `dramaverse-preview.html` | **Sumber kebenaran desain** — token warna, tipografi, layout |

### 4.2 🔧 DITULIS ULANG — file ada tapi isinya harus diganti

| Path | Tindakan |
|---|---|
| 6 migration stub (dramas, episodes, favorites, genres, countries) | Tulis ulang lengkap: kolom, foreign key, index |
| `routes/web.php` | Tulis ulang: 18 route publik + 9 user + 13 admin, penamaan konsisten `web.*` |
| `bootstrap/app.php` | Tambah `api:` ke `withRouting`, daftarkan middleware alias |
| `app/Providers/AppServiceProvider.php` | Bind 20 interface yang tertinggal |
| `resources/css/app.css` | Rombak strategi CSS — konsolidasi, bukan 106 file terpisah |
| `database/seeders/DatabaseSeeder.php` | Ganti seeder email → Telegram + seeder konten |
| `app/Http/Controllers/SearchController.php` | Arahkan ke `web.pages.*`, bukan view legacy |
| `resources/views/components/web/home/{navbar,footer}.blade.php` | Perbaiki 8 referensi route mati |

### 4.3 📦 DIKARANTINA — simpan, sambungkan per sprint

Dipindah ke `resources/views/_staging/` agar tidak ikut ter-render, lalu ditarik kembali
satu per satu saat sprint-nya tiba:

| Kelompok | Jumlah | Sprint tujuan |
|---|---|---|
| `web/search/*` | 11 | Sprint 2 |
| `web/drama/*` | 12 | Sprint 3 |
| `web/profile/*` | 13 | Sprint 4 |
| `web/membership/*` | 13 | Sprint 5 |
| `web/about/*`, `web/contact/*` | 14 | Sprint 5 |
| `web/admin/*` | 14 | Sprint 6 |

Beserta file CSS pasangannya.

### 4.4 ❌ DIHAPUS — duplikat, konflik, atau bertentangan dengan spesifikasi

| Path | Alasan |
|---|---|
| `resources/views/welcome.blade.php` | Memanggil `route('login')` & `route('register')` — tidak ada login email |
| `resources/views/home.blade.php` | Duplikat legacy dari `web/pages/home.blade.php` |
| `resources/views/search.blade.php` | Duplikat legacy |
| `resources/views/drama/` | Direktori legacy |
| `resources/views/episode/` | Direktori legacy |
| `resources/views/components/web-search-header.blade.php` | Salah lokasi + duplikat `search-box` |
| `app/Models/PremiumSubscription.php` | Digantikan `Subscription` + `MembershipPlan` |
| `..._create_premium_subscriptions_table.php` | Tabel kosong, konsep usang |
| `..._create_role_user_table.php.php` | Nama berkas rusak — dibuat ulang dengan nama benar |
| `..._184730_add_telegram_fields_to_users_table.php` | Duplikat; digabung ke migration users utama |
| `routes/api_v1.php`, `routes/api_v2.php` | 0 route aktif — diganti struktur API yang benar |

### 4.5 ➕ HARUS DIBUAT — belum ada sama sekali

- `resources/views/web/layouts/admin.blade.php` — layout admin
- `resources/views/components/web/home/mobile-nav.blade.php` — bottom nav mobile (ada di desain, tidak ada di kode)
- Migration pivot `drama_genre`
- Migration `my_lists`, `banners` (index/FK lengkap)
- Seeder konten: `GenreSeeder`, `CountrySeeder`, `DramaSeeder`, `EpisodeSeeder`, `BannerSeeder`, `MembershipPlanSeeder`
- `app/Http/Middleware/TelegramAuth.php` + alias middleware
- Halaman untuk 30+ route yang belum punya view

---

## 5. RENCANA PERBAIKAN

Urutan ini wajib — setiap langkah bergantung pada langkah sebelumnya.

**Langkah 1 — Fondasi database**
Tulis ulang 6 migration stub, tambah pivot `drama_genre`, perbaiki nama berkas rusak,
gabungkan migration telegram ganda. Selaraskan `$fillable` semua model dengan kolom nyata.

**Langkah 2 — Routing & binding**
Tulis ulang `routes/web.php` dengan penamaan konsisten. Daftarkan `api:` di `bootstrap/app.php`.
Bind 20 interface yang tertinggal.

**Langkah 3 — Sambungkan view**
Perbaiki 14 referensi route mati. Hapus view legacy. Karantina 76 komponen yatim.

**Langkah 4 — Data**
Seeder Telegram + seeder konten agar homepage terisi saat `migrate --seed`.

**Langkah 5 — Verifikasi**
`php artisan route:list` bersih · seluruh route punya controller & view ·
tidak ada `RouteNotFoundException` · homepage render penuh dengan data.

---

## 6. KEPUTUSAN YANG DIBUTUHKAN

Tiga hal perlu ditetapkan sebelum implementasi dimulai:

1. **Strategi CSS** — pertahankan 106 file CSS terpisah, atau konsolidasi ke Tailwind
   + satu file token? (Rekomendasi: konsolidasi. Pola sekarang bertabrakan dengan Tailwind
   dan 90% file-nya tidak ter-bundle.)

2. **Nasib lapisan API** — aktifkan `routes/api.php` untuk 22 controller yang ada, atau
   buang API dan jadikan aplikasi murni Blade + Alpine? (Rekomendasi: aktifkan sebagian —
   hanya endpoint yang benar-benar dipakai frontend seperti progress player & search realtime.)

3. **Karantina vs hapus** untuk 76 komponen yatim. (Rekomendasi: karantina.)

---

*Tidak ada kode implementasi yang ditulis. Menunggu persetujuan atas laporan ini.*
