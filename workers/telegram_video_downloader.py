import hashlib
import asyncio
import os
import sys
import re

import boto3
import requests
from botocore.config import Config
from telethon import TelegramClient

from fast_download import ParallelDownloader

# --- Ambil dari environment variable, JANGAN hardcode di sini ---
# Sebelum jalanin, set dulu di terminal:
#   export TG_API_ID=123456
#   export TG_API_HASH=abcdef...
API_ID = os.environ.get("TG_API_ID")
API_HASH = os.environ.get("TG_API_HASH")

DOWNLOAD_DIR = "./downloads"

# ==========================================
# Cloudflare R2 Configuration
# ==========================================

R2_ENDPOINT = "https://eca700cac8f9f9384e77cd568efb76d7.r2.cloudflarestorage.com"
R2_ACCESS_KEY = "ac825d85545ea4c82b6586be05f20ac8"
R2_SECRET_KEY = "8d5f304004fdf7cc9c6a372e007e78dc00652fd0e8a7be0225abc31e16532423"
R2_BUCKET = "dracinhub-storage"
R2_FOLDER = "telegram"

# ==========================================
# DramaVerse Laravel API
# ==========================================

LARAVEL_API_URL = "https://dracinverse.cloud/api/internal/video-inbox"
LARAVEL_API_TOKEN = os.environ.get("VIDEO_WORKER_TOKEN")

r2 = boto3.client(
    "s3",
    endpoint_url=R2_ENDPOINT,
    aws_access_key_id=R2_ACCESS_KEY,
    aws_secret_access_key=R2_SECRET_KEY,
    config=Config(signature_version="s3v4"),
)
def make_safe_filename(filename):
    """
    Normalisasi nama object R2 agar kompatibel dengan
    Laravel ObjectKey.
    """
    filename = os.path.basename(filename)

    stem, extension = os.path.splitext(filename)

    # Laravel Str::slug(): lowercase dan karakter selain
    # huruf/angka dipisahkan dengan tanda '-'.
    safe_stem = re.sub(r"[^a-z0-9]+", "-", stem.lower())
    safe_stem = safe_stem.strip("-")

    if not safe_stem:
        raise ValueError("Nama file tidak menghasilkan object key yang valid.")

    # ObjectKey Laravel membatasi stem ke 120 karakter.
    safe_stem = safe_stem[:120].rstrip("-")

    extension = extension.lower().lstrip(".")

    if extension:
        return f"{safe_stem}.{extension}"

    return safe_stem


def upload_to_r2(local_file):
    """
    Upload file ke Cloudflare R2.
    Mengembalikan key object yang berhasil di-upload.
    """

    filename = os.path.basename(local_file)
    stored_filename = make_safe_filename(filename)
    object_key = f"{R2_FOLDER}/{stored_filename}"

    print(f"[R2] Uploading {filename}...")
    print(f"[R2] Object key: {object_key}")

    r2.upload_file(
        local_file,
        R2_BUCKET,
        object_key,
    )

    print("[R2] Upload selesai.")

    return object_key
    r2.upload_file(
        local_file,
        R2_BUCKET,
        object_key,
    )

    print("[R2] Upload selesai.")

    return object_key

def calculate_sha256(local_file):
    sha256 = hashlib.sha256()

    with open(local_file, "rb") as file:
        while chunk := file.read(1024 * 1024):
            sha256.update(chunk)

    return sha256.hexdigest()

def sync_to_laravel(local_file, object_key, telegram_message_id=None):
    """
    Kirim metadata video yang sudah berada di R2 ke Laravel Video Inbox.
    """

    if not LARAVEL_API_TOKEN:
        raise RuntimeError(
            "VIDEO_WORKER_TOKEN belum tersedia di environment."
        )

    filename = os.path.basename(local_file)
    file_size = os.path.getsize(local_file)

    print("[CHECKSUM] Menghitung SHA-256...")
    checksum = calculate_sha256(local_file)
    print("[CHECKSUM] Selesai.")

    payload = {
        "provider_slug": "r2",
        "telegram_message_id": telegram_message_id,
        "original_filename": filename,
        "object_key": object_key,
        "mime_type": "video/mp4",
        "size": file_size,
        "checksum": checksum,
    }

    print("[LARAVEL] Menyinkronkan metadata video...")

    response = requests.post(
        LARAVEL_API_URL,
        json=payload,
        headers={
            "Authorization": f"Bearer {LARAVEL_API_TOKEN}",
            "Accept": "application/json",
        },
        timeout=30,
    )

    if not response.ok:
        raise RuntimeError(
            f"HTTP {response.status_code}: {response.text}"
        )

    data = response.json()

    print("[LARAVEL] Video berhasil masuk ke Video Inbox.")

    return data

# Berapa banyak pesan terakhir yang di-scan untuk cari video
SCAN_LIMIT = 50

