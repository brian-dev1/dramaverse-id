# Sprint 7.2D — Enable, Disable, Set Default & Update Priority

Selesai: 30 Juli 2026

Empat aksi baru di Storage Manager. Test Connection dari panel belum dibuat.

---

## Berkas yang disunting

- `app/Services/StorageProviderService.php` — penjagaan invarian dipindah ke
  dalam kunci; `reorder()` mengembalikan jumlah yang berubah
- `app/Repositories/StorageProviderRepository.php` — `lockAll()`,
  `changedPriorities()`
- `app/Repositories/Contracts/StorageProviderRepositoryInterface.php`
- `app/Http/Controllers/Admin/StorageController.php` — `enable()`, `disable()`,
  `makeDefault()`, `updatePriority()`
- `routes/web.php` — empat route baru
- `resources/views/web/pages/admin/crud/index.blade.php` — tombol dan editor
  prioritas

Tidak ada migration, tidak ada view baru, tidak ada CSS baru.

---

## Invarian: TEPAT SATU default

Ini bagian yang paling banyak menyita perhatian, karena permintaannya mudah
ditulis tapi tidak mudah dipenuhi.

### Transaction sendirian TIDAK cukup

Rancangan awal (Sprint 7.1) sudah memakai `DB::transaction` di `makeDefault()`:
bersihkan tanda default dari semua baris, lalu pasang pada baris ini. Itu
melindungi dari kegagalan separuh jalan, tapi **tidak** melindungi dari dua
permintaan yang berjalan bersamaan.

Urutannya begini. Dua admin menekan Set Default hampir serentak, A ke provider
`r2` dan B ke provider `wasabi`:

1. Transaksi A membersihkan tanda default. Belum commit.
2. Transaksi B membersihkan tanda default — **tidak melihat** perubahan A yang
   belum commit.
3. A memasang `is_default` pada `r2`, commit.
4. B memasang `is_default` pada `wasabi`, commit.

Hasilnya dua baris bertanda default. Transaction menjaga keutuhan tiap
transaksi, bukan urutan antar-transaksi.

### Solusinya: kunci seluruh baris lebih dulu

`lockAll()` menjalankan `SELECT ... FOR UPDATE` atas seluruh baris sebelum
apa pun diubah. Transaksi B menunggu sampai A selesai, lalu melihat keadaan
A yang sudah commit dan membersihkannya dengan benar.

Yang dikunci sengaja **semua baris**, bukan hanya yang sedang bertanda default.
Kalau yang dikunci hanya baris ber-`is_default = true`, maka pada tabel yang
belum punya default sama sekali tidak ada baris yang cocok — tidak ada yang
terkunci, dan kedua permintaan lolos bersamaan. Tabel ini kecil (jumlah
provider dihitung dengan jari), jadi mengunci semuanya tidak berbiaya berarti.

`withTrashed()` disertakan supaya baris terhapus ikut terkunci: baris terhapus
masih bisa dipulihkan, dan pemulihan tidak boleh berbarengan dengan pemindahan
default.

### Penjagaan dipindahkan ke dalam kunci

Ini yang paling mudah terlewat. `disable()`, `delete()`, dan `makeDefault()`
memeriksa keadaan sebelum bertindak — dan ketiganya semula memeriksa
**sebelum** transaksi dimulai. Pemeriksaan di luar kunci berarti hasilnya bisa
kedaluwarsa sebelum dipakai:

| Yang berjalan bersamaan | Akibat sebelum diperbaiki |
|---|---|
| `disable(B)` + `makeDefault(B)` | B jadi default **dan** nonaktif |
| `delete(B)` + `makeDefault(B)` | B jadi default **dan** terhapus |
| `makeDefault(B)` + `disable(B)` | default menunjuk provider nonaktif |

Ketiganya melanggar syarat turunan dari invarian: provider default harus aktif
dan hidup. Kalau dilanggar, setiap unggahan berikutnya gagal — dengan gejala
yang muncul jauh dari sebabnya.

Sekarang urutannya seragam di ketiga operasi:

```php
DB::transaction(function () use ($provider) {
    $this->repository->lockAll();   // 1. kunci dulu
    $provider->refresh();           // 2. baca ulang nilai yang sudah pasti
    if (...) throw ...;             // 3. baru periksa
    // 4. baru ubah
});
```

