import asyncio
import hashlib
import mimetypes
import os
import platform
import re
import socket
import sys
import time

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

# Jumlah koneksi TCP paralel ke Telegram DC.
PARALLEL_CONNECTIONS = int(
    os.environ.get("TG_PARALLEL_CONNECTIONS", "4")
)

# Plafon jumlah koneksi. Default sama dengan titik awal, jadi angka di
# atas tidak pernah dilewati diam-diam.
MAX_PARALLEL = int(
    os.environ.get("TG_MAX_PARALLEL", str(PARALLEL_CONNECTIONS))
)

# Berapa request 1 MB yang boleh terbang bersamaan DI TIAP koneksi.
#
# Ini tombol kecepatan yang sebenarnya, bukan jumlah koneksi. Dengan
# 4 koneksi x 3 = 12 request bersamaan. Versi lama terkunci di 1 per
# koneksi, jadi tiap koneksi menganggur penuh selama satu perjalanan
# pulang-pergi ke DC.
#
# Turunkan ke 1 kalau akun sering kena flood; naikkan hati-hati.
INFLIGHT_PER_CONNECTION = int(
    os.environ.get("TG_INFLIGHT_PER_CONN", "3")
)

# Berapa kali satu video dicoba ulang sebelum menyerah. Percobaan
# berikutnya MELANJUTKAN dari file .part, bukan mengulang dari nol.
DOWNLOAD_ATTEMPTS = int(
    os.environ.get("TG_DOWNLOAD_ATTEMPTS", "3")
)

# Skrip ini dirancang mengunduh lewat jaringan VPS. Menjalankannya dari
# Windows berarti seluruh trafik lewat internet rumah/kantor, dan itu
# yang bikin terasa lama. Set TG_ALLOW_NON_LINUX=1 kalau memang sengaja.
ALLOW_NON_LINUX = os.environ.get("TG_ALLOW_NON_LINUX") == "1"


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
# Pemeriksaan jaringan
#
# Tujuannya satu: memastikan byte video mengalir lewat sambungan VPS,
# bukan lewat internet komputer pribadi.
# ============================================================

def assert_running_on_vps():
    if os.name == "posix":
        return

    print("========================================")
    print(" SALAH TEMPAT")
    print("========================================")
    print(
        f"Skrip ini terdeteksi jalan di {platform.system()}, "
        "bukan di VPS."
    )
    print(
        "Kalau dijalankan dari komputer sendiri, seluruh video "
        "ditarik lewat internet rumah lalu diupload lagi dari "
        "sana -- dua kali lipat kerja, dan jauh lebih lambat."
    )
    print()
    print("Jalankan di VPS:")
    print("    ssh root@<vps>")
    print("    unduh          # 4 koneksi paralel")
    print("    unduh 6        # 6 koneksi paralel")
    print()
    print(
        "Kalau memang sengaja mau jalan di sini, "
        "set TG_ALLOW_NON_LINUX=1."
    )
    print("========================================")

    sys.exit(1)


def detect_public_ip():
    """
    IP publik yang benar-benar dipakai keluar. Dicoba ke beberapa
    layanan supaya satu yang mati tidak bikin kelihatan gagal.
    """
    services = (
        "https://api.ipify.org",
        "https://ifconfig.me/ip",
        "https://icanhazip.com",
    )

    for url in services:
        try:
            response = requests.get(url, timeout=8)

            if response.ok:
                candidate = response.text.strip()

                if re.match(r"^[0-9a-fA-F.:]+$", candidate):
                    return candidate

        except requests.RequestException:
            continue

    return None


