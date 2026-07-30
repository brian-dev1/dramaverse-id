# Sprint 7.5 — Episode Video Upload

Selesai: 30 Juli 2026

Unggah video episode lewat Storage Engine. Belum ada Telegram, Queue, Retry,
Subtitle, Thumbnail, Poster, Episode Publish, Streaming, dan Video Player.
Foundation Multi Storage dan Storage Engine **tidak diubah**.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `database/migrations/2026_07_30_200000_create_episode_videos_table.php` | Tabel metadata |
| `app/Models/EpisodeVideo.php` | Model |
| `app/Rules/UsableStorageProvider.php` | Rule: aktif + lengkap + lolos Test Connection |
| `app/Services/EpisodeVideoService.php` | Aturan bisnis unggah |
| `app/Http/Requests/Admin/StoreEpisodeVideoRequest.php` | Validasi |
| `app/Http/Controllers/Admin/EpisodeVideoController.php` | Halaman + endpoint |
| `resources/views/web/pages/admin/episode-video.blade.php` | Halaman unggah |

## Berkas yang disunting

- `app/Models/Episode.php` — relasi `video()`
- `routes/web.php` — tiga route baru
- `resources/js/admin.js` — modul `videoUpload()`
- `resources/css/web/admin/admin.css` — 8 kelas baru
- `resources/views/web/pages/admin/crud/index.blade.php` — tombol "Unggah video"
- `resources/views/components/admin/field.blade.php` — perbaikan bug, lihat bawah
- `tools/verify-consistency.py` — `EpisodeVideo` masuk pemeriksaan fillable

---

## Alur lengkap

```
Halaman  →  Controller  →  EpisodeVideoService  →  StorageEngineInterface
                                                          ↓
                                              StorageManager → provider
```

Controller **tidak** menyentuh Storage, tidak tahu driver apa pun, dan tidak
pernah menyebut nama disk. Yang dilakukannya hanya tiga hal: validasi lewat
FormRequest, panggil service, terjemahkan hasilnya jadi JSON.

Service **tidak** menyentuh Storage juga. Satu-satunya jalan ke penyimpanan
adalah `StorageEngineInterface`. Diverifikasi: nol kemunculan `Storage::`,
`->store()`, `->storeAs()`, `disk(`, `move_uploaded_file`, dan
`file_put_contents` di kedua berkas (setelah komentar dibuang).

---

## Keputusan desain

### Checksum dihitung SEBELUM upload

`hash_file('sha256', ...)` dijalankan pada berkas sementara, sebelum apa pun
dikirim. `hash_file` membaca bertahap, jadi berkas gigabyte tidak masuk memori
sekaligus.

Urutan ini penting karena dua hal. Setelah engine memindahkan berkasnya, berkas
sementara sudah tidak ada di tempatnya. Dan membaca ulang dari penyimpanan
untuk menghitung checksum berarti mengunduh kembali berkas gigabyte —
sekaligus mengukur hal yang salah: yang ingin dicatat adalah apa yang dimaksud
pengunggah, bukan apa yang kebetulan ada di bucket sesudahnya.

### Tidak ada data setengah jadi

Ada tiga titik gagal, dan masing-masing punya jalan keluarnya:

| Gagal di | Yang terjadi |
|---|---|
| Validasi / engine menolak | Belum ada apa pun yang ditulis. Pesan ditampilkan. |
| Penyimpanan metadata | Objek yang **sudah** terunggah **dihapus**. Tanpa ini, berkas gigabyte duduk di bucket tanpa satu baris pun yang mengenalinya — biaya penyimpanan yang berjalan terus untuk berkas yang tidak akan pernah ditemukan. |
| Penghapusan video lama | Penggantian **tidak** dibatalkan. Video baru sudah tersimpan dan tersambung; menggagalkan seluruh operasi karena sisa berkas lama justru meninggalkan keadaan lebih buruk. Yang tertinggal dicatat sebagai `orphan`. |

Urutannya: metadata baru tersimpan **dulu**, video lama dihapus **sesudahnya**.
Urutan sebaliknya akan meninggalkan episode tanpa video sama sekali bila
unggahan yang baru gagal di tengah jalan.

