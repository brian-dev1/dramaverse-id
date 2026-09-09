#!/usr/bin/env bash
#
# Pintasan menjalankan downloader Telegram di VPS.
#
# Pemasangan: tidak perlu manual. `deploy-worker` menyalin berkas ini ke
# /root/unduh.sh setiap kali dijalankan.
#
# Pintasan perintah (sekali saja):
#   echo "alias unduh='/root/unduh.sh'" >> /root/.bashrc && source /root/.bashrc
#
# PENTING: jangan menyunting /root/unduh.sh langsung — `deploy-worker`
# akan menimpanya. Sunting _staging/vps/unduh.sh di repo, commit, lalu
# deploy.
#
# Pemakaian:
#   unduh              -> 6 koneksi, pindai 300 pesan terakhir
#   unduh 4            -> 4 koneksi, pindai 300
#   unduh 6 500        -> 6 koneksi, pindai 500 pesan
#
# Setelan lanjutan lewat environment:
#   TG_INFLIGHT_PER_CONN=1 unduh   -> 6 request bersamaan, bukan 12
#   TG_EXTRA_SOCKETS=0 unduh       -> matikan sender exported untuk DC lain
#   TG_REQUEST_TIMEOUT=60 unduh    -> tunggu socket beku maksimal 60 detik
#   TG_STALL_TIMEOUT=75 unduh      -> reset otomatis jika progress membeku
#   TG_RESET_PAUSE=0.5 unduh       -> jeda global saat socket diputus
#   TG_VERBOSE=1 unduh             -> tampilkan log Telethon apa adanya
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

export TG_PARALLEL_CONNECTIONS="${1:-6}"

# Berapa pesan terakhir yang dipindai untuk mencari video.
#
# 300 jadi bawaan karena itu yang dipakai sehari-hari; 50 terlalu sedikit
# dan membuat video lama tidak ikut terlihat. Bisa ditimpa lewat argumen
# kedua (unduh 6 500) atau lewat environment.
export TG_SCAN_LIMIT="${2:-${TG_SCAN_LIMIT:-300}}"

# Berapa request 1 MB yang boleh terbang bersamaan di TIAP koneksi.
#
# Default 2 -> maksimal 12 request bersamaan. Pada DC utama request tetap
# paralel, tetapi semuanya melewati sender resmi Telethon agar tidak ada
# dua receive-loop yang membaca socket sama.
#
# Limiter tetap mulai dari separuh (1 per soket) dan memanjat hanya
# kalau lancar. Pantau baris "[TG] Direm:" di akhir download.
export TG_INFLIGHT_PER_CONN="${TG_INFLIGHT_PER_CONN:-2}"

# Sender tambahan hanya dibuka melalui exported authorization resmi untuk
# media di DC lain. Pada DC utama mode clone tidak pernah aktif otomatis.
export TG_EXTRA_SOCKETS="${TG_EXTRA_SOCKETS:-1}"

# Jangan biarkan proxy warisan di shell membelokkan trafik keluar dari
# VPS. Skrip juga akan mencetak IP publiknya sendiri saat start.
unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy

cd /root

echo "==> Jalur paralel     : $TG_PARALLEL_CONNECTIONS"
echo "==> Request/koneksi   : $TG_INFLIGHT_PER_CONN"
echo "==> Total in-flight   : $((TG_PARALLEL_CONNECTIONS * TG_INFLIGHT_PER_CONN)) MB"
echo "==> Pesan dipindai    : $TG_SCAN_LIMIT"

exec "$VENV/bin/python3" "$SKRIP"
