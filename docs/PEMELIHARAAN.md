# Panduan Pemeliharaan

## Harian — otomatis

Tidak ada yang perlu dikerjakan. Scheduler menjalankan cadangan, verifikasi
pembayaran, kedaluwarsa langganan, penerbitan episode, dan perawatan Telegram.

Yang perlu Anda lakukan: **baca peringatan yang masuk**. Peringatan hanya
dikirim saat ada yang rusak, dan sudah ditahan supaya tidak membanjiri.

## Mingguan — lima menit

1. `/admin/monitoring` — semuanya hijau?
2. `php artisan queue:failed` — kosong?
3. `/admin/telegram/sync` — ada yang Gagal menumpuk?
4. Ruang disk: `df -h`

## Bulanan

1. **Uji pemulihan cadangan** ke basis data terpisah. Lihat
   [CADANGAN.md](CADANGAN.md).
2. `php artisan storage:test` — seluruh provider masih terhubung?
3. `php artisan upload:prune` — buang berkas staging yang tertinggal.
4. Tinjau `/admin/logs` untuk aktivitas admin yang tidak Anda kenali.

## Setiap deploy

```bash
cd /var/www/dramaverse
bash deploy.sh
php artisan env:check --production
supervisorctl restart all
```

`deploy.sh` menjalankan pull, composer, npm build, migrate, cache, izin
berkas, dan restart worker.

**Jangan pernah** menjalankan `migrate:fresh` setelah ada data pengguna
sungguhan.

## Setelah mengubah kode

Sembilan alat verifikasi, semuanya statis:

```bash
python tools/verify-consistency.py
python tools/check-blade-directives.py resources/views/**/*.blade.php
python tools/check-css-coverage.py
python tools/check-php-structure.py app/**/*.php config/*.php database/**/*.php routes/*.php
python tools/audit-final.py
```

Plus audit per-fase di `tools/audit-*.py`.

**Semuanya statis — tidak menjalankan PHP.** Beberapa kesalahan hanya muncul
saat dieksekusi. Selalu uji di browser setelah deploy.

## Yang perlu diperhatikan seiring waktu

| Hal | Kenapa |
|---|---|
| Ukuran tabel `activity_logs` | Tumbuh terus, belum ada pemangkasan otomatis |
| Ukuran tabel `jobs` dan `failed_jobs` | `failed_jobs` tidak dibersihkan sendiri |
| Ruang bucket | File Manager tidak menampilkan berkas yatim |
| Masa berlaku `file_id` Telegram | Belum ada verifikasi terjadwal; jalankan Bulk Verify sesekali |
| Kredensial gateway | Sebagian punya masa berlaku |

## Mode pemeliharaan

```bash
php artisan down --secret="kata-rahasia-anda"
# akses tetap terbuka lewat https://dracinverse.cloud/kata-rahasia-anda
php artisan up
```

Aplikasi juga punya sakelar pemeliharaan sendiri di **Pengaturan** yang
menutup situs untuk pengguna tetapi membiarkan admin masuk — dipakai untuk
perawatan yang tidak butuh mematikan aplikasi.
