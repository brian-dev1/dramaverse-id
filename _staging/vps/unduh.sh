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
# Yang menentukan kecepatan sebenarnya bukan jumlah koneksi, tapi
# berapa request 1 MB yang boleh terbang bersamaan di TIAP koneksi:
#
#   TG_INFLIGHT_PER_CONN=3 unduh      # default, 4 x 3 = 12 in-flight
#   TG_INFLIGHT_PER_CONN=1 unduh      # perilaku lama, paling hemat
#   TG_INFLIGHT_PER_CONN=4 unduh      # lebih agresif, lebih rawan flood
#
# Berapa pesan terakhir di chat bot yang dipindai saat mencari video.
# Video di luar batas ini TIDAK muncul di daftar, tanpa keterangan apa
# pun -- terlihat sama persis dengan videonya memang tidak ada:
#
#   unduh                             # 100 pesan terakhir (default)
#   TG_SCAN_LIMIT=300 unduh           # kalau video lama belum kebaca
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

if [ ! -f "/root/fast_download.py" ]; then
    echo "GAGAL: fast_download.py tidak ada di /root" >&2
    exit 1
fi

export TG_PARALLEL_CONNECTIONS="${1:-4}"
export TG_INFLIGHT_PER_CONN="${TG_INFLIGHT_PER_CONN:-3}"
export TG_SCAN_LIMIT="${TG_SCAN_LIMIT:-100}"

# Jangan biarkan proxy warisan di shell membelokkan trafik keluar dari
# VPS. Skrip juga akan mencetak IP publiknya sendiri saat start.
unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy

cd /root

echo "==> Koneksi paralel   : $TG_PARALLEL_CONNECTIONS"
echo "==> Request/koneksi   : $TG_INFLIGHT_PER_CONN"
echo "==> Total in-flight   : $((TG_PARALLEL_CONNECTIONS * TG_INFLIGHT_PER_CONN)) MB"
echo "==> Pesan dipindai    : $TG_SCAN_LIMIT"

exec "$VENV/bin/python3" "$SKRIP"
