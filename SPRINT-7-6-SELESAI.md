# Sprint 7.6 — Drama Asset Management

Selesai: 30 Juli 2026

Sepuluh jenis aset drama lewat Storage Engine. Foundation Multi Storage,
Storage Engine, dan Upload Video Episode **tidak diubah**. Belum ada Telegram,
Queue, Retry, Streaming, Video Player, AI Compression, dan CDN Optimization.

---

## Cara memakainya dari kode

```php
use App\Enums\DramaAssetType;
use App\Services\DramaAssetService;

// Auto — provider default
$asset = $service->upload($drama, DramaAssetType::POSTER, $file);

// Manual — provider tertentu (harus aktif dan lolos Test Connection)
$asset = $service->upload($drama, DramaAssetType::BACKDROP, $file, providerId: 3);

// Galeri: banyak berkas, kegagalan satu tidak membatalkan yang lain
['berhasil' => $ok, 'gagal' => $gagal] =
    $service->uploadMany($drama, DramaAssetType::GALLERY, $files);

// Semua aset satu drama, dikelompokkan per jenis
$service->grouped($drama);
```

Modul lain (Website, API, Telegram di sprint berikutnya) cukup memanggil
`DramaAssetService`. Tidak perlu tahu provider, driver, atau nama disk.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/DramaAssetType.php` | 10 jenis + aturan per jenis |
| `database/migrations/2026_07_30_230000_create_drama_assets_table.php` | Tabel |
| `app/Models/DramaAsset.php` | Model |
| `app/Services/DramaAssetService.php` | Aturan bisnis |
| `app/Http/Requests/Admin/StoreDramaAssetRequest.php` | Validasi |
| `app/Http/Controllers/Admin/DramaAssetController.php` | Halaman + endpoint |
| `resources/views/web/pages/admin/drama-assets.blade.php` | Asset Manager |

## Berkas yang disunting

- `app/Models/Drama.php` — relasi `assets()`
- `routes/web.php` — tiga route
- `resources/js/admin.js` — modul `assetManager()`
- `resources/css/web/admin/admin.css` — 17 kelas baru
- `resources/views/web/pages/admin/crud/index.blade.php` — tombol "Kelola aset"
- `tools/routeparse.py` — perbaikan bug, lihat bawah
- `tools/verify-consistency.py` — `DramaAsset` masuk pemeriksaan fillable

---

## Sepuluh jenis aset

| Jenis | Ekstensi | Batas | Jumlah |
|---|---|---|---|
| Poster | jpg jpeg png webp avif | 4 MB | satu |
| Cover Desktop | idem | 8 MB | satu |
| Cover Mobile | idem | 4 MB | satu |
| Backdrop | idem | 8 MB | satu |
| Banner | idem | 8 MB | satu |
| Thumbnail | idem | 4 MB | satu |
| Logo Drama | idem | 4 MB | satu |
| Thumbnail Trailer | idem | 4 MB | satu |
| **Galeri** | idem | 4 MB | **banyak** |
| Subtitle | srt vtt ass ssa | 2 MB | satu |

Direktori: `drama/{id}/{jenis}` — dikelompokkan per drama, bukan per jenis,
supaya seluruh aset satu drama berada di satu tempat saat harus diperiksa,
dipindahkan, atau dibersihkan bersama.

---

## Keputusan desain

### SVG tidak diterima

Spesifikasi menyebut SVG opsional. Saya tidak menerimanya, dan alasannya
bukan kemalasan: SVG adalah dokumen XML yang boleh memuat `<script>`, dan aset
ini disajikan dari domain yang sama dengan panel admin. Satu SVG berisi skrip
yang dibuka langsung berarti skrip itu berjalan dengan sesi admin yang sedang
aktif.

Menerimanya dengan aman memerlukan pembersihan isi berkas lebih dulu, dan itu
pekerjaan tersendiri — bukan satu baris tambahan di daftar ekstensi.

### Aset drama bersifat publik, berbeda dari video episode

Video episode privat karena berbayar. Poster dan banner harus bisa dimuat
peramban siapa pun tanpa autentikasi — menjadikannya privat berarti setiap
gambar di beranda perlu URL bertanda tangan yang kedaluwarsa, dan halaman akan
penuh gambar rusak begitu tautannya lewat masa berlaku.

Subtitle **tingkat drama** juga publik: isinya teks terjemahan, bukan videonya.
Subtitle per episode yang mengikuti isi berbayar bukan bagian sprint ini.

### `uploadTo()`, bukan `upload()`

Engine punya `upload()` yang membaca direktori dan visibility dari
`StorageCollection`. Saya memakai `uploadTo()` dengan direktori dari
`DramaAssetType`, karena menambahkan case ke `StorageCollection` berarti
mengubah Storage Engine — yang dilarang sprint ini.

Pembatasan ekstensi dan ukuran tetap ditegakkan, di dua tempat yang membaca
enum yang sama: `StoreDramaAssetRequest` dan `DramaAssetService::assertAllowed()`.
Yang kedua ada karena service ini juga akan dipakai dari luar HTTP nanti (API,
artisan, Telegram), dan penjagaan yang hanya ada di FormRequest tidak berlaku
di sana.

### Kegagalan satu berkas galeri tidak membatalkan yang lain

`uploadMany()` mengembalikan `['berhasil' => ..., 'gagal' => ...]`. Mengunggah
sepuluh gambar lalu kehilangan sembilan yang berhasil karena satu yang rusak
adalah perilaku yang menyakitkan dan tidak perlu. Panel melaporkan mana yang
gagal beserta sebabnya.

Bila **tidak ada satu pun** yang berhasil, responsnya 422 — itu kegagalan,
bukan keberhasilan sebagian, dan tidak boleh tampil sebagai pesan sukses kosong.

### Hapus: baris dihapus meski berkasnya gagal dihapus

Baris yang tertinggal akan terus menampilkan aset yang admin kira sudah
dihapus, dan itu lebih membingungkan daripada satu berkas yatim di bucket —
yang setidaknya tercatat di log sebagai `drama.asset.orphan` dan bisa
dibersihkan.

### Keunikan "satu berkas per jenis" dijaga aplikasi, bukan database

Sembilan dari sepuluh jenis hanya boleh punya satu berkas, tetapi galeri boleh
banyak. Aturan "unik kecuali untuk satu nilai" memerlukan partial index, yang
tidak ada di MySQL. Yang menjaganya adalah `DramaAssetService`, lewat
`updateOrCreate` pada `(drama_id, asset_type)` untuk jenis tunggal.

Dicatat terbuka di komentar migration. Selama semua penulisan lewat service
itu, aturannya berlaku.

### Duplikasi dengan `EpisodeVideoService`

Alurnya serupa: checksum → engine → metadata → kompensasi. Keduanya **tidak**
disatukan, karena menyatukannya berarti mengubah modul upload video episode
yang secara tegas dilarang spesifikasi 7.6.

Yang benar-benar berbagi kode — pengiriman berkas itu sendiri — sudah berada di
Storage Engine dan tidak ditulis ulang di kedua tempat. Yang tersisa adalah
pola persistensi metadata yang bentuk tabelnya berbeda. Penyatuannya jadi satu
`StoredFileWriter` layak dipertimbangkan di sprint yang boleh menyentuh
keduanya.

---

## Bug yang ditemukan dan diperbaiki

### `routeparse.py` salah menghitung kurung kurawal di dalam string

Route baru memakai `->prefix('drama/{drama}/asset')`. Parser menghitung `{` dan
`}` per baris untuk melacak kedalaman blok — **termasuk yang ada di dalam
string**. Akibatnya prefix nama didorong ke stack pada kedalaman yang terlalu
dalam lalu langsung dibuang pada baris yang sama, dan seluruh route di dalam
grup kehilangan awalan namanya.

Gejalanya menyesatkan: `verify-consistency.py` melaporkan
`admin.drama.asset.store` sebagai route mati, padahal route-nya benar dan yang
salah parsernya. Saya sempat mencari kesalahan di `routes/web.php` sebelum
menyadari itu.

Diperbaiki dengan mengosongkan isi string literal sebelum kurung dihitung.
Diverifikasi: ketiga route `drama.asset.*` kini terdeteksi, dan 84 nama route
lain tetap utuh.

Ini bug yang akan mengenai setiap route dengan parameter di dalam prefix —
tidak hanya sprint ini.

### Hapus aset akan 405

Rancangan pertama saya mengirim `POST` dengan `_method: 'DELETE'` di dalam body
JSON. Laravel hanya membaca method spoofing dari body ber-form-encoding; pada
body JSON nilainya tidak pernah sampai ke `Request`, sehingga permintaannya
tetap POST dan route `Route::delete` membalas 405.

Diganti `fetch(..., { method: 'DELETE' })` — DELETE sungguhan, tanpa spoofing.

### Karakter `·` melanggar aturan ikon proyek

Saya memakai titik tengah sebagai pemisah di kartu aset. `U+00B7` ada di daftar
larangan `verify-consistency.py` — perenderannya berbeda antar sistem operasi,
persis alasan aturan itu dibuat. Diganti em dash, yang sudah dipakai di
seluruh proyek.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       350 berkas, 0 bermasalah
python tools/check-css-coverage.py        222 kelas, semua punya aturan
python tools/check-blade-directives.py    66 blade, 0 bermasalah
node (sintaks admin.js)                   valid
```