`refresh()` diperlukan karena objek `$provider` diisi sebelum kunci didapat.
Mengunci baris lalu memutuskan berdasarkan nilai lama tidak ada gunanya.

### Yang TIDAK dikunci, dan alasannya

`enable()` hanya menulis `status`. Mengaktifkan provider nonaktif tidak bisa
menghasilkan dua default, dan default yang ada sudah pasti aktif. Menambah
kunci di sana hanya memperlambat tanpa menjaga apa pun.

`reorder()` memakai transaction tapi tanpa `lockAll()`: prioritas tidak
bersinggungan dengan invarian default sama sekali.

### Catatan jujur: ini penjagaan di lapisan aplikasi

Invarian ini dijaga oleh kode, bukan oleh database. Selama semua penulisan
lewat `StorageProviderService`, jaminannya berlaku. Kode yang menulis
`is_default` langsung ke model akan melewatinya.

Jaminan tingkat database bisa ditambahkan lewat kolom generated plus unique
index (MySQL tidak mendukung partial index):

```sql
ALTER TABLE storage_providers
  ADD COLUMN default_lock TINYINT
    GENERATED ALWAYS AS (IF(is_default = 1 AND deleted_at IS NULL, 1, NULL)) VIRTUAL,
  ADD UNIQUE KEY uniq_single_default (default_lock);
```

Saya **tidak** menambahkannya di sprint ini. Alasannya bukan karena
tidak berguna — justru itu satu-satunya cara membuat invarian ini mustahil
dilanggar — melainkan karena saya tidak punya PHP maupun MySQL untuk
mengujinya. Sintaks kolom generated berbeda antar versi MySQL, dan migration
yang gagal akan menghentikan `deploy.sh` di langkah migrate. Kalau Anda mau,
ini kandidat pertama untuk sprint berikutnya, dijalankan di server yang bisa
langsung diuji.

---

## Update Priority

Satu formulir mengirim prioritas seluruh baris yang tampil, bukan satu
permintaan per baris.

Transaction diperlukan karena ini banyak `UPDATE` terpisah. Kalau separuhnya
masuk lalu sisanya gagal, urutan yang tersimpan bukan urutan lama maupun
urutan baru, melainkan campuran yang tidak pernah diminta siapa pun — dan
tidak ada yang tahu bahwa itu yang terjadi.

`changedPriorities()` menyaring dulu, menyisakan yang nilainya benar-benar
berbeda. Dua manfaatnya: tidak ada `UPDATE` yang sia-sia, dan menyimpan
formulir tanpa mengubah apa pun tidak meninggalkan catatan aktivitas yang
berbunyi seolah ada perubahan. Id yang tidak ada di database dibuang tanpa
suara — itu bisa terjadi wajar: admin membuka daftar, provider dihapus di tab
lain, lalu formulir lama tetap dikirim.

Batas 0..65535 sepakat di tiga tempat: atribut `min`/`max` di input, rule
`min:0|max:65535` di controller, dan `unsignedSmallInteger` di migration. Tanpa
batas atas di validasi, MySQL dalam mode non-strict memotong nilainya diam-diam
menjadi 65535 — urutan yang tersimpan berbeda dari yang diminta tanpa ada yang
tahu.

---

## Tombol: digerakkan `Route::has()`, bukan nama modul

`crud/index.blade.php` adalah view bersama sembilan modul. Tombol baru
diputuskan lewat `Route::has('admin.'.$routeKey.'.enable')` dan seterusnya —
bukan `$routeKey === 'storage'`. Modul lain yang nanti mendaftarkan route
bernama sama langsung mendapat tombolnya tanpa view ini disunting lagi.

Keadaan baris dibaca secara aman (`method_exists($record, 'isActive')`), jadi
modul yang tidak punya konsep aktif/default dirender persis seperti sebelumnya.

Dua tombol sengaja tidak dirender meski route-nya ada:

- **Set Default** tidak muncul pada baris nonaktif dan pada baris yang sudah
  default.
- **Disable** tidak muncul pada baris default. Provider default wajib aktif,
  jadi tombolnya pasti ditolak. Jalan keluarnya memang memindahkan status
  default ke provider lain lebih dulu.

Service tetap menolak keduanya secara mandiri. Menyembunyikan tombol adalah
kesopanan antarmuka, bukan penjagaannya.

---

## Editor prioritas memakai atribut `form=`, bukan membungkus tabel

