# Dokumentasi Storage

## Aturan tunggal

**Tidak ada satu pun controller yang boleh menyentuh `Storage`.** Seluruh
operasi berkas lewat `StorageEngineInterface`.

Alasannya bukan kerapian: selama pengetahuan tentang provider tersebar di
controller, memindahkan penyimpanan dari R2 ke Wasabi berarti menyunting
setiap tempat yang pernah mengunggah sesuatu — dan yang terlewat baru ketahuan
saat ada berkas yang tidak bisa ditemukan.

Diperiksa otomatis oleh `tools/audit-sprint-7-8.py`.

## Provider

Ada di tabel `storage_providers`, bukan di `.env`. Kredensial terenkripsi
dengan `APP_KEY`. Dikelola di `/admin/storage`.

Sembilan driver dikenal: `local`, `s3`, `r2`, `b2`, `wasabi`, `spaces`,
`minio`, `gcs`, `azure`. Enam di antaranya memakai protokol S3 dan
membutuhkan:

```bash
composer require league/flysystem-aws-s3-v3
```

**Tepat satu provider berstatus default.** Invarian itu dijaga transaction
plus `SELECT ... FOR UPDATE` seluruh baris — transaction sendirian tidak
cukup, karena dua permintaan bersamaan bisa sama-sama membersihkan flag
sebelum salah satunya commit.

## Kewajiban modul yang menyimpan berkas

**Simpan `provider_id` bersama `object_key`.** Menyimpan key saja hanya benar
sampai provider default dipindah — sesudahnya berkas dicari di bucket yang
salah, dan gejalanya "berkas hilang" tanpa jejak.

## Menguji

```bash
php artisan storage:test            # semua provider
php artisan storage:test r2         # satu provider
php artisan storage:smoke           # siklus penuh lewat engine, mode Auto
php artisan storage:smoke local     # provider tertentu
```

`storage:test` menulis satu berkas kecil, membacanya kembali, lalu
menghapusnya. Jalankan setelah menambah provider **dan setelah setiap
deploy**.

## Modul yang memakainya

| Modul | Halaman |
|---|---|
| Video episode | `/admin/episode/video` |
| Aset drama | `/admin/drama/{id}/asset` |
| Unggah massal | `/admin/upload/batch` |
| Antrean | `/admin/upload` |
| File Manager | `/admin/files` |
| Monitoring | `/admin/storage/monitor` |

## Yang belum ada

- **Load balancing dan failover.** `StorageManager::chain()` menyiapkan
  urutannya, tetapi engine selalu memakai satu provider dan gagal
  terang-terangan. Berpindah diam-diam akan menyebarkan berkas satu modul ke
  beberapa bucket tanpa ada yang memutuskan begitu.
- **Migrasi berkas antar provider.** File Manager memindahkan antar direktori
  di provider yang sama.
- **Verifikasi checksum terhadap bucket.** Kolomnya terisi setiap unggahan,
  tetapi belum ada yang membandingkannya.
- **GCS dan Azure** — baru kerangka.
