# Sprint 7.2B — Create Storage Provider

Selesai: 30 Juli 2026

Form tambah storage provider, validasi, simpan, redirect, dan toast.
Edit, Delete, dan Test Connection belum ada — route-nya pun belum didaftarkan.

---

## Berkas yang dibuat

- `resources/views/web/pages/admin/crud/storage-form.blade.php`

## Berkas yang disunting

- `app/Http/Controllers/Admin/StorageController.php` — `formData()`, `rules()`,
  `validationMessages()`, `store()`
- `routes/web.php` — `admin.storage.create` dan `admin.storage.store`
- `app/Providers/AuthServiceProvider.php` — izin `storage.manage`
- `bootstrap/app.php` — `dontFlash` untuk kredensial

Halaman daftar tidak disunting. `crud/index.blade.php` dan
`components/admin/cell.blade.php` tidak disentuh sama sekali.

---

## Satu perubahan yang terlihat di halaman daftar

Tombol **Tambah** kini muncul di halaman daftar. Ini perlu disebut karena
spesifikasi meminta halaman daftar tidak diubah — dan memang berkasnya tidak
diubah satu baris pun.

Tombol itu muncul karena `crud/index.blade.php` memeriksa
`Route::has('admin.storage.create')` sebelum merendernya. Begitu route-nya ada,
tombolnya ada. Tanpa itu form ini tidak punya pintu masuk sama sekali.

Tombol Ubah dan Hapus tetap tidak muncul, dengan mekanisme yang sama:
route-nya belum ada.

---

## Keputusan desain

### Provider baru selalu tersimpan nonaktif, dan status tidak ada di form

Sprint 7.1 menetapkan `INACTIVE` sebagai status awal dengan alasan eksplisit:
provider tidak boleh menerima lalu lintas sebelum Test Connection berhasil.
Test Connection dari panel baru dibuat di sprint berikutnya.

Karena itu kolom Status sengaja **tidak disediakan di form**, dan `store()`
memaksa `StorageStatus::INACTIVE`. Mengizinkan admin langsung menandai aktif
berarti memasukkan tujuan penyimpanan yang belum pernah terbukti bisa
dihubungi ke dalam rantai fallback — dan kegagalannya baru muncul saat ada
berkas sungguhan yang hendak disimpan, jauh dari sebabnya.

`is_default` juga tidak disediakan, dengan alasan yang lebih keras: menjadikan
provider yang belum teruji sebagai default akan menggagalkan setiap upload
berikutnya. `StorageProviderService::store()` tetap menandai default secara
otomatis bila tabel benar-benar kosong — dan itu tidak akan terjadi selama
provider `local` hasil seeder masih ada.

Toast setelah menyimpan menyebutkan hal ini dan memberi perintah ujinya.

### `store()` ditimpa, mendelegasikan ke service

`AdminCrudController::store()` bawaan memanggil `Model::create()` langsung.
Untuk modul ini itu salah: seluruh penjagaan bisnis ada di
`StorageProviderService` — provider pertama otomatis jadi default, normalisasi
driver, path-style yang dipaksa untuk MinIO dan R2, region bawaan, dan
pencatatan aktivitas.

Ini bukan duplikasi logika melainkan mengarahkan ke lapisan yang benar.
`parent::store()` tidak dipanggil sama sekali, supaya aktivitas tidak tercatat
dua kali.

### Field wajib diturunkan dari enum, bukan dipatok

`rules()` membaca `StorageDriver::requiredFields()`. Konsekuensinya benar
secara per-provider:

| Driver | Wajib |
|---|---|
| Penyimpanan Lokal | tidak ada |
| Amazon S3 | bucket, region, access_key, secret_key |
| Cloudflare R2 | bucket, endpoint, access_key, secret_key |
| Backblaze B2, Wasabi, Spaces | bucket, endpoint, region, access_key, secret_key |
| MinIO | bucket, endpoint, access_key, secret_key |
| GCS, Azure | bucket |

R2 tidak meminta region karena selalu `auto`; S3 asli tidak meminta endpoint
karena diturunkan dari region. Satu daftar wajib yang sama untuk sembilan
provider akan menolak konfigurasi yang sebenarnya sah.

Tabel yang sama ditampilkan di bawah form, dibangkitkan dari kode yang sama —
jadi petunjuk di layar tidak bisa berbeda dari yang benar-benar diperiksa
server.

### Driver tanpa adapter ditolak di form