Input prioritas berada di dalam sel tabel, tetapi formulirnya berdiri di luar
tabel. Keduanya dihubungkan atribut `form="priority-form"` (HTML5).

Alasannya: **form tidak boleh bersarang.** Kalau formulir prioritas dibuat
melingkupi tabel, ia akan membungkus form tombol Hapus dan Pulihkan yang ada di
dalam baris. Parser HTML membuang tag `<form>` yang bersarang, sehingga tombol
Hapus justru akan mengirim formulir prioritas.

---

## Temuan terpisah: form bersarang di tujuh modul lain (BELUM diperbaiki)

Saat memastikan hal di atas, saya menemukan masalah yang sudah ada sebelum
sprint ini dan **tidak** saya sentuh.

Ketika sebuah modul punya aksi massal, `crud/index.blade.php` membuka
`<form data-bulk-form>` **sebelum** `<table>` dan menutupnya sesudahnya. Form
tombol Hapus (`x-admin.confirm`) dan Pulihkan berada di dalam baris tabel —
jadi di dalam form bulk itu.

Diverifikasi dari urutan posisinya di berkas: form bulk dibuka di offset 4175,
tabel di 6377, form baris pertama di 10565, form bulk ditutup di 15854.

Menurut aturan parsing HTML5, tag `<form>` yang muncul saat masih ada form
terbuka **diabaikan**. Akibatnya `@csrf`, `@method('DELETE')`, dan tombolnya
menjadi bagian dari form bulk — tombol Hapus mengirim permintaan ke
`admin.<modul>.bulk`, bukan ke `admin.<modul>.destroy`.

Modul terdampak: `drama`, `episode`, `genre`, `country`, `banner`,
`membership`, `subscription`, `user`.
Tidak terdampak (aksi massalnya kosong): `storage`, `logs`, `role`.

Perbaikannya persis teknik yang sudah saya pakai untuk editor prioritas: beri
form bulk sebuah `id`, tutup segera tanpa melingkupi tabel, lalu tambahkan
`form="bulk-form"` pada kotak centang tiap baris. Perubahannya kecil dan
mekanis.

Saya belum mengerjakannya karena di luar lingkup 7.2D, menyentuh jalur aksi
destruktif di tujuh modul sekaligus, dan tidak bisa saya uji di browser. Kalau
mau dikerjakan, sebaiknya jadi sprint sendiri yang kecil dan diuji langsung.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       329 berkas, 0 bermasalah
python tools/check-css-coverage.py        197 kelas, semua punya aturan
python tools/check-blade-directives.py    64 blade, 0 bermasalah
```

Self-audit invarian, dijalankan dengan pencocokan kurung atas badan tiap
metode:

- `store()`, `update()`, `makeDefault()` — menulis `is_default`: transaction
  dan `lockAll()` keduanya ada
- `disable()`, `delete()` — membaca `is_default`: transaction dan `lockAll()`
  keduanya ada
- ketiga operasi berpenjagaan: `lockAll()` mendahului `throw`, dan `refresh()`
  mendahului pemeriksaan
- `enable()`, `reorder()` — tidak menyentuh invarian, sengaja tanpa kunci
- batas priority sepakat di input, controller, dan migration
- keempat route baru terdefinisi; `bulk` dan Test Connection terbukti masih
  tidak ada
- view bersama tidak menghardcode `$routeKey === 'storage'`

Seperti sprint sebelumnya, dua percobaan pertama self-audit saya melaporkan
GAGAL palsu — sekali karena `is_default` yang dibaca tidak dibedakan dari yang
ditulis, sekali karena escape `\$` di dalam skrip. Diulang dengan deteksi tulis
yang benar dan pencocokan kurung, hasilnya bersih.

**Semua verifikasi ini statis.** Yang hanya bisa dibuktikan di browser:

- tombol Aktifkan muncul pada provider nonaktif, dan berhasil
- tombol Nonaktifkan tidak muncul pada provider default
- tombol bintang memindahkan default, dan kolom Default hanya berisi satu "Ya"
- mengubah beberapa angka prioritas lalu menekan Simpan menyimpan semuanya
- menekan Simpan tanpa mengubah apa pun berbunyi "Tidak ada prioritas yang
  berubah"
- tombol Hapus di halaman ini tetap berfungsi (storage tidak terdampak temuan
  form bersarang di atas)