def print_network_banner():
    print("========================================")
    print(" JARINGAN")
    print("========================================")

    print(f"Host    : {socket.gethostname()}")
    print(f"Sistem  : {platform.system()} {platform.release()}")

    proxy_vars = [
        name
        for name in (
            "HTTP_PROXY",
            "HTTPS_PROXY",
            "ALL_PROXY",
            "http_proxy",
            "https_proxy",
            "all_proxy",
        )
        if os.environ.get(name)
    ]

    public_ip = detect_public_ip()

    if public_ip:
        print(f"IP keluar: {public_ip}")
        print(
            "Semua trafik Telegram dan upload storage keluar "
            "lewat IP ini."
        )
    else:
        print(
            "IP keluar: tidak terdeteksi "
            "(layanan pengecek tidak terjangkau)."
        )

    if proxy_vars:
        print()
        print(
            "PERINGATAN: ada proxy aktif di environment "
            f"({', '.join(proxy_vars)})."
        )
        print(
            "Upload storage akan lewat proxy itu, bukan langsung "
            "dari VPS."
        )

    print("========================================")


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

    started_at = time.monotonic()

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

    elapsed = max(time.monotonic() - started_at, 0.001)

    print(
        "[STORAGE] Upload selesai "
        f"({format_size(file_size)} dalam {elapsed:.1f}s, "
        f"{file_size / (1024 * 1024) / elapsed:.2f} MB/s)."
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
    """
    Cadangan saja. Jalur normal menghitung checksum SAMBIL mendownload
    (lihat parameter `hasher`), jadi file tidak perlu dibaca dua kali.
    """
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

    if not checksum:
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

class ProgressPrinter:
    """
    Cetak bar kemajuan, tapi tidak lebih sering dari `interval` detik.

    Versi lama mencetak setiap chunk 1 MB dengan flush=True. Untuk video
    1 GB itu 1000 kali tulis ke terminal, semuanya di dalam event loop
    -- dan lewat SSH tiap flush itu satu paket jaringan tersendiri.
    """

    def __init__(self, interval=0.2):
        self.interval = interval
        self.last_print = 0.0
        self.started_at = time.monotonic()

    def reset(self):
        self.last_print = 0.0
        self.started_at = time.monotonic()

    def __call__(self, current, total):
        if not total:
            return

        now = time.monotonic()

        is_last = current >= total

        if not is_last and (now - self.last_print) < self.interval:
            return

        self.last_print = now

        percent = current * 100 / total

        bar_len = 30
        filled = int(bar_len * current / total)

        bar = (
            "#" * filled
            + "-" * (bar_len - filled)
        )

        mb_current = current / (1024 * 1024)
        mb_total = total / (1024 * 1024)

        elapsed = max(now - self.started_at, 0.001)
        speed = mb_current / elapsed

        print(
            f"\r[{bar}] "
            f"{percent:5.1f}%  "
            f"({mb_current:.1f}/{mb_total:.1f} MB)  "
            f"{speed:5.2f} MB/s",
            end="",
            flush=True,
        )

        if is_last:
            print()


def format_size(num_bytes):
    if not num_bytes:
        return "?"

    mb = num_bytes / (1024 * 1024)

    if mb >= 1024:
        return f"{mb / 1024:.2f} GB"

    return f"{mb:.1f} MB"


def print_download_stats(stats):
    if not stats:
        return

    seconds = stats.get("seconds") or 0

    if seconds:
        mb = stats["bytes"] / (1024 * 1024)

        line = (
            f"[TG] {mb:.1f} MB dalam {seconds:.1f}s "
            f"({mb / seconds:.2f} MB/s)"
        )

        if stats.get("resumed_bytes"):
            resumed = stats["resumed_bytes"] / (1024 * 1024)

            line += f", {resumed:.1f} MB di antaranya hasil lanjutan"

        print(line + ".")

    print(
        f"[TG] Koneksi {stats.get('connections')}, "
        f"request bersamaan: mulai {stats.get('inflight_start')}, "
        f"terendah {stats.get('inflight_min')}, "
        f"tertinggi {stats.get('inflight_max')}, "
        f"akhir {stats.get('inflight_end')}."
    )

    if stats.get("flood_hits"):
        print(
            f"[TG] Kena flood {stats['flood_hits']}x "
            f"(total tunggu {stats['flood_seconds']}s)."
        )

    if stats.get("reconnects"):
        print(
            f"[TG] Koneksi diganti baru "
            f"{stats['reconnects']}x di tengah jalan."
        )


async def download_with_retry(
    downloader,
    client,
    message,
    out_path,
    progress,
):
    """
    Unduh satu video. Kembalikan (path, checksum_atau_None).

    Percobaan ulang MELANJUTKAN dari file .part yang tertinggal, jadi
    gangguan di menit ke-9 tidak membuang 9 menit pertama.
    """
    last_error = None

    for attempt in range(1, DOWNLOAD_ATTEMPTS + 1):
        hasher = hashlib.sha256()

        progress.reset()

        try:
            path = await downloader.download(
                message,
                out_path,
                progress_callback=progress,
                hasher=hasher,
            )

            print_download_stats(downloader.last_stats)

            return path, hasher.hexdigest()

        except Exception as error:
            last_error = error

            print(f"\n[TG] Percobaan {attempt} gagal: {error}")

            if attempt < DOWNLOAD_ATTEMPTS:
                pause = 3 * attempt

                print(
                    f"[TG] Menunggu {pause}s lalu melanjutkan "
                    "dari bagian yang sudah terunduh..."
                )

                await asyncio.sleep(pause)

    print(
        f"\n[TG] Mode paralel menyerah ({last_error}), "
        "mencoba cara biasa satu koneksi..."
    )

    ParallelDownloader.cleanup_partial(out_path)

    progress.reset()

    path = await client.download_media(
        message,
        file=DOWNLOAD_DIR,
        progress_callback=progress,
    )

    return path, None


# ============================================================
# Main
# ============================================================

async def main():
    if not ALLOW_NON_LINUX:
        assert_running_on_vps()

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

    print_network_banner()

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

    if getattr(client, "_proxy", None):
        print(
            "PERINGATAN: Telethon memakai proxy. Video tidak "
            "ditarik langsung lewat sambungan VPS."
        )

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
        max_connections=MAX_PARALLEL,
        inflight_per_connection=INFLIGHT_PER_CONNECTION,
    )

    progress = ProgressPrinter()

    print(
        f"\n[TG] {downloader.num_connections} koneksi, "
        f"maksimal {downloader.max_inflight} request 1 MB "
        "terbang bersamaan."
    )

    for index, message in enumerate(
        selected,
        start=1,
    ):
        print(
            f"\nMendownload video "
            f"{index}/{len(selected)}..."
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
            path, checksum = await download_with_retry(
                downloader,
                client,
                message,
                out_path,
                progress,
            )

        except Exception as error:
            print(
                f"\n[TG] Video ini gagal diunduh: {error}"
            )

            continue

        print(
            f"Selesai! File tersimpan di: {path}"
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
