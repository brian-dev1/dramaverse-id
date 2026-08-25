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
#   unduh                          -> 6 koneksi paralel
#   unduh 4                        -> 4 koneksi paralel
#   TG_SCAN_LIMIT=300 unduh        -> pindai 300 pesan terakhir
#   TG_INFLIGHT_PER_CONN=3 unduh   -> lebih agresif (lihat catatan)
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
export TG_SCAN_LIMIT="${TG_SCAN_LIMIT:-50}"

# Berapa request 1 MB yang boleh terbang bersamaan di TIAP koneksi.
#
# Default 2 -> 6 soket x 2 = 12 request bersamaan, tersebar di soket
# yang benar-benar terpisah.
#
# Angka ini aman sekarang karena sambungan tambahan dibuat lewat
# _create_exported_sender: tiap soket punya auth key SENDIRI. Waktu 12
# request dulu memicu banjir "connection reset by peer", soketnya masih
# meminjam auth_key koneksi utama -- itu yang dihukum Telegram, bukan
# jumlah requestnya.
#
# Limiter tetap mulai dari separuh (1 per soket) dan memanjat hanya
# kalau lancar. Pantau baris "[TG] Direm:" di akhir download.
export TG_INFLIGHT_PER_CONN="${TG_INFLIGHT_PER_CONN:-2}"

# Jangan biarkan proxy warisan di shell membelokkan trafik keluar dari
# VPS. Skrip juga akan mencetak IP publiknya sendiri saat start.
unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy

cd /root

echo "==> Koneksi paralel   : $TG_PARALLEL_CONNECTIONS"
echo "==> Request/koneksi   : $TG_INFLIGHT_PER_CONN"
echo "==> Total in-flight   : $((TG_PARALLEL_CONNECTIONS * TG_INFLIGHT_PER_CONN)) MB"
echo "==> Pesan dipindai    : $TG_SCAN_LIMIT"

exec "$VENV/bin/python3" "$SKRIP"