Self-audit (40 pemeriksaan, semuanya lolos):

- Controller dan Service: nol `Storage::`, nol `->store()`/`->storeAs()`, nol
  `disk(`, nol penulisan berkas mentah — diperiksa setelah komentar dibuang
- upload lewat `engine->uploadTo()`, hapus lewat `engine->delete()`
- Controller tidak menyimpan metadata sendiri
- 10 jenis aset, termasuk kesembilan yang diwajibkan spesifikasi
- semua kolom metadata ada di migration dan ditulis service
- checksum dihitung sebelum berkas dikirim
- jalur pembatalan menghapus objek terunggah; berkas lama dihapus setelah
  metadata baru tersimpan
- jenis tunggal memakai `updateOrCreate`, galeri membuat baris baru
- validasi per jenis: `mimetypes` (memeriksa isi), `extensions`, batas ukuran,
  provider aktif + lolos test, galeri maks 20 vs jenis lain maks 1
- Auto dan Manual keduanya berjalan; daftar Manual disaring dengan syarat yang
  sama dengan validasinya
- enam peristiwa log: started, success, replaced, deleted, orphan, failed

**Semua verifikasi ini statis.** Yang hanya bisa dibuktikan di browser ada di
bagian pengujian.

---

## Siap dipakai sprint berikutnya

- **Website** — `$drama->assets` atau `DramaAssetService::grouped()`;
  `public_url` siap dipasang di `<img src>`
- **Admin Panel** — halaman Asset Manager sudah ada
- **API** — panggil `DramaAssetService`; `DramaAssetController::serialize()`
  bisa dijadikan contoh bentuk respons
- **Telegram** — `upload()` menerima `UploadedFile`; berkas dari Telegram perlu
  dibungkus jadi `UploadedFile` lebih dulu

---

## Belum dikerjakan (sengaja)

- Telegram, `telegram_file_id`, Queue, Retry
- Streaming, Video Player, AI Compression, CDN Optimization
- Pengurutan ulang galeri (kolom `sort_order` sudah ada dan terisi, tapi belum
  ada UI seret-lepas untuknya)
- Pemindahan `Admin\MediaService` ke engine — kolom `dramas.poster` dan
  `dramas.cover` lama masih berdampingan dengan tabel baru ini
- Verifikasi checksum terhadap berkas di bucket
