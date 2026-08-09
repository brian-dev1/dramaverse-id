import asyncio
import hashlib
import mimetypes
import os
import re
import sys

import requests
from telethon import TelegramClient

from fast_download import ParallelDownloader


# ============================================================
# Telegram
# ============================================================

API_ID = os.environ.get("TG_API_ID")
API_HASH = os.environ.get("TG_API_HASH")

DOWNLOAD_DIR = "./downloads"

SCAN_LIMIT = 50
PARALLEL_CONNECTIONS = 6


# ============================================================
# DramaVerse Laravel API
# ============================================================

LARAVEL_BASE_URL = "https://dracinverse.cloud/api/internal"

UPLOAD_TARGET_URL = f"{LARAVEL_BASE_URL}/video-upload-target"
VIDEO_INBOX_URL = f"{LARAVEL_BASE_URL}/video-inbox"

LARAVEL_API_TOKEN = os.environ.get("VIDEO_WORKER_TOKEN")


def api_headers():
    if not LARAVEL_API_TOKEN:
        raise RuntimeError(
            "VIDEO_WORKER_TOKEN belum tersedia di environment."
        )

    return {
        "Authorization": f"Bearer {LARAVEL_API_TOKEN}",
        "Accept": "application/json",
    }


# ============================================================
# Filename
# ============================================================

def make_safe_filename(filename):
    filename = os.path.basename(filename)

    stem, extension = os.path.splitext(filename)

    safe_stem = re.sub(
        r"[^a-z0-9]+",
        "-",
        stem.lower(),
    ).strip("-")

    if not safe_stem:
        raise ValueError(
            "Nama file tidak menghasilkan object key yang valid."
        )

    safe_stem = safe_stem[:120].rstrip("-")

    extension = extension.lower().lstrip(".")

    if extension:
        return f"{safe_stem}.{extension}"

    return safe_stem


def detect_mime_type(filename):
    mime_type, _ = mimetypes.guess_type(filename)

    return mime_type or "video/mp4"


# ============================================================
# Storage Provider
# ============================================================

def get_storage_providers():
    print("\n[STORAGE] Mengambil daftar storage dari DramaVerse...")

    response = requests.get(
        UPLOAD_TARGET_URL,
        headers=api_headers(),
        timeout=30,
    )

    if not response.ok:
        raise RuntimeError(
            f"HTTP {response.status_code}: {response.text}"
        )

    data = response.json()

    return (
        data.get("default_provider"),
        data.get("providers", []),
    )


def choose_storage_provider():
    default_slug, providers = get_storage_providers()

    if not providers:
        raise RuntimeError(
            "Tidak ada storage provider yang tersedia."
        )

    print("\n========================================")
    print(" STORAGE PROVIDER")
    print("========================================")

    default_name = next(
        (
            provider["name"]
            for provider in providers
            if provider["slug"] == default_slug
        ),
        default_slug or "-",
    )

    print(f"[1] Auto (Default: {default_name})")

    for index, provider in enumerate(
        providers,
        start=2,
    ):
        marker = " [DEFAULT]" if (
            provider["slug"] == default_slug
        ) else ""

        print(
            f"[{index}] "
            f"{provider['name']} "
            f"({provider['driver']})"
            f"{marker}"
        )

    print("========================================")

    while True:
        choice = input("\nPilih storage: ").strip()

        try:
            choice_number = int(choice)
        except ValueError:
            print("Pilihan tidak valid.")
            continue

        if choice_number == 1:
            print(
                f"[STORAGE] Mode Auto -> {default_name}"
            )

            return "auto"

        provider_index = choice_number - 2

        if 0 <= provider_index < len(providers):
            provider = providers[provider_index]

            print(
                "[STORAGE] Dipilih: "
                f"{provider['name']}"
            )

            return provider["slug"]

        print("Pilihan tidak valid.")


# ============================================================
# Upload Target
# ============================================================

def create_upload_target(
    provider_slug,
    filename,
    mime_type,
):
    print(
        "[STORAGE] Meminta URL upload sementara..."
    )

    response = requests.post(
        UPLOAD_TARGET_URL,
        json={
            "provider_slug": provider_slug,
            "filename": filename,
            "mime_type": mime_type,
        },
        headers={
            **api_headers(),
            "Content-Type": "application/json",
        },
        timeout=30,
    )

    if not response.ok:
        raise RuntimeError(
            f"HTTP {response.status_code}: {response.text}"
        )

    data = response.json()

    if (
        "provider" not in data
        or "file" not in data
        or "upload" not in data
    ):
        raise RuntimeError(
            "Response upload target dari Laravel tidak valid."
        )

    return data


