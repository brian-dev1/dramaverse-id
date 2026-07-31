# Panduan Masalah

Disusun dari gejala, karena itu yang Anda lihat lebih dulu.

## Bot

**Bot tidak membalas apa pun.**
```bash
php artisan telegram:test
```
Token salah, jaringan VPS, atau webhook tidak terdaftar. Pesannya menyebutkan
langkah berikutnya.

**Tombol ditekan, tidak terjadi apa-apa.**
Lihat `/admin/telegram/log`, saring level `error`. Callback yang meledak
dicatat sebagai `telegram.callback.error`.

**Broadcast tidak sampai.**
Hampir selalu antrean, bukan Telegram. Buka `/admin/telegram` — panel Antrean
menampilkan koneksi, nama antrean, dan jumlah yang menunggu. Angka yang naik
dan tidak turun berarti worker tidak mendengarkan antrean itu. Lihat
[ANTREAN.md](ANTREAN.md).

**Video tidak terkirim ke penonton.**
Belum tersinkron. Buka `/admin/telegram/sync`, cari episodenya, tekan
Sinkronkan.

**Sinkronisasi selalu gagal untuk video besar.**
Bot API menolak di atas 50 MB. Itu batas Telegram. Lihat
[TELEGRAM.md](TELEGRAM.md).

## Unggahan

**Unggahan menggantung di status Menunggu selamanya.**
Worker tidak mendengarkan antrean `uploads`. Perintahnya harus menyebutnya
eksplisit.

**419 tanpa penjelasan saat mengunggah video.**
Berkasnya melewati `post_max_size`. Request-nya kosong sampai ke Laravel, jadi
yang muncul kegagalan CSRF, bukan pesan validasi. Naikkan di `php.ini` **dan**
`client_max_body_size` Nginx.

**Baris tersangkut di Diproses.**
Worker dibunuh di tengah jalan. Dilepaskan otomatis oleh `telegram:auto
cleanup` setelah `TELEGRAM_STUCK_MINUTES`, atau tekan Refresh Status.

## Pembayaran

**Sudah bayar, membership belum aktif.**
1. `/admin/payment/log`, cari nomor invoicenya.
2. `payment.callback.received` tidak ada berarti callback tidak sampai —
   `payment:auto verify` akan menutupnya dalam lima menit.
3. `payment.callback.amount_mismatch` berarti nominalnya tidak cocok dan
   sengaja tidak diaktifkan. Perlu diperiksa manual.
4. `payment.callback.illegal_transition` berarti callback datang terlambat
   setelah status berubah.

**Callback ditolak terus.**
`payment.callback.invalid_signature` — kredensial provider tidak cocok dengan
yang ada di dashboard gateway.

**Provider tidak muncul di checkout.**
Cek `blocker()`-nya di `/admin/payment/provider`: driver masih kerangka,
kredensial belum lengkap, atau statusnya nonaktif.

## Akses

**Episode premium tidak bisa dibuka padahal langganan aktif.**
```bash
php artisan env:check
```
Pastikan kolom `users.is_premium` ada. Kolom itu sempat tidak pernah dibuat,
dan akibatnya **tidak ada satu pun episode yang bisa ditonton siapa pun** —
diperbaiki di Phase 10 lewat migration.

**Tautan masuk dari bot tidak bisa dipakai.**
Sekali pakai dan berlaku sepuluh menit. Tekan Buka Website lagi.

## Umum

**Perubahan `.env` tidak berpengaruh.**
```bash
php artisan config:cache
```

**Halaman putih setelah deploy.**
```bash
tail -50 storage/logs/laravel.log
chown -R www-data:www-data storage bootstrap/cache
```

**Fitur terjadwal tidak pernah berjalan.**
Cron belum dipasang. `php artisan env:check --production` akan mengatakannya.

**Log penuh sampai disk habis.**
Channel `daily` berputar sendiri. Yang tidak berputar adalah `single`; periksa
`LOG_CHANNEL`.
