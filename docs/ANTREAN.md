# Antrean & Scheduler

Dua hal yang **wajib** berjalan di produksi. Keduanya gagal secara diam bila
lupa dipasang: tidak ada galat, tidak ada log, hanya fitur yang tidak pernah
terjadi.

## Cron — sekali saja

```bash
crontab -e
```

```
* * * * * cd /var/www/dramaverse && php artisan schedule:run >> /dev/null 2>&1
```

Verifikasi:

```bash
php artisan schedule:list
php artisan env:check --production
```

`env:check` membaca detak scheduler dari cache dan **menolak** bila tidak ada
detak dalam sepuluh menit terakhir. Detak itu ditulis `routes/console.php`
setiap menit khusus untuk menjawab pertanyaan "apakah cron benar-benar
jalan".

## Worker — dua antrean

| Antrean | Isi | Kenapa dipisah |
|---|---|---|
| `uploads` | Video ke storage provider | Video 4 GB tidak boleh menahan broadcast di belakangnya |
| `default` | Broadcast, sinkron Telegram, verifikasi pembayaran | Pekerjaan singkat |

Supervisor:

```ini
[program:dramaverse-worker]
command=php /var/www/dramaverse/artisan queue:work --queue=default --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2

[program:dramaverse-upload]
command=php /var/www/dramaverse/artisan queue:work uploads --queue=uploads --sleep=3 --tries=1 --timeout=3600 --max-time=7200
autostart=true
autorestart=true
user=www-data
numprocs=1
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl status
```

### Kesalahan yang pernah terjadi

**Argumen pertama `queue:work` adalah nama KONEKSI, bukan nama antrean.**
`queue:work redis` berarti "ambil dari koneksi redis". Pernah terpasang begitu
sementara `QUEUE_CONNECTION=database` — job masuk ke tabel `jobs`, worker
menunggui Redis, dan keduanya tidak pernah bertemu. Tanpa satu pun galat.

Karena itu worker `default` di atas **tidak menyebut koneksi**: ia mengikuti
`.env`, satu sumber kebenaran.

**Worker memuat kode saat dinyalakan.** Restart setelah setiap deploy, atau
yang lama akan terus menjalankan versi sebelumnya.

## Jadwal yang berjalan

| Perintah | Kapan | Untuk apa |
|---|---|---|
| detak scheduler | tiap menit | Membuktikan cron jalan |
| `telegram:auto retry` | 15 menit | Ulangi sinkronisasi gagal |
| `telegram:auto health` | 30 menit | Periksa bot dan antrean |
| `telegram:auto cleanup` | tiap jam | Lepaskan yang tersangkut, buang berkas sementara |
| `payment:auto verify` | 5 menit | Tanyakan ulang pembayaran yang menunggu |
| `payment:auto expire` | tiap jam | Kedaluwarsakan tagihan dan langganan |
| `episodes:publish` | 5 menit | Terbitkan episode berjadwal |
| `analytics:refresh` | 10 menit | Panaskan cache dashboard |
| `backup:run` | 02:30 | Cadangan, verifikasi, pemangkasan |

Seluruhnya `withoutOverlapping()`. Tanpa itu, jalan yang lambat bertumpuk
dengan jalan berikutnya — dan dua proses yang sama-sama mengantrekan ulang
video gagal akan mengirim berkas yang sama dua kali.

## Perintah manual

```bash
php artisan queue:failed          # daftar yang gagal
php artisan queue:retry all       # ulangi semuanya
php artisan upload:prune          # buang berkas staging
php artisan cache:application     # bersihkan cache aplikasi
php artisan telegram:auto all     # perawatan Telegram sekarang juga
```
