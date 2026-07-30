# Sprint 7.4 — Storage Engine (Core Upload)

Selesai: 30 Juli 2026

Pusat seluruh operasi berkas DramaVerse ID. Belum ada Upload Episode,
Thumbnail, Subtitle, Telegram, Queue, Scheduler, Retry, maupun Statistics.
Pondasi Sprint 7.1–7.3 tidak diubah.

---

## Cara memakainya

```php
use App\Enums\StorageCollection;
use App\Services\Storage\Contracts\StorageEngineInterface;

public function __construct(
    protected StorageEngineInterface $storage
) {}

// Auto — provider default
$file = $this->storage->upload($request->file('poster'), StorageCollection::POSTER);

// Manual — provider tertentu (id atau slug), harus aktif
$file = $this->storage->upload($request->file('video'), StorageCollection::EPISODE, 'r2');

// Simpan KEDUANYA. object_key saja tidak cukup.
$episode->update([
    'storage_provider_id' => $file->providerId,
    'object_key'          => $file->objectKey,
]);

// Operasi berikutnya selalu menyebut provider secara eksplisit
$url = $this->storage->temporaryUrl($episode->storage_provider_id, $episode->object_key, 120);
$this->storage->delete($episode->storage_provider_id, $episode->object_key);
```

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/StorageCollection.php` | Skema alamat: 8 koleksi, masing-masing dengan direktori, ekstensi, batas ukuran, visibility |
| `app/Services/Storage/Contracts/StorageEngineInterface.php` | Kontrak yang dipakai modul lain |
| `app/Services/Storage/StorageEngine.php` | Implementasi |
| `app/Services/Storage/StoredFile.php` | Objek hasil, 11 field + visibility |
| `app/Services/Storage/FileMetadata.php` | Hasil Get Metadata |
| `app/Services/Storage/ObjectKey.php` | Pembangun dan pembersih object key |
| `app/Services/Storage/Exceptions/StorageEngineException.php` | Kegagalan operasi berkas |
| `app/Console/Commands/StorageSmoke.php` | `php artisan storage:smoke` |

## Berkas yang disunting

- `config/storage.php` — blok `engine`
- `app/Providers/AppServiceProvider.php` — binding singleton
- `.env.example` — tiga variabel engine

Tidak ada migration, tidak ada view, tidak ada CSS, tidak ada route.

---

## Keputusan desain

### Provider eksplisit pada setiap operasi berkas yang sudah ada

Hanya `upload`, `uploadTo`, dan `putContents` punya mode Auto. `delete`,
`rename`, `move`, `copy`, `metadata`, `url`, dan `temporaryUrl` **mewajibkan**
provider disebutkan.

Ini pembatasan yang disengaja, dan bagian terpenting dari rancangan ini.
Berkas berada di satu provider tertentu. Mencarinya di provider default hanya
benar selama default belum pernah dipindah — dan begitu dipindah, berkas yang
diunggah kemarin ke R2 dicari di Wasabi: key-nya benar, bucketnya salah, dan
gejalanya "berkas hilang" tanpa jejak.

Karena itu modul yang memakai engine ini **wajib menyimpan `provider_id`
bersama `object_key`**. Kalau hanya key yang disimpan, kerugiannya baru
terasa berbulan-bulan kemudian, saat sudah ada ribuan berkas.

### Nama berkas dari peramban dibuang seluruhnya

Nama tersimpan selalu ULID + ekstensi yang sudah dibersihkan. Nama asli
disimpan terpisah di `StoredFile::$originalName` untuk ditampilkan.

Nama dari peramban bisa memuat path, karakter yang membuat URL harus di-encode,
dan ekstensi ganda. Memakainya sebagai object key hanya memindahkan masalahnya
ke lain hari.

### Replace menulis key baru, bukan menimpa

Bawaannya: tulis object key baru, lalu hapus yang lama. Menimpa key yang sama
membuat CDN dan peramban tetap menyajikan berkas lama — gejala klasik "sudah
saya ganti tapi yang muncul masih poster lama". Untuk menimpa di tempat, kirim
`['keep_key' => true]`.

Kalau penghapusan berkas lama gagal, penggantian **tidak** dibatalkan. Berkas
baru sudah tersimpan; menggagalkan seluruh operasi karena sisa berkas lama
justru meninggalkan keadaan yang lebih buruk. Yang tertinggal dicatat sebagai
`storage.delete.orphan` di log.

### Delete idempoten

Menghapus berkas yang sudah tidak ada mengembalikan `false`, bukan melempar
exception. Kode pembersih tidak boleh gagal hanya karena pekerjaannya sudah
dilakukan orang lain.

### Berkas privat tidak diberi URL publik

`StoredFile::$url` selalu `null` untuk berkas privat, walaupun providernya
sanggup menyusun URL-nya. Menyertakan URL permanen untuk video berbayar di
objek hasil adalah cara paling mudah agar URL itu akhirnya bocor ke HTML.
Untuk berkas privat, pakai `temporaryUrl()`.

Video episode dan subtitle karena itu ditandai `private` di
`StorageCollection`, sedangkan poster, cover, thumbnail, banner, dan avatar
`public`.

### Satu jalur untuk rename, move, dan copy

Ketiganya hanya berbeda pada cara menghitung key tujuan dan apakah sumber ikut
dihapus, jadi ketiganya memanggil satu `relocate()`. Menulis tiga method yang
isinya sama berarti tiga tempat yang harus ingat memeriksa keberadaan sumber,
menolak menimpa tujuan, dan menangkap kegagalan.

### Metadata dibaca satu per satu, bukan sekaligus

Setiap pembacaan (`size`, `mimeType`, `lastModified`, `visibility`) dibungkus
sendiri. Provider yang tidak melaporkan salah satunya tidak boleh membuat
seluruh metadata gagal — R2 dan B2 tidak mengenal ACL objek gaya S3, jadi
`visibility` di sana memang tidak tersedia. Field yang tidak dilaporkan
bernilai `null`, bukan tebakan yang terlihat seperti fakta.

---

## Soal "Koneksi valid"

Spesifikasi meminta validasi koneksi. Yang saya lakukan bukan menghubungi
penyimpanan sebelum setiap operasi, dan itu perlu dijelaskan.

Menghubungi penyimpanan dua kali untuk satu unggahan menggandakan waktu
tunggu, dan tetap tidak menjamin apa pun: provider bisa rusak di antara
pemeriksaan dan operasinya. Yang menentukan adalah operasinya sendiri, yang
kegagalannya ditangkap, dicatat sebagai `storage.upload.failed`, dan
disampaikan sebagai exception dengan pesan asli dari SDK.

Yang diperiksa sebelum operasi adalah empat hal yang **tidak** perlu jaringan
dan menangkap sebab tersering:

1. **Provider aktif** — `isActive()`
2. **Driver tersedia** — `hasAdapterInstalled()`, memastikan paket composer
   adapternya benar-benar ada di `vendor/`
3. **Bucket tersedia** — lewat `isConfigured()`, karena `bucket` ada di
   `requiredFields()` setiap driver awan. Provider lokal tidak memerlukan
   bucket, dan memaksanya justru salah
4. **Nilai contoh sudah diganti** — penjagaan dari 7.3

Bila Anda tetap ingin menolak provider yang belum pernah lulus Test Connection,
nyalakan `STORAGE_REQUIRE_VERIFIED=true`. Bawaannya **mati**, karena
menyalakannya di pemasangan baru langsung mengunci semua unggahan: seeder
memasang provider lokal sebagai aktif dan default, sementara kolom hasil
test-nya masih kosong sampai seseorang menjalankan Test Connection.

---

## Lubang keamanan yang ditemukan self-audit

Saya memport logika `ObjectKey` ke Python dan mengujinya dengan 15 masukan
bermusuhan. Semua vektor traversal ditolak: `../../etc`, `a/../../b`,
`/etc/passwd`, `C:\Windows`, byte nol, `....//..`, `..%2f..`, `a/.hidden`,
`~/root`, dan sisanya.

