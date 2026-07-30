# Sprint 7.2C — Edit & Delete

Selesai: 30 Juli 2026

Ubah dan hapus storage provider. Soft delete dibuat tersedia, beserta
pemulihannya. Enable, Disable, Set Default, ubah prioritas, dan Test Connection
tetap belum ada.

---

## Langkah setelah deploy

`deploy.sh` sudah menjalankan `php artisan migrate`, jadi tidak ada langkah
manual. Migration barunya menambah kolom `deleted_at` dan memindahkan jaminan
keunikan slug.

---

## Berkas

**Dibuat**

- `database/migrations/2026_07_30_140000_add_soft_deletes_to_storage_providers_table.php`

**Disunting**

- `app/Models/StorageProvider.php` — trait `SoftDeletes`
- `app/Http/Controllers/Admin/StorageController.php` — `update()`, `destroy()`,
  `restore()`, perbaikan `rules()` untuk mode edit
- `routes/web.php` — `edit`, `update`, `destroy`, `restore`
- `resources/views/web/layouts/admin.blade.php` — toast `session('error')`
- `resources/views/web/pages/admin/crud/storage-form.blade.php` — penyesuaian
  mode edit

Tidak ada view baru. Tombol Ubah, Hapus, kotak centang "Terhapus", dan tombol
Pulihkan semuanya muncul sendiri di `crud/index.blade.php` begitu route-nya ada
dan model memakai soft delete.

---

## Keputusan desain

### Soft delete: "jika tersedia" dibuat tersedia

Sebelum sprint ini soft delete belum ada di `storage_providers`. Saya
menambahkannya, bukan melewatinya, karena bobot penghapusan di tabel ini
berbeda dari tabel lain.

Baris storage provider memuat kredensial dan pemetaan ke bucket tempat berkas
sungguhan berada. Menghapusnya **tidak** menghapus berkas di bucket — yang
hilang justru satu-satunya jalan aplikasi menjangkau berkas itu. Tanpa soft
delete, satu klik keliru berarti menggali ulang kunci dari dashboard provider
dan menebak-nebak konfigurasi lama.

`AdminCrudController` sudah mendukung soft delete sepenuhnya lewat
`class_uses_recursive`, jadi menambahkan trait langsung menyalakan filter
"Terhapus", tombol Pulihkan, dan `withTrashed()` pada pencarian record — tanpa
satu baris pun ditulis untuk itu.

Seluruh query di `StorageProviderRepository` memakai query biasa, sehingga
global scope soft delete mengecualikan baris terhapus dengan sendirinya.
Konsekuensi yang penting: **StorageManager tidak akan pernah memilih provider
yang sudah dihapus** sebagai tujuan penyimpanan.

### Unique slug dipindah ke gabungan `(slug, deleted_at)`

Unique tunggal pada `slug` akan menghalangi alur yang justru paling wajar:
hapus provider `r2` yang salah konfigurasi, lalu buat ulang dengan slug yang
sama. Barisnya masih ada di tabel, hanya ditandai terhapus, jadi database akan
menolak.

MySQL menganggap NULL berbeda satu sama lain pada index unique. Dengan unique
gabungan, banyak baris terhapus boleh memakai slug yang sama, sementara baris
hidup (`deleted_at NULL`) tetap dijamin hanya satu per slug — dan itulah satu-
satunya jaminan yang dibutuhkan, karena `StorageManager` hanya mencari baris
hidup.

Validasinya harus ikut menyesuaikan: `Rule::unique(...)->whereNull('deleted_at')`.
Tanpa klausa itu, form akan menolak slug yang database sendiri izinkan.

### Menimpa `update()`: bukan duplikasi, tapi mencegah kredensial terhapus

`AdminCrudController::update()` memanggil `Model::update()` langsung, yang
berarti melewati `StorageProviderService`. Lewat jalur bawaan, **mengganti nama
provider akan menimpa `access_key` dan `secret_key` dengan string kosong** —
karena form memang mengirim keduanya kosong. Penjagaan "kosong berarti jangan
ubah" ada di `StorageProviderService::prepare()`, jadi `update()` harus lewat
sana.

### Bug yang tertangkap: kredensial jadi wajib diketik ulang saat edit

`rules()` menurunkan field wajib dari `StorageDriver::requiredFields()`. Untuk
R2 itu termasuk `access_key` dan `secret_key`. Tapi form 7.2B sengaja **tidak
pernah** menampilkan kembali secret yang tersimpan.