def upload_to_storage(
    local_file,
    provider_slug,
):
    filename = os.path.basename(local_file)
    mime_type = detect_mime_type(filename)

    target = create_upload_target(
        provider_slug,
        filename,
        mime_type,
    )

    provider = target["provider"]
    file_info = target["file"]
    upload = target["upload"]

    upload_url = upload["url"]
    method = upload.get("method", "PUT").upper()
    headers = upload.get("headers", {})

    if method != "PUT":
        raise RuntimeError(
            f"Metode upload tidak didukung: {method}"
        )

    print(
        f"[STORAGE] Provider : {provider['name']}"
    )
    print(
        f"[STORAGE] Object   : {file_info['object_key']}"
    )
    print(
        f"[STORAGE] Uploading {filename}..."
    )

    file_size = os.path.getsize(local_file)

    with open(local_file, "rb") as file:
        response = requests.put(
            upload_url,
            data=file,
            headers=headers,
            timeout=(30, 7200),
        )

    if not response.ok:
        raise RuntimeError(
            "Upload storage gagal "
            f"(HTTP {response.status_code}): "
            f"{response.text[:500]}"
        )

    print(
        "[STORAGE] Upload selesai "
        f"({format_size(file_size)})."
    )

    return {
        "provider_slug": provider["slug"],
        "object_key": file_info["object_key"],
        "stored_filename": file_info["stored_filename"],
        "mime_type": file_info["mime_type"],
    }


# ============================================================
# Checksum
# ============================================================

def calculate_sha256(local_file):
    sha256 = hashlib.sha256()

    with open(local_file, "rb") as file:
        while True:
            chunk = file.read(1024 * 1024)

            if not chunk:
                break

            sha256.update(chunk)

    return sha256.hexdigest()


# ============================================================
# Video Inbox
# ============================================================

def sync_to_laravel(
    local_file,
    upload_result,
    telegram_message_id=None,
):
    filename = os.path.basename(local_file)
    file_size = os.path.getsize(local_file)

    print("[CHECKSUM] Menghitung SHA-256...")

    checksum = calculate_sha256(local_file)

    print("[CHECKSUM] Selesai.")

    payload = {
        "provider_slug": upload_result["provider_slug"],
        "telegram_message_id": telegram_message_id,
        "original_filename": filename,
        "object_key": upload_result["object_key"],
        "mime_type": upload_result["mime_type"],
        "size": file_size,
        "checksum": checksum,
    }

    print(
        "[LARAVEL] Mengirim video ke Video Inbox..."
    )

    response = requests.post(
        VIDEO_INBOX_URL,
        json=payload,
        headers={
            **api_headers(),
            "Content-Type": "application/json",
        },
        timeout=30,
    )

    if not response.ok:
        raise RuntimeError(
            f"HTTP {response.status_code}: {response.text}"
        )

    data = response.json()

    print(
        "[LARAVEL] Video berhasil masuk ke Video Inbox."
    )

    return data


# ============================================================
# Helpers
# ============================================================

def progress_callback(current, total):
    if not total:
        return

    percent = current * 100 / total

    bar_len = 30
    filled = int(bar_len * current / total)

    bar = (
        "#" * filled
        + "-" * (bar_len - filled)
    )

    mb_current = current / (1024 * 1024)
    mb_total = total / (1024 * 1024)

    print(
        f"\r[{bar}] "
        f"{percent:5.1f}%  "
        f"({mb_current:.1f}/{mb_total:.1f} MB)",
        end="",
        flush=True,
    )


def format_size(num_bytes):
    if not num_bytes:
        return "?"

    mb = num_bytes / (1024 * 1024)

    if mb >= 1024:
        return f"{mb / 1024:.2f} GB"

    return f"{mb:.1f} MB"


# ============================================================
# Main
# ============================================================