Tapi pengujian nama berkas memunculkan satu hal yang saya lewatkan saat
menulis:

```
shell.jpg.php  ->  shell-jpg.php
```

Ekstensinya benar secara teknis — `.php` memang ekstensi terakhir. Masalahnya
ada di tempat berkasnya mendarat. Provider lokal menyimpan di
`storage/app/public`, yang tersaji ke publik lewat symlink `public/storage`,
dan banyak konfigurasi Nginx meneruskan apa pun berakhiran `.php` ke PHP-FPM
termasuk yang ada di bawah `/storage`. Berkas itu bisa dieksekusi.

Koleksi memang membatasi ekstensi (gambar untuk poster, video untuk episode),
tetapi `uploadTo()` dan koleksi `ASSET` sengaja tidak membatasi — dan itulah
jalur yang akan dipakai modul "aset lain" nanti.

Perbaikannya: daftar tolak yang berlaku pada **semua** jalur tulis, diperiksa
pada object key yang sudah final (bukan pada nama yang dikirim peramban).
Isinya di `config/storage.php` pada `engine.blocked_extensions` — php dan
variannya, phtml, phar, cgi, pl, py, rb, sh, exe, dll, jsp, asp, htaccess,
env, dan sejenisnya.

Daftar ini bukan pengulangan pembatasan per koleksi. Koleksi membatasi apa yang
**wajar**; daftar ini menolak apa yang **berbahaya**.

Diuji: `shell-jpg.php`, `a.PHP`, `a.phtml`, `a.phar`, `x.htaccess`, `x.env`
ditolak; `poster-php.jpg`, `video.mp4`, `sub.vtt`, `data.json`, dan berkas
tanpa ekstensi diterima.