Akibatnya, mengganti satu salah tulis pada nama provider R2 akan ditolak dengan
"Driver Cloudflare R2 memerlukan secret key" — dan admin harus menggali ulang
kunci dari dashboard Cloudflare. Lebih buruk lagi, penjagaan "kosong berarti
jangan ubah" di service tidak akan pernah bisa tercapai, karena validasi
menolak lebih dulu.

Perbaikannya: pada penyuntingan, kredensial yang **sudah tersimpan** tidak
diwajibkan. Pemeriksaannya memakai `getRawOriginal()`, bukan accessor biasa —
kolom ini memakai cast `encrypted`, sehingga membacanya normal akan mendekripsi
dan bisa melempar `DecryptException` bila APP_KEY sudah diganti. Di sini kita
hanya perlu tahu *apakah* ada isinya, bukan apa isinya, jadi ciphertext mentah
cukup dan tidak bisa gagal.

### `status` dan `is_default` tidak pernah ditulis dari form edit

Keduanya tidak ada di `rules()`, sehingga `validate()` tidak pernah
mengembalikannya dan `update()` tidak pernah menyentuhnya. Ini disengaja:
Enable, Disable, dan Set Default belum dibuat, dan provider yang sedang aktif
tidak boleh diam-diam nonaktif hanya karena namanya disunting.

Form kini menyatakannya di layar. Tanpa keterangan itu, admin wajar menduga
menyimpan form akan mengubah status — dan ragu menyentuhnya.

### Toast baru untuk penolakan yang bukan kesalahan isian

Layout admin hanya punya dua saluran pesan: `session('status')` yang dirender
hijau bercentang, dan ringkasan `$errors` untuk kesalahan isian form.

Penolakan seperti "provider default tidak bisa dihapus" tidak cocok di
keduanya. Menaruhnya di `session('status')` membuat penolakan tampil **seolah
berhasil** — persis kebalikan dari maksudnya. Menaruhnya di `$errors`
menghasilkan "1 isian belum benar. Periksa kembali form di bawah", padahal di
halaman daftar tidak ada form yang dimaksud.

Jadi ditambahkan `session('error')` yang memakai kelas `.toast .toast-error`
yang sudah ada — tanpa CSS baru.

### Pemulihan menjaga bentrok slug

`restore()` bawaan hanya memanggil `$record->restore()`. Setelah provider `r2`
dihapus, slug itu bebas dipakai provider baru; bila itu terjadi, memulihkan
yang lama akan ditolak database dengan galat integritas mentah. Versi di sini
memeriksanya lebih dulu dan menolak dengan sebab yang jelas serta langkah yang
bisa dikerjakan.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       329 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    64 blade, 0 bermasalah
```

Self-audit:

- ketujuh route (`index`, `create`, `store`, `edit`, `update`, `destroy`,
  `restore`) terdefinisi
- delapan route yang belum boleh ada (`bulk`, `enable`, `disable`, `toggle`,
  `default`, `test`, `priority`, `reorder`) terbukti **tidak** ada, dan
  controller tidak punya metodenya
- `update()`, `destroy()`, `store()` semuanya lewat `StorageProviderService`;
  tidak ada penulisan atribut lewat Model
- `update()`, `destroy()`, `restore()` tidak menulis kolom `status` maupun
  `is_default`; `store()` tetap memaksa nonaktif sesuai aturan 7.2B
- form tidak pernah membaca maupun mengisi ulang kredensial
- validasi unique slug mengabaikan baris terhapus
- toast galat dirender sebelum toast validasi

Dua "GAGAL" pada self-audit ternyata cacat skrip audit lagi: `::create(`
tertangkap dari teks docblock, dan `status` dari kunci flash
`->with('status', ...)`. Diulang dengan komentar dibuang lebih dulu dan pola
yang lebih tepat — bersih. Ini pola yang sudah tiga sprint berturut-turut
muncul: pencocokan substring pada kode PHP hampir selalu menghasilkan tuduhan
palsu.

**Semua verifikasi statis.** Yang perlu dicoba langsung setelah deploy:

- tombol Ubah dan Hapus muncul di daftar
- menyunting nama provider **tanpa** mengisi kredensial tetap berhasil, dan
  `php artisan storage:test` sesudahnya masih lolos — ini pembuktian bahwa
  kredensial tidak terhapus
- menghapus provider `local` (yang default) ditolak dengan toast merah
- kotak centang "Terhapus" menampilkan baris terhapus dengan tombol Pulihkan
- `php artisan migrate` menjalankan pemindahan index unique tanpa galat

---

## Belum dikerjakan (sengaja)

- Enable, Disable
- Set Default
- Update Priority
- Test Connection dari panel
- Aksi massal
- Hapus permanen (force delete) — baris terhapus saat ini hanya bisa dipulihkan
  atau dibiarkan