# Berapa banyak koneksi paralel dipakai untuk download.
# Makin banyak, makin cepat (sampai batas tertentu), tapi juga makin berat.
# 4-8 biasanya titik optimal. Jangan set terlalu tinggi (>16) karena
# Telegram bisa mulai membatasi/menolak koneksi berlebihan.
PARALLEL_CONNECTIONS = 6


def progress_callback(current, total):
    percent = current * 100 / total
    bar_len = 30
    filled = int(bar_len * current / total)
    bar = "#" * filled + "-" * (bar_len - filled)
    mb_current = current / (1024 * 1024)
    mb_total = total / (1024 * 1024)
    print(f"\r[{bar}] {percent:5.1f}%  ({mb_current:.1f}/{mb_total:.1f} MB)", end="", flush=True)


def format_size(num_bytes):
    if not num_bytes:
        return "?"
    mb = num_bytes / (1024 * 1024)
    return f"{mb:.1f} MB"


async def main():
    if not API_ID or not API_HASH:
        print("ERROR: TG_API_ID / TG_API_HASH belum di-set sebagai environment variable.")
        print("Jalankan dulu:")
        print("  export TG_API_ID=xxxxxx")
        print("  export TG_API_HASH=xxxxxxxxxxxxxxxx")
        sys.exit(1)

    os.makedirs(DOWNLOAD_DIR, exist_ok=True)

    client = TelegramClient("telegram_session", int(API_ID), API_HASH)
    await client.start()
    print("Telegram berhasil terhubung.")

    bot_username = input("Masukkan username bot (contoh: @namabot atau namabot): ").strip().lstrip("@")

    try:
        entity = await client.get_entity(bot_username)
    except ValueError:
        print(f"Bot '{bot_username}' tidak ditemukan. Pastikan kamu sudah pernah chat dengan bot ini.")
        await client.disconnect()
        return

    print(f"Mengambil {SCAN_LIMIT} pesan terakhir dari chat bot...")

    videos = []
    async for message in client.iter_messages(entity, limit=SCAN_LIMIT):
        if message.video or (message.document and message.file and message.file.mime_type and "video" in message.file.mime_type):
            videos.append(message)

    if not videos:
        print("Tidak ada video ditemukan di chat bot ini.")
        await client.disconnect()
        return

    print(f"\nDitemukan {len(videos)} video:\n")
    for i, msg in enumerate(videos, start=1):
        name = msg.file.name if msg.file and msg.file.name else "(tanpa nama)"
        size = format_size(msg.file.size if msg.file else None)
        date = msg.date.strftime("%Y-%m-%d %H:%M")
        print(f"  [{i}] {name} — {size} — {date}")

    pilihan = input("\nPilih nomor video yang mau didownload (atau 'all' untuk semua): ").strip().lower()

    if pilihan == "all":
        selected = videos
    else:
        try:
            idx = int(pilihan)
            if idx < 1 or idx > len(videos):
                raise ValueError
            selected = [videos[idx - 1]]
        except ValueError:
            print("Input tidak valid.")
            await client.disconnect()
            return

    downloader = ParallelDownloader(client, num_connections=PARALLEL_CONNECTIONS)

    for i, message in enumerate(selected, start=1):
        print(f"\nMendownload video {i}/{len(selected)} (mode paralel, {PARALLEL_CONNECTIONS} koneksi)...")

        file_name = message.file.name if message.file and message.file.name else f"video_{message.id}.mp4"
        out_path = os.path.join(DOWNLOAD_DIR, file_name)

        try:
            path = await downloader.download(
                message,
                out_path,
                progress_callback=progress_callback,
            )
        except Exception as e:
            # Kalau mode paralel gagal (misal media bukan document biasa),
            # fallback ke cara download standar Telethon.
            print(f"\nMode paralel gagal ({e}), mencoba cara biasa...")
            path = await client.download_media(
                message,
                file=DOWNLOAD_DIR,
                progress_callback=progress_callback,
            )

        print(f"\nSelesai! File tersimpan di: {path}")

        try:
            object_key = upload_to_r2(path)
            print(f"[R2] File berhasil diupload: {object_key}")

        except Exception as e:
            print(f"[R2] Upload gagal: {e}")
            print("[LOCAL] File lokal tetap disimpan.")
            continue

        try:
            sync_to_laravel(
                path,
                object_key,
                telegram_message_id=message.id,
            )

        except Exception as e:
            print(f"[LARAVEL] Sinkronisasi gagal: {e}")
            print(f"[R2] Video tetap aman di R2: {object_key}")

        try:
            os.remove(path)
            print("[LOCAL] File lokal dihapus.")

        except OSError as e:
            print(f"[LOCAL] Gagal menghapus file lokal: {e}")
    await client.disconnect()


if __name__ == "__main__":
    asyncio.run(main())
