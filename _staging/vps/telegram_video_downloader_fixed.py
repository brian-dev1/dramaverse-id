import asyncio
import hashlib
import mimetypes
import os
import re
import shutil
import sys
import time

import requests
from telethon import TelegramClient

from fast_download import NotEnoughDiskSpace, ParallelDownloader


# ============================================================
# Telegram
# ============================================================

API_ID = os.environ.get("TG_API_ID")
API_HASH = os.environ.get("TG_API_HASH")

# Semua path dibuat absolut relatif terhadap lokasi skrip, supaya
# hasilnya sama dari mana pun skrip dijalankan (cron, systemd, manual).
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

DOWNLOAD_DIR = os.environ.get(
    "TG_DOWNLOAD_DIR",
    os.path.join(BASE_DIR, "downloads"),
)

SESSION_PATH = os.environ.get(
    "TG_SESSION_PATH",
    os.path.join(BASE_DIR, "telegram_session"),
)

SCAN_LIMIT = int(os.environ.get("TG_SCAN_LIMIT", "50"))

# Default diturunkan dari 6 ke 4. Di VPS kecil, 6 koneksi bikin
# FLOOD_WAIT lebih sering dan I/O disk ikut tersendat.
PARALLEL_CONNECTIONS = int(os.environ.get("TG_PARALLEL_CONNECTIONS", "4"))

# Chunk in-flight per koneksi. Turunkan ke 1-2 kalau RAM VPS tipis.
BATCH_MULTIPLIER = int(os.environ.get("TG_BATCH_MULTIPLIER", "3"))

# Sisa disk yang harus tetap kosong setelah download (default 1 GB).
MIN_FREE_BYTES = int(
    os.environ.get("TG_MIN_FREE_BYTES", str(1024 * 1024 * 1024))
)


# ============================================================
# DramaVerse Laravel API
# ============================================================

LARAVEL_BASE_URL = os.environ.get(
    "LARAVEL_BASE_URL",
    "https://dracinverse.cloud/api/internal",
).rstrip("/")

UPLOAD_TARGET_URL = f"{LARAVEL_BASE_URL}/video-upload-target"
VIDEO_INBOX_URL = f"{LARAVEL_BASE_URL}/video-inbox"

LARAVEL_API_TOKEN = os.environ.get("VIDEO_WORKER_TOKEN")

UPLOAD_RETRIES = int(os.environ.get("UPLOAD_RETRIES", "3"))


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
    # Nama file dari Telegram sering mengandung spasi, tanda kurung,
    # atau karakter non-ASCII. Dibersihkan dulu supaya object key
    # di storage tetap rapi dan aman dipakai di URL.
    filename = make_safe_filename(local_file)
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
    headers = dict(upload.get("headers", {}))

    if method != "PUT":
        raise RuntimeError(
            f"Metode upload tidak didukung: {method}"
        )

    file_size = os.path.getsize(local_file)

    # Content-Length eksplisit: tanpa ini requests bisa memakai
    # chunked transfer-encoding, yang ditolak sebagian presigned URL S3.
    headers.setdefault("Content-Length", str(file_size))

    print(
        f"[STORAGE] Provider : {provider['name']}"
    )
    print(
        f"[STORAGE] Object   : {file_info['object_key']}"
    )
    print(
        f"[STORAGE] Uploading {filename} "
        f"({format_size(file_size)})..."
    )

    last_error = None

    for attempt in range(1, UPLOAD_RETRIES + 1):
        try:
            # File dibuka ulang tiap percobaan supaya pointer-nya
            # kembali ke awal. Dibaca streaming, tidak dimuat ke RAM.
            with open(local_file, "rb") as file:
                response = requests.put(
                    upload_url,
                    data=file,
                    headers=headers,
                    timeout=(30, 7200),
                )

            if response.ok:
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

            last_error = (
                f"HTTP {response.status_code}: "
                f"{response.text[:500]}"
            )

            # 4xx selain 408/429 tidak akan membaik kalau diulang.
            if (
                400 <= response.status_code < 500
                and response.status_code not in (408, 429)
            ):
                break

        except requests.RequestException as error:
            last_error = str(error)

        if attempt < UPLOAD_RETRIES:
            wait = 2 ** attempt

            print(
                f"[STORAGE] Percobaan {attempt} gagal "
                f"({last_error}). Ulangi dalam {wait}s..."
            )

            time.sleep(wait)

    raise RuntimeError(
        f"Upload storage gagal setelah {UPLOAD_RETRIES} "
        f"percobaan: {last_error}"
    )


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
    checksum=None,
):
    filename = os.path.basename(local_file)
    file_size = os.path.getsize(local_file)

    if checksum is None:
        # Fallback: hanya terjadi kalau download memakai mode biasa.
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

_last_progress_at = 0.0


def progress_callback(current, total):
    """
    Cetak progress maksimal 4x per detik. Versi lama mencetak tiap
    chunk 512 KB -- untuk file besar itu ribuan kali write ke stdout,
    yang ikut membebani CPU dan membanjiri log kalau output diarahkan
    ke file.
    """
    global _last_progress_at

    if not total:
        return

    now = time.monotonic()
    is_done = current >= total

    if not is_done and (now - _last_progress_at) < 0.25:
        return

    _last_progress_at = now

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


