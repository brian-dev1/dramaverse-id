# Cloudflare R2 + Telegram Bot

Panduan ini untuk memasang Cloudflare R2 sebagai tempat video episode, lalu
membuat bot Telegram mengirim video ke penonton tanpa mengubah aturan akses:
episode gratis tetap gratis, episode VIP tetap hanya untuk premium.

## Alur singkat

1. Video diunggah admin ke storage provider default.
2. Kalau R2 sudah default, video baru masuk ke bucket R2.
3. Admin menjalankan sinkronisasi Telegram.
4. Aplikasi membaca video dari R2, mengirim sekali ke channel privat
   `TELEGRAM_STORAGE_CHAT_ID`, lalu menyimpan `file_id`.
5. Saat penonton menekan tombol episode di bot, aplikasi memeriksa akses.
6. Bot hanya mengirim `file_id` bila penonton berhak menonton.

## Di Cloudflare

1. Buat bucket R2.
2. Buat R2 API token dengan izin object read/write untuk bucket itu.
3. Catat:
   - Account ID
   - Bucket name
   - Access Key ID
   - Secret Access Key

Bucket video sebaiknya tetap privat. Jangan jadikan bucket publik hanya supaya
video bisa ditonton, karena alur tontonnya sudah lewat Telegram dan membership.

## Di VS Code / lokal

Jalankan dari folder project:

```bash
cd C:\ProjectDrama\dramaverse-id
```

Cek perubahan:

```bash
git status
```

Commit dan push ke GitHub:

```bash
git add app/Console/Commands/StorageR2Setup.php config/storage.php .env.example docs/STORAGE.md docs/TELEGRAM.md docs/R2-TELEGRAM-DEPLOY.md
git commit -m "Add Cloudflare R2 setup for Telegram videos"
git push origin main
```

Kalau branch production bukan `main`, ganti `main` dengan nama branch yang
dipakai VPS.

## Di VPS

Masuk ke folder aplikasi:

```bash
cd /var/www/dramaverse
```

Ambil update dari GitHub:

```bash
git pull origin main
```

Install dependency dan build seperti deploy biasa:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
```

Isi `.env` di VPS. Jangan commit nilai ini ke Git:

```env
R2_ACCOUNT_ID=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
R2_BUCKET=nama-bucket
R2_ACCESS_KEY_ID=isi-access-key
R2_SECRET_ACCESS_KEY=isi-secret-key
R2_REGION=auto
R2_ROOT=
R2_VISIBILITY=private
```

Pastikan chat penyimpanan Telegram juga sudah ada:

```env
TELEGRAM_STORAGE_CHAT_ID=-100xxxxxxxxxx
```

Buat/update provider R2, test koneksi, aktifkan, dan jadikan default:

```bash
php artisan config:clear
php artisan storage:r2:setup --test --activate --default
php artisan storage:smoke r2
```

Cache ulang konfigurasi production:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Restart worker dan PHP service:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart all
sudo systemctl reload php8.2-fpm
```

Sesuaikan nama service PHP bila VPS memakai versi berbeda.

## Setelah R2 aktif

Unggah video baru dari panel admin. Video baru akan masuk ke R2 karena R2 sudah
menjadi default.

Lalu sinkronkan ke Telegram dari panel:

```text
/admin/telegram/sync
```

Atau jalankan perawatan otomatis dari VPS:

```bash
php artisan telegram:auto all
```

Untuk video di atas 50 MB, Bot API resmi Telegram akan menolak upload. Gunakan
Local Bot API Server dan isi `TELEGRAM_API_URL`, lalu naikkan
`TELEGRAM_UPLOAD_MAX_MB` sesuai batas server itu.
