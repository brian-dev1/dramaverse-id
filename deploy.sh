#!/usr/bin/env bash
#
# DramaVerse ID — skrip deploy VPS
#
# Pemakaian:
#   bash deploy.sh
#
# Skrip ini menentukan lokasi project dari posisinya sendiri, jadi nama
# folder di server bebas (dramaverse, dramaverse-id, apa pun).
#
# PENTING: server ini hanya MENARIK kode. Jangan pernah commit di sini.
# Kode ditulis dan di-commit di mesin pengembangan, lalu di-push ke GitHub.
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

# Langkah yang sedang berjalan, dipakai pesan kegagalan di bawah.
STEP="persiapan"

# Situs selalu dinyalakan kembali, berhasil maupun gagal. Tapi keduanya
# TIDAK boleh terlihat sama: versi lama skrip ini mencetak "Application is
# now live" setelah pull yang gagal, sehingga deploy yang batal di tengah
# jalan mudah disalahartikan sebagai deploy yang sukses.
selesai() {
    local code=$?

    php artisan up || true

    if [ "$code" -ne 0 ]; then
        echo >&2
        echo "============================================================" >&2
        echo "DEPLOY GAGAL pada langkah: ${STEP}" >&2
        echo "Kode keluar: ${code}" >&2
        echo >&2
        echo "Situs sudah dinyalakan kembali, TETAPI masih menjalankan" >&2
        echo "kode LAMA. Perubahan terbaru belum aktif." >&2
        echo "============================================================" >&2
    fi

    exit "$code"
}
trap selesai EXIT

# Diperiksa SEBELUM apa pun disentuh — sebelum mode pemeliharaan, sebelum
# kode ditarik. Kalau ada yang fatal, situs tidak pernah sempat turun dan
# tidak ada satu pun perubahan yang perlu dibatalkan.
#
# Yang paling penting dijaga di sini: TELEGRAM_API_URL, TELEGRAM_BOT_TOKEN,
# dan folder TELEGRAM_API_DIR. Ketiganya mematikan SELURUH telegram_file_id
# sekaligus bila berubah, tanpa menimbulkan galat apa pun saat terjadi — yang
# terlihat baru muncul saat pengguna pertama menekan tombol tonton, dan pada
# saat itu videonya sudah tidak bisa dipulihkan.
echo "==> Periksa environment"
STEP="periksa environment (env:check)"
php artisan env:check

echo "==> Mode pemeliharaan"
STEP="mode pemeliharaan"
php artisan down || true

echo "==> Tarik kode terbaru"
STEP="tarik kode terbaru (git)"

git fetch origin main

# `git pull` tanpa strategi eksplisit berhenti dengan pesan membingungkan
# begitu riwayat server berbeda dari origin. `merge --ff-only` lebih tepat
# untuk target deploy: berhasil kalau server memang cuma tertinggal, gagal
# keras kalau tidak — dan tidak pernah diam-diam membuat commit merge atau
# menghapus apa pun.
if ! git merge --ff-only FETCH_HEAD; then
    echo >&2
    echo "GAGAL: riwayat git di server berbeda dari origin/main." >&2
    echo >&2
    echo "Server ini seharusnya hanya menarik kode, tidak pernah dipakai" >&2
    echo "untuk commit. Lihat commit yang hanya ada di server:" >&2
    echo >&2
    echo "    git log --oneline origin/main..HEAD" >&2
    echo >&2
    echo "Kalau commit itu memang tidak diperlukan, buang dan samakan" >&2
    echo "dengan GitHub:" >&2
    echo >&2
    echo "    git reset --hard origin/main" >&2
    echo >&2
    echo "PERINGATAN: reset --hard menghapus commit lokal server itu" >&2
    echo "beserta perubahan yang belum ter-commit. Periksa isinya dulu:" >&2
    echo >&2
    echo "    git diff HEAD origin/main --stat" >&2
    echo >&2
    exit 1
fi

echo "==> Dependensi PHP"
STEP="composer install"
composer install --no-dev --optimize-autoloader

echo "==> Dependensi JS + build aset"
STEP="npm ci"
npm ci

STEP="npm run build"
npm run build

echo "==> Bersihkan cache lama"
# Wajib sebelum migrate/seed: perintah artisan membaca route & config dari
# cache, dan cache lama bisa merujuk route yang sudah tidak ada.
STEP="optimize:clear"
php artisan optimize:clear

echo "==> Migration"
STEP="migrate"
php artisan migrate --force

echo "==> Bangun ulang cache"
STEP="bangun ulang cache"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Izin berkas"
STEP="izin berkas"
mkdir -p storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> Link storage publik"
STEP="storage link"
php artisan storage:link --force

echo "==> Restart worker antrean"
STEP="restart worker"
supervisorctl restart dramaverse-worker:* || true

echo "==> Selesai"
