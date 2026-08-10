#!/usr/bin/env bash
#
# Pintasan menjalankan downloader Telegram di VPS.
#
# Pemasangan (sekali saja, di VPS):
#   cp unduh.sh /root/unduh.sh && chmod +x /root/unduh.sh
#   echo "alias unduh='/root/unduh.sh'" >> /root/.bashrc && source /root/.bashrc
#
# Pemakaian:
#   unduh          -> 4 koneksi paralel (default)
#   unduh 6        -> 6 koneksi paralel
#
set -euo pipefail

VENV="/root/telegram-env"
SKRIP="/root/telegram_video_downloader.py"

if [ ! -x "$VENV/bin/python3" ]; then
    echo "GAGAL: virtualenv tidak ditemukan di $VENV" >&2
    exit 1
fi

if [ ! -f "$SKRIP" ]; then
    echo "GAGAL: skrip tidak ditemukan di $SKRIP" >&2
    exit 1
fi

export TG_PARALLEL_CONNECTIONS="${1:-4}"

cd /root

echo "==> Koneksi paralel: $TG_PARALLEL_CONNECTIONS"

exec "$VENV/bin/python3" "$SKRIP"
