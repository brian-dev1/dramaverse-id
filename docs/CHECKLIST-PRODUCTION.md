# Checklist Production

Jalankan berurutan. Yang bertanda **FATAL** menghentikan peluncuran.

## 1. Otomatis dulu

```bash
php artisan env:check --production
```

Keluar dengan kode 1 bila ada FATAL. Perintah ini memeriksa `APP_KEY`,
`APP_DEBUG`, `APP_ENV`, https, sambungan basis data, migration yang belum
jalan, token Telegram, rahasia webhook, provider pembayaran mode sandbox,
driver antrean, detak scheduler, dan izin folder.

Sisanya di bawah tidak bisa diperiksa mesin.

## 2. Environment

- [ ] **FATAL** `APP_DEBUG=false`
- [ ] **FATAL** `APP_ENV=production`
- [ ] **FATAL** `APP_KEY` sudah dibuat, dan **dicadangkan di tempat terpisah**
- [ ] **FATAL** `APP_URL` memakai https
- [ ] `.env` tidak ikut ter-commit (`git check-ignore .env`)
- [ ] Izin `.env` 600, pemiliknya bukan www-data

## 3. Basis data

- [ ] **FATAL** `php artisan migrate --force` selesai tanpa galat
- [ ] `php artisan db:seed --class='Database\Seeders\RoleSeeder' --force`
- [ ] Kata sandi admin awal **sudah diganti** (`php artisan admin:password`)
- [ ] Pengguna basis data bukan `root`, dan haknya terbatas pada satu database

## 4. Telegram

- [ ] **FATAL** `php artisan telegram:test` berhasil
- [ ] **FATAL** `TELEGRAM_WEBHOOK_SECRET` terisi
- [ ] Webhook terdaftar ke `https://.../telegram/webhook`
- [ ] `TELEGRAM_STORAGE_CHAT_ID` terisi, channelnya **privat**
- [ ] `php artisan telegram:test --chat=<id-anda>` — pesan benar-benar sampai
- [ ] `grep -i "bot[0-9]" storage/logs/laravel.log` **kosong**

## 5. Storage

- [ ] **FATAL** `php artisan storage:test` — seluruh provider aktif lolos
- [ ] `php artisan storage:smoke` — siklus penuh lewat engine berhasil
- [ ] Tepat satu provider berstatus default, dan statusnya aktif
- [ ] `php artisan storage:link` sudah dijalankan
- [ ] `upload_max_filesize`, `post_max_size`, `client_max_body_size` dinaikkan

## 6. Pembayaran

- [ ] Minimal satu provider aktif dan `isUsable()`
- [ ] **FATAL** tidak ada provider aktif yang masih mode `sandbox`
- [ ] URL callback terdaftar di dashboard gateway
- [ ] Satu transaksi uji berhasil dari checkout sampai membership aktif
- [ ] Paket membership terisi harga dan durasi yang benar

## 7. Antrean & scheduler

- [ ] **FATAL** cron `schedule:run` terpasang
- [ ] **FATAL** worker `default` **dan** `uploads` keduanya RUNNING
- [ ] `php artisan schedule:list` menampilkan sembilan jadwal
- [ ] `php artisan queue:failed` kosong
- [ ] Supervisor `autostart=true` dan `autorestart=true`

## 8. Cadangan

- [ ] **FATAL** `php artisan backup:run` berhasil
- [ ] **FATAL** pemulihan sudah **diuji** ke basis data terpisah
- [ ] `APP_KEY` dicadangkan terpisah dari dump
- [ ] Ruang disk cukup untuk retensi yang disetel

## 9. Keamanan

- [ ] HTTPS aktif, sertifikat berlaku, perpanjangan otomatis jalan
- [ ] Security header terpasang (middleware `SecurityHeaders`)
- [ ] Rate limit aktif untuk login admin, API, dan broadcast
- [ ] Firewall: hanya 22, 80, 443 terbuka
- [ ] MySQL dan Redis tidak mendengarkan alamat publik
- [ ] Login SSH memakai kunci, bukan kata sandi
- [ ] `storage/` dan `bootstrap/cache/` **tidak** bisa diakses lewat web

## 10. Uji jalur penuh di browser

- [ ] `/start` di bot, tekan **Buka Website** — tautan masuk berfungsi
- [ ] Cari drama lewat bot, buka daftar episode, tonton satu episode gratis
- [ ] Buka episode premium dengan akun gratis — muncul penawaran, bukan video
- [ ] Berlangganan sampai membership aktif
- [ ] Buka episode premium lagi — sekarang bisa
- [ ] Tonton di website, cek riwayat muncul di bot
- [ ] Favoritkan di bot, cek muncul di website
- [ ] Panel admin: unggah episode, sinkronkan ke Telegram, tonton lewat bot

## 11. Setelah meluncur

- [ ] Pantau `/admin/monitoring` selama 24 jam pertama
- [ ] Cek `storage/logs/laravel.log` untuk galat yang tidak dikenali
- [ ] Pastikan cadangan pertama yang terjadwal benar-benar berjalan
- [ ] Pastikan `payment:auto expire` tidak mengedaluwarsakan yang seharusnya aktif
