#!/usr/bin/env bash
#
# DramaVerse ID — skrip deploy VPS
#
# Pemakaian:
#   ./deploy.sh
#
# Skrip ini menentukan lokasi project dari posisinya sendiri, jadi nama
# folder di server bebas (dramaverse, dramaverse-id, apa pun).
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

if [ ! -f artisan ]; then
    echo "GAGAL: 'artisan' tidak ditemukan di $APP_DIR" >&2
    echo "Jalankan skrip ini dari dalam folder project." >&2
    exit 1
fi

echo "==> Project: $APP_DIR"

echo "==> Mode pemeliharaan"
php artisan down || true
trap 'php artisan up || true' EXIT

echo "==> Tarik kode terbaru"
git pull origin main

echo "==> Dependensi PHP"
composer install --no-dev --optimize-autoloader

echo "==> Dependensi JS + build aset"
npm ci
npm run build

echo "==> Bersihkan cache lama"
# Wajib sebelum migrate/seed: perintah artisan membaca route & config dari
# cache, dan cache lama bisa merujuk route yang sudah tidak ada.
php artisan optimize:clear

echo "==> Migration"
php artisan migrate --force

echo "==> Bangun ulang cache"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Izin berkas"
mkdir -p storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> Restart worker antrean"
supervisorctl restart dramaverse-worker:* || true

echo "==> Selesai"