def free_disk_bytes(path):
    return shutil.disk_usage(path).free


def parse_selection(choice, total):
    """
    Terjemahkan input pengguna jadi daftar indeks (mulai dari 0).

    Format yang diterima:
        all       -> semua video
        3         -> video ke-3
        1-5       -> video 1 sampai 5
        1,3,7     -> video 1, 3, dan 7
        1-3,7,9-10 -> gabungan keduanya

    Duplikat dibuang, hasilnya selalu urut menaik.
    """
    choice = choice.strip().lower()

    if choice == "all":
        return list(range(total))

    if not choice:
        raise ValueError("Input kosong.")

    selected = set()

    for part in choice.split(","):
        part = part.strip()

        if not part:
            continue

        if "-" in part:
            start_text, _, end_text = part.partition("-")

            try:
                start = int(start_text.strip())
                end = int(end_text.strip())
            except ValueError:
                raise ValueError(
                    f"Rentang '{part}' tidak valid. "
                    "Contoh yang benar: 1-5"
                )

            if start > end:
                start, end = end, start

            if start < 1 or end > total:
                raise ValueError(
                    f"Rentang '{part}' di luar jangkauan "
                    f"(tersedia 1-{total})."
                )

            selected.update(range(start - 1, end))

            continue

        try:
            number = int(part)
        except ValueError:
            raise ValueError(
                f"'{part}' bukan angka."
            )

        if number < 1 or number > total:
            raise ValueError(
                f"Nomor {number} di luar jangkauan "
                f"(tersedia 1-{total})."
            )

        selected.add(number - 1)

    if not selected:
        raise ValueError("Tidak ada video yang dipilih.")

    return sorted(selected)


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

    print(
        f"\n[DISK] Sisa ruang: "
        f"{format_size(free_disk_bytes(DOWNLOAD_DIR))}"
    )

    client = TelegramClient(
        SESSION_PATH,
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

    print(
        "\nFormat pilihan: "
        "satu nomor (3), rentang (1-5), "
        "gabungan (1-3,7,9-10), atau 'all'."
    )

    while True:
        choice = input(
            "Pilih nomor video yang mau didownload: "
        )

        try:
            indexes = parse_selection(choice, len(videos))

            break

        except ValueError as error:
            print(f"  {error} Coba lagi.")

    selected = [videos[index] for index in indexes]

    total_size = sum(
        message.file.size
        for message in selected
        if message.file and message.file.size
    )

    print(
        f"\n{len(selected)} video dipilih "
        f"(total {format_size(total_size)})."
    )

    # File lokal dihapus tiap selesai satu video, jadi yang perlu
    # muat di disk hanya video terbesar -- bukan totalnya.
    largest = max(
        (
            message.file.size
            for message in selected
            if message.file and message.file.size
        ),
        default=0,
    )

    free_space = free_disk_bytes(DOWNLOAD_DIR)

    if largest + MIN_FREE_BYTES > free_space:
        print(
            "[DISK] Ruang tidak cukup untuk video terbesar "
            f"({format_size(largest)}). "
            f"Sisa disk: {format_size(free_space)}."
        )

        await client.disconnect()

        return

    downloader = ParallelDownloader(
        client,
        num_connections=PARALLEL_CONNECTIONS,
        batch_multiplier=BATCH_MULTIPLIER,
        min_free_bytes=MIN_FREE_BYTES,
    )

    for index, message in enumerate(
        selected,
        start=1,
    ):
        print(
            f"\nMendownload video "
            f"{index}/{len(selected)} "
            f"(mode paralel, "
            f"{downloader.num_connections} koneksi)..."
        )

        file_name = (
            message.file.name
            if message.file
            and message.file.name
            else f"video_{message.id}.mp4"
        )

        out_path = os.path.join(
            DOWNLOAD_DIR,
            os.path.basename(file_name),
        )

        # Checksum dihitung sambil menulis file, jadi tidak perlu
        # membaca ulang seluruh video setelah upload.
        hasher = hashlib.sha256()
        checksum = None

        try:
            path = await downloader.download(
                message,
                out_path,
                progress_callback=progress_callback,
                hasher=hasher,
            )

            checksum = hasher.hexdigest()

        except NotEnoughDiskSpace as error:
            # Ini bukan masalah yang bisa diselamatkan dengan
            # fallback -- disk memang penuh.
            print(f"\n[DISK] {error}")

            break

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

            # Mode fallback tidak menghitung checksum, jadi
            # sync_to_laravel yang akan menghitungnya nanti.
            checksum = None

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
                checksum=checksum,
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
                "[LOCAL] File lokal dihapus. "
                f"Sisa disk: "
                f"{format_size(free_disk_bytes(DOWNLOAD_DIR))}"
            )

        except OSError as error:
            print(
                "[LOCAL] Gagal menghapus "
                f"file lokal: {error}"
            )

    await client.disconnect()


if __name__ == "__main__":
    asyncio.run(main())
