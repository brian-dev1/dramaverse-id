#!/usr/bin/env bash
#
# DramaVerse ID — deploy downloader Telegram ke /root
#
# Pemakaian di VPS:
#   bash /var/www/dramaverse/deploy-worker.sh
#
# Pintasan (sekali saja):
#   echo "alias deploy-worker='bash /var/www/dramaverse/deploy-worker.sh'" >> /root/.bashrc
#   source /root/.bashrc
#
# Bedanya dengan deploy.sh: skrip ini TIDAK menyentuh aplikasi Laravel
# sama sekali. Tidak ada composer, npm, migrate, cache, atau mode
# pemeliharaan — situs tidak pernah turun. Ia cuma menarik kode terbaru
# lalu menyalin berkas downloader ke /root, tempat `unduh` mencarinya.
#
# PENTING: server hanya MENARIK kode. Jangan pernah commit di sini.
# Kode ditulis dan di-commit di mesin pengembangan, lalu di-push ke
# GitHub.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TUJUAN="/root"

BERKAS=(
    "workers/fast_download.py"
    "workers/telegram_video_downloader.py"
    "workers/test_fast_download.py"
)

cd "$APP_DIR"

if [ ! -d workers ]; then
    echo "GAGAL: folder 'workers' tidak ada di $APP_DIR" >&2
    exit 1
fi

echo "==> Project : $APP_DIR"
echo "==> Tujuan  : $TUJUAN"

# ------------------------------------------------------------------
# Tarik kode terbaru
# ------------------------------------------------------------------

echo "==> Tarik kode terbaru"

git fetch origin main

# merge --ff-only, bukan `git pull`: berhasil kalau server memang cuma
# tertinggal, gagal keras kalau riwayatnya menyimpang — dan tidak pernah
# diam-diam membuat commit merge.
if ! git merge --ff-only FETCH_HEAD; then
    echo >&2
    echo "GAGAL: riwayat git di server berbeda dari origin/main." >&2
    echo >&2
    echo "Lihat commit yang hanya ada di server:" >&2
    echo "    git -C $APP_DIR log --oneline origin/main..HEAD" >&2
    echo >&2
    echo "Kalau tidak diperlukan, samakan dengan GitHub:" >&2
    echo "    git -C $APP_DIR reset --hard origin/main" >&2
    exit 1
fi

# ------------------------------------------------------------------
# Periksa dulu, salin belakangan
#
# Berkas rusak yang sudah telanjur menimpa /root membuat `unduh` mati
# total. Jadi semuanya diperiksa lebih dulu; kalau ada satu saja yang
# gagal, tidak ada yang disalin dan versi lama tetap utuh.
# ------------------------------------------------------------------

echo "==> Periksa berkas"

PY="/root/telegram-env/bin/python3"

if [ ! -x "$PY" ]; then
    PY="$(command -v python3)"
fi

for berkas in "${BERKAS[@]}"; do
    if [ ! -f "$berkas" ]; then
        echo "GAGAL: $berkas tidak ada di repo" >&2
        exit 1
    fi

    if ! "$PY" -m py_compile "$berkas" 2>/dev/null; then
        echo "GAGAL: $berkas tidak lolos pemeriksaan sintaks" >&2
        exit 1
    fi
done

echo "    $(printf '%s ' "${BERKAS[@]##*/}")— lolos"

# ------------------------------------------------------------------
# Cadangkan versi yang sedang jalan
# ------------------------------------------------------------------

CADANGAN="$TUJUAN/cadangan-worker/$(date +%Y%m%d-%H%M%S)"

mkdir -p "$CADANGAN"

for berkas in "${BERKAS[@]}"; do
    nama="$(basename "$berkas")"

    if [ -f "$TUJUAN/$nama" ]; then
        cp "$TUJUAN/$nama" "$CADANGAN/$nama"
    fi
done

if [ -n "$(ls -A "$CADANGAN" 2>/dev/null)" ]; then
    echo "==> Cadangan: $CADANGAN"
else
    rmdir "$CADANGAN" 2>/dev/null || true
fi

# ------------------------------------------------------------------
# Salin
#
# File sesi (.session) dan folder downloads/ TIDAK disentuh.
# ------------------------------------------------------------------

echo "==> Salin ke $TUJUAN"

for berkas in "${BERKAS[@]}"; do
    cp "$berkas" "$TUJUAN/$(basename "$berkas")"
    echo "    $(basename "$berkas")"
done

if [ -f "_staging/vps/unduh.sh" ]; then
    cp "_staging/vps/unduh.sh" "$TUJUAN/unduh.sh"
    chmod +x "$TUJUAN/unduh.sh"
    echo "    unduh.sh"
fi

# Cache lama bisa membuat Python memuat versi sebelumnya.
rm -rf "$TUJUAN/__pycache__" 2>/dev/null || true

echo "==> Selesai. Jalankan: unduh"