### Satu video per episode

`episode_id` unik di database. Mengunggah lagi **mengganti** barisnya, bukan
menambah baris kedua — kalau tidak, tidak ada yang bisa menjawab "video mana
yang sedang dipakai episode ini". Form memberi tahu lebih dulu bila episode
yang dipilih sudah punya video.

### `storage_provider_id` pakai `restrictOnDelete`

Bukan `cascade`, bukan `nullOnDelete`. Provider memakai soft delete jadi FK-nya
tetap sah; tapi kalau suatu hari ada penghapusan permanen, penghapusan itu
harus **ditolak** selama masih ada video yang menunjuknya. Mengosongkan kolom
ini berarti kehilangan satu-satunya petunjuk di bucket mana berkasnya berada —
key-nya masih tersimpan, tapi tidak ada lagi yang tahu ke mana harus mencari.

### Nama berkas

Pola: `slugdrama_episode_07_a1b2c3d4e5f6.mp4`

Nama asli dari peramban tidak dipakai sebagai nama tersimpan, tapi tetap
disimpan di kolom `original_filename`. Selain rawan, nama asli video biasanya
tidak berguna — "video final REVISI 3.mp4" tidak membantu siapa pun yang
membuka bucket dan mencoba menebak isinya.

Bagian acak di ujung diperlukan: tanpa itu, mengunggah ulang episode yang sama
menghasilkan key yang sama, dan CDN akan tetap menyajikan berkas lama.

### Batas ukuran: yang TERKECIL antara aplikasi dan php.ini

`StorageCollection::EPISODE->maxKb()` adalah 8 GB, tapi yang berlaku adalah
yang terkecil di antara itu, `upload_max_filesize`, dan `post_max_size`.

Menampilkan batas aplikasi saja akan menyesatkan: berkas yang melewati
`upload_max_filesize` ditolak web server sebelum PHP berjalan, sehingga yang
muncul bukan pesan kita melainkan galat tanpa penjelasan. Angka efektifnya
ditampilkan di halaman, dan diperiksa juga di peramban sebelum pengiriman
dimulai — supaya orang tidak menunggu berjam-jam untuk ditolak di ujung.

**Perlu Anda atur di server** (lihat bagian pengujian di bawah): nilai PHP dan
Nginx bawaan biasanya hanya 2–100 MB, jauh di bawah ukuran video episode.

### Video disimpan PRIVAT

`StorageCollection::EPISODE` bervisibility `private`, jadi `public_url` selalu
`null` dan `episodes.video_url` ikut `null`. Itu benar: URL permanen untuk isi
berbayar tidak boleh ada. Penyajiannya lewat temporary URL, di sprint
streaming.

### Season tidak dibuat

Spesifikasi menulis "Season (jika ada)". Di DramaVerse ID konsep itu **belum
ada** — tidak ada kolom, tabel, maupun relasi season di seluruh proyek.
Menambahkan pilihan yang tidak menyimpan ke mana pun hanya akan tampak
berfungsi. Dicatat sebagai komentar di view supaya keputusannya tidak hilang.

### Progress bar memakai XHR, bukan fetch()

`fetch()` tidak menyediakan kemajuan **pengiriman**. Untuk berkas gigabyte,
halaman tanpa progress bar tidak bisa dibedakan dari halaman yang menggantung —
dan orang akan menutupnya di tengah jalan. Karena itu endpoint-nya membalas
JSON, bukan redirect.

Setelah pengiriman mencapai 100%, label berganti menjadi "Server sedang
menyimpan ke storage provider…". Tanpa itu, progress bar berhenti di 100% dan
terlihat menggantung padahal server masih meneruskan berkas ke bucket.

Bila JavaScript mati, tombolnya tetap mengirim formulir secara normal ke route
yang sama. Respons JSON-nya tidak enak dilihat, tetapi berkasnya tetap
terunggah dan tidak ada yang hilang.

### Logging: dua lapisan, bukan duplikasi