async def main():
    if not API_ID or not API_HASH:
        print(
            "ERROR: TG_API_ID / TG_API_HASH "
            "belum di-set sebagai environment variable."
        )
        sys.exit(1)

    if not LARAVEL_API_TOKEN:
        print(
            "ERROR: VIDEO_WORKER_TOKEN "
            "belum di-set sebagai environment variable."
        )
        sys.exit(1)

    try:
        selected_provider = choose_storage_provider()
    except Exception as error:
        print(
            f"\n[STORAGE] Gagal mengambil storage: {error}"
        )
        return

    os.makedirs(
        DOWNLOAD_DIR,
        exist_ok=True,
    )

    client = TelegramClient(
        "telegram_session",
        int(API_ID),
        API_HASH,
    )

    await client.start()

    print("\nTelegram berhasil terhubung.")

    bot_username = input(
        "Masukkan username bot "
        "(contoh: @namabot atau namabot): "
    ).strip().lstrip("@")

    try:
        entity = await client.get_entity(
            bot_username
        )

    except ValueError:
        print(
            f"Bot '{bot_username}' tidak ditemukan. "
            "Pastikan kamu sudah pernah chat "
            "dengan bot ini."
        )

        await client.disconnect()

        return

    print(
        f"Mengambil {SCAN_LIMIT} pesan terakhir "
        "dari chat bot..."
    )

    videos = []

    async for message in client.iter_messages(
        entity,
        limit=SCAN_LIMIT,
    ):
        is_video = bool(message.video)

        is_video_document = bool(
            message.document
            and message.file
            and message.file.mime_type
            and "video" in message.file.mime_type
        )

        if is_video or is_video_document:
            videos.append(message)

    if not videos:
        print(
            "Tidak ada video ditemukan di chat bot ini."
        )

        await client.disconnect()

        return

    print(
        f"\nDitemukan {len(videos)} video:\n"
    )

    for index, message in enumerate(
        videos,
        start=1,
    ):
        name = (
            message.file.name
            if message.file and message.file.name
            else "(tanpa nama)"
        )

        size = format_size(
            message.file.size
            if message.file
            else None
        )

        date = message.date.strftime(
            "%Y-%m-%d %H:%M"
        )

        print(
            f"  [{index}] "
            f"{name} — {size} — {date}"
        )

    choice = input(
        "\nPilih nomor video yang mau didownload "
        "(atau 'all' untuk semua): "
    ).strip().lower()

    if choice == "all":
        selected = videos

    else:
        try:
            index = int(choice)

            if (
                index < 1
                or index > len(videos)
            ):
                raise ValueError

            selected = [
                videos[index - 1]
            ]

        except ValueError:
            print("Input tidak valid.")

            await client.disconnect()

            return

    downloader = ParallelDownloader(
        client,
        num_connections=PARALLEL_CONNECTIONS,
    )

    for index, message in enumerate(
        selected,
        start=1,
    ):
        print(
            f"\nMendownload video "
            f"{index}/{len(selected)} "
            f"(mode paralel, "
            f"{PARALLEL_CONNECTIONS} koneksi)..."
        )

        file_name = (
            message.file.name
            if message.file
            and message.file.name
            else f"video_{message.id}.mp4"
        )

        out_path = os.path.join(
            DOWNLOAD_DIR,
            file_name,
        )

        try:
            path = await downloader.download(
                message,
                out_path,
                progress_callback=progress_callback,
            )

        except Exception as error:
            print(
                "\nMode paralel gagal "
                f"({error}), mencoba cara biasa..."
            )

            path = await client.download_media(
                message,
                file=DOWNLOAD_DIR,
                progress_callback=progress_callback,
            )

        print(
            f"\nSelesai! File tersimpan di: {path}"
        )

        try:
            upload_result = upload_to_storage(
                path,
                selected_provider,
            )

            print(
                "[STORAGE] File berhasil diupload: "
                f"{upload_result['object_key']}"
            )

        except Exception as error:
            print(
                f"[STORAGE] Upload gagal: {error}"
            )
            print(
                "[LOCAL] File lokal tetap disimpan."
            )

            continue

        try:
            sync_to_laravel(
                path,
                upload_result,
                telegram_message_id=message.id,
            )

        except Exception as error:
            print(
                f"[LARAVEL] Sinkronisasi gagal: {error}"
            )
            print(
                "[STORAGE] Video tetap aman di "
                f"{upload_result['provider_slug']}: "
                f"{upload_result['object_key']}"
            )

            # Jangan hapus file lokal jika metadata gagal
            # masuk ke Video Inbox.
            continue

        try:
            os.remove(path)

            print(
                "[LOCAL] File lokal dihapus."
            )

        except OSError as error:
            print(
                "[LOCAL] Gagal menghapus "
                f"file lokal: {error}"
            )

    await client.disconnect()


if __name__ == "__main__":
    asyncio.run(main())