Kalau paket composer adapternya belum terpasang, driver itu ditolak saat
validasi dengan pesan yang menyebut perintah `composer require`-nya. Tanpa ini
provider tersimpan rapi dan terlihat benar di daftar, lalu gagal saat disk
dibangun dengan galat Flysystem yang tidak menyebut sebabnya. Lebih baik
ditolak di tempat orangnya masih memperhatikan.

Kolom "Adapter" di tabel bawah form menunjukkan status ini sebelum admin
sempat salah pilih.

### `endpoint` tidak divalidasi sebagai URL

Endpoint MinIO sering berupa host dan port di jaringan lokal, dan sebagian
operator menuliskannya tanpa skema. Rule `url` akan menolak konfigurasi yang
sah. `public_url` tetap divalidasi sebagai URL, karena itu memang alamat yang
akan dibuka peramban.

---

## Kebocoran yang ditutup: kredensial di session dan HTML

Saat validasi gagal, Laravel mem-flash **seluruh** input ke session agar form
bisa diisi ulang lewat `old()`. Untuk form ini artinya dua hal sekaligus:
secret key tersimpan sebagai teks polos di penyimpanan session, dan dirender
kembali ke dalam atribut `value` pada HTML.

Nilai yang sama disimpan terenkripsi di database. Melindunginya di satu tempat
lalu membocorkannya di tempat lain tidak ada gunanya.

`bootstrap/app.php` sekarang menambahkan `access_key` dan `secret_key` ke
`dontFlash`, berdampingan dengan `password` bawaan Laravel. Field kredensial di
form juga diberi `:value="null"` secara eksplisit, sehingga tidak bergantung
pada satu lapis perlindungan saja.

Konsekuensi yang disengaja: setelah validasi gagal, kolom kunci kembali kosong
dan harus diisi ulang.

---

## Izin

Izin baru `storage.manage` untuk tulis, terpisah dari `storage.view` untuk
baca — mengikuti pola `drama.view` / `drama.manage` yang sudah ada.

Setiap route menerima `setting.manage` sebagai alternatif, karena kedua izin
storage belum ada barisnya di database sampai `RoleSeeder` dijalankan ulang.
Tanpa alternatif itu, halamannya 403 di server yang baru di-deploy.

```
php artisan db:seed --class='Database\Seeders\RoleSeeder' --force
```

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       332 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    64 blade, 0 bermasalah
```

Jumlah kelas CSS tetap 197 — form ini tidak memperkenalkan satu pun kelas baru,
seluruhnya memakai gaya panel yang sudah ada.

Self-audit tambahan:

- `crud/index.blade.php` dan `cell.blade.php` terbukti tidak disunting
- `admin.storage.index`, `.create`, `.store` ada; `.edit`, `.update`,
  `.destroy`, `.restore`, `.bulk` terbukti **tidak** ada
- controller tidak punya `update()`, `destroy()`, maupun `test()`
- kelima field wajib yang mungkin muncul di enum punya aturan dasar di `rules()`
- kredensial: masuk `dontFlash`, `:value="null"` di form, `type="password"`,
  dan tetap tidak ada di kolom daftar
- jalur simpan hanya satu: `$this->service->store($data)`; tidak ada
  `Model::create()` dan tidak ada `parent::store()`

Satu "GAGAL" pada self-audit ternyata cacat skrip audit lagi: pencarian
`::create(` menangkap docblock yang menjelaskan **mengapa** `Model::create()`
tidak dipakai. Diulang dengan komentar dibuang, hasilnya bersih.

**Semua verifikasi ini statis.** Yang harus dilihat langsung setelah deploy:

- tombol Tambah muncul di `/admin/storage`, tombol Ubah dan Hapus tidak
- pilih driver Cloudflare R2 lalu simpan dengan kolom kosong — pesan galat
  harus berbunyi "Driver Cloudflare R2 memerlukan bucket", bukan pesan umum
- simpan yang berhasil memunculkan toast hijau dan kembali ke daftar
- provider baru muncul dengan badge **Nonaktif**
- setelah validasi gagal, kolom Access key dan Secret key kembali kosong
- pilih driver Google Cloud Storage — harus ditolak dengan pesan
  `composer require league/flysystem-google-cloud-storage`

---

## Belum dikerjakan (sengaja)

- Edit dan Delete provider
- Enable, Disable, Set Default, Update Priority
- Test Connection dari panel
- Kolom hasil test terakhir di tabel daftar
- Upload, Telegram, thumbnail, subtitle