Storage Engine sudah mencatat `storage.upload.success` dan `.failed` pada
tingkat berkas — tanpa tahu berkas itu milik episode mana. Service ini
menambahkan `episode.video.upload.started`, `.success`, `.failed`, dan
`.orphan` dengan konteks episode, drama, mode, checksum, dan durasi.

`started` khususnya tidak dimiliki engine, dan justru satu-satunya yang
tercatat ketika unggahan besar mati di tengah jalan tanpa pernah sampai ke
baris sukses maupun gagal.

---

## Bug yang ditemukan dan diperbaiki

### `x-admin.field` membuang atribut pada `select` dan `textarea`

`{{ $attributes }}` hanya ada di cabang `@default` (input teks). Cabang
`select` dan `textarea` tidak meneruskannya, jadi atribut apa pun yang
dikirim ke sana **dibuang tanpa suara**.

Akibat yang sudah berjalan: di halaman Tambah Episode Massal,

```blade
<x-admin.field name="drama_id" type="select" data-next-numbers="..." />
```

`data-next-numbers` tidak pernah sampai ke DOM. `autoEpisodeNumber()` di
`admin.js` mencari `document.querySelector('[data-next-numbers]')`, tidak
menemukannya, lalu `return` — **pengisian nomor episode otomatis tidak pernah
berfungsi sejak dibuat**, tanpa galat apa pun di console.

Diperbaiki dengan menambahkan `{{ $attributes }}` ke kedua cabang. Halaman
7.5 memerlukannya untuk `data-drama`; halaman batch mendapat perbaikannya
sebagai efek samping.

### `diskName()` bisa membatalkan unggahan yang sudah berhasil

Versi pertama saya memanggil `$this->storage->resolveProvider(...)` hanya untuk
mengambil slug. Method itu memvalidasi ulang dan bisa melempar — dan bila itu
terjadi, jalur pembatalan akan menghapus objek yang sudah aman di penyimpanan,
hanya karena satu kolom keterangan gagal diisi.

Diganti pembacaan langsung dari model dengan `withTrashed()` dan fallback ke
nama driver. Tidak bisa melempar.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-php-structure.py       344 berkas, 0 bermasalah
python tools/check-css-coverage.py        205 kelas, semua punya aturan
python tools/check-blade-directives.py    65 blade, 0 bermasalah
node (sintaks admin.js)                   valid
```

Self-audit (34 pemeriksaan, semuanya lolos):

- Controller dan Service: nol `Storage::`, nol `->store()`/`->storeAs()`, nol
  `disk(`, nol `move_uploaded_file`/`file_put_contents` — diperiksa setelah
  komentar dibuang, supaya docblock tidak menghasilkan GAGAL palsu
- Service memanggil `engine->upload()` dengan `StorageCollection::EPISODE`
- Controller tidak menyimpan metadata sendiri; itu tugas service
- 13 kolom spesifikasi ada di migration **dan** ditulis service
- checksum dihitung sebelum `engine->upload()` (dibuktikan dari posisi
  keduanya di berkas)
- jalur pembatalan ada dan menghapus objek terunggah
- video lama dihapus setelah metadata baru tersimpan
- kelima validasi berjalan, dan `UsableStorageProvider` benar-benar memeriksa
  `last_test_status`
- daftar provider disaring `active()` + `isUsable()` + lolos test — syarat yang
  sama dengan validasinya, jadi tidak ada pilihan yang tampil lalu ditolak

**Semua verifikasi ini statis.** Yang hanya bisa dibuktikan di browser ada di
bagian berikutnya.

---

## Belum dikerjakan (sengaja)

- Telegram, `telegram_file_id`
- Queue upload, Retry
- Subtitle, Thumbnail, Poster upload
- Episode Publish, Streaming, Video Player
- Hapus video dari panel (mengunggah ulang sudah menggantinya)
- Verifikasi checksum terhadap berkas yang ada di bucket — kolomnya sudah ada
  dan terisi, tapi belum ada perintah yang membandingkannya
- Pemindahan `Admin\MediaService` ke engine (masih dari Sprint 7.4)