---

## Cara membuktikan engine ini bekerja

```
php artisan storage:smoke              # mode Auto (provider default)
php artisan storage:smoke local        # provider tertentu
php artisan storage:smoke 2 --keep     # jangan hapus berkas ujinya
```

Menjalankan satu siklus penuh lewat `StorageEngineInterface`: putContents,
exists, metadata, url, temporaryUrl, copy, rename, move, delete, plus dua
penjagaan keamanan (`../../etc` dan `shell.jpg.php` harus ditolak). Berkas uji
beberapa ratus byte di direktori `_smoke`, dihapus di akhir termasuk saat ada
langkah yang gagal.

Ini alat verifikasi, bukan fitur. Tanpa perintah ini, Storage Engine tidak bisa
dibuktikan bekerja sampai modul upload dibuat di sprint berikutnya — dan kalau
ada yang salah di engine, kesalahannya baru ketahuan bercampur dengan
kesalahan modul barunya.

---

## Temuan: `Admin\MediaService` masih melewati multi-storage

Spesifikasi meminta tidak ada upload langsung dari controller ke storage.
Empat controller admin sudah memanggil service, bukan Storage — jadi secara
bentuk sudah benar. Tapi service yang dipanggilnya melewati multi-storage
sepenuhnya:

`app/Services/Admin/MediaService.php` menulis dengan
`$file->storeAs($folder, $name, 'public')` dan menghapus dengan
`Storage::disk('public')->delete()`. Disk `public` dipatok di kode, jadi
poster, cover, thumbnail episode, banner, dan logo situs **tidak pernah sampai
ke provider awan** meskipun R2 sudah aktif dan default.

Pemanggilnya: `BannerController`, `DramaController`, `EpisodeController`,
`SettingController`.

Saya tidak menyentuhnya, karena spesifikasi 7.4 melarang membuat Upload
Thumbnail dan sejenisnya. Direktori di `StorageCollection` sudah sengaja
disamakan dengan peta folder `MediaService` (`drama/poster`, `drama/cover`,
`episode/thumbnail`, `banner`), jadi pemindahannya nanti tidak mengubah letak
berkas yang sudah ada.

Satu hal yang perlu diputuskan saat memindahkannya: `MediaService` memperkecil
gambar di tempat lewat `ImageProcessor::optimise()`, yang memerlukan **path
absolut di disk lokal**. Itu tidak mungkin untuk berkas yang langsung ditulis
ke bucket awan. Urutannya harus dibalik — perkecil dulu di berkas sementara,
baru unggah hasilnya — dan itu perubahan yang cukup berarti untuk jadi
sprintnya sendiri.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       338 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    64 blade, 0 bermasalah
```

Self-audit tambahan:

- engine tidak pernah memanggil `Storage::`; facade-nya bahkan tidak diimpor.
  Satu-satunya jalan ke disk adalah `StorageManager::build()`, lewat satu
  helper `disk()` yang dipakai 9 kali
- 11 field wajib ada di `StoredFile`, dan `toArray()` memuat 11 kunci
  snake_case yang siap dipakai `fill()`
- `logContext()` dan `context()` tidak memuat `access_key` maupun
  `secret_key`; model provider tidak pernah di-array-kan utuh ke log
- 13 method ada di interface DAN implementasi, tanda tangannya cocok
- empat validasi spesifikasi ada di `assertReady()`
- upload dan delete dicatat, sukses maupun gagal, lewat `Log::channel`
- 15 vektor path traversal ditolak, 9 path sah diterima
- 11 kasus daftar tolak ekstensi sesuai harapan

Satu "GAGAL" pada self-audit ternyata cacat skrip saya: pemeriksaan
`Storage::` juga membaca komentar, dan yang tertangkap adalah docblock yang
menjelaskan bahwa engine **tidak** memanggilnya. Diulang setelah komentar
dibuang, hasilnya bersih.

**Semua verifikasi ini statis.** Yang hanya bisa dibuktikan di server:
`php artisan storage:smoke`. Jalankan setelah deploy, kirim keluarannya apa
adanya.

---

## Belum dikerjakan (sengaja)

- Upload Episode, Thumbnail, Subtitle — modul yang memakai engine ini
- Pemindahan `Admin\MediaService` ke engine
- Load balancing dan failover. `StorageManager::chain()` sudah menyiapkan
  urutannya, tetapi engine selalu memakai satu provider dan gagal
  terang-terangan bila provider itu bermasalah — bukan diam-diam berpindah,
  yang akan menyebarkan berkas satu modul ke beberapa bucket tanpa ada yang
  memutuskan begitu
- Penyalinan antar provider (migrasi berkas)
- Queue, Scheduler, Retry, Storage Statistics
- Telegram
