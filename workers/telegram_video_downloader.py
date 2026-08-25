import asyncio
import hashlib
import logging
import mimetypes
import os
import platform
import re
import socket
import sys
import time

import requests
from telethon import TelegramClient
from telethon.errors import ApiIdInvalidError

from fast_download import ParallelDownloader


# ============================================================
# Telegram
# ============================================================

API_ID = os.environ.get("TG_API_ID")
API_HASH = os.environ.get("TG_API_HASH")

# Semua jalur dipatok ke folder skrip ini, BUKAN ke direktori kerja.
#
# Telethon menyimpan sesi login sebagai "<nama>.session" relatif ke
# direktori kerja. Selama skrip dijalankan lewat `unduh` (yang ber-`cd`
# ke /root) itu tidak kelihatan bermasalah -- tapi begitu skrip
# dipanggil langsung dari folder lain, Telethon tidak menemukan sesi
# lama, menganggap ini login pertama, dan mulai meminta nomor HP.
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

SESSION_PATH = os.environ.get(
    "TG_SESSION",
    os.path.join(BASE_DIR, "telegram_session"),
)

DOWNLOAD_DIR = os.environ.get(
    "TG_DOWNLOAD_DIR",
    os.path.join(BASE_DIR, "downloads"),
)

# Berapa pesan terakhir di chat bot yang dipindai untuk mencari video.
SCAN_LIMIT = int(
    os.environ.get("TG_SCAN_LIMIT", "50")
)

# Jumlah koneksi TCP paralel ke Telegram DC.
PARALLEL_CONNECTIONS = int(
    os.environ.get("TG_PARALLEL_CONNECTIONS", "6")
)

# Plafon jumlah koneksi. Default sama dengan titik awal, jadi angka di
# atas tidak pernah dilewati diam-diam.
MAX_PARALLEL = int(
    os.environ.get("TG_MAX_PARALLEL", str(PARALLEL_CONNECTIONS))
)

# Berapa request 1 MB yang boleh terbang bersamaan DI TIAP koneksi.
#
# Default 2 -> 6 soket x 2 = 12 request bersamaan.
#
# Sambungan tambahan sekarang dibuat lewat _create_exported_sender, jadi
# tiap soket punya auth key sendiri. Yang dulu memicu banjir "connection
# reset by peer" bukan jumlah requestnya, melainkan soket yang meminjam
# auth_key koneksi utama.
INFLIGHT_PER_CONNECTION = int(
    os.environ.get("TG_INFLIGHT_PER_CONN", "2")
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
# Peredam log Telethon
# ============================================================

class PeredamKoneksi(logging.Filter):
    """
    Kumpulkan pesan putus-sambung Telethon jadi satu angka.

    Telethon mencatat tiap sambungan yang diputus server, lalu
    menyambungnya kembali sendiri. Untuk unduhan bermulti-soket, itu
    puluhan baris per menit yang menenggelamkan bar kemajuan -- padahal
    tidak satu pun byte hilang karenanya: chunk yang gagal diulang, dan
    berkasnya diverifikasi checksum.

    Yang dilakukan di sini BUKAN menyembunyikannya. Pesannya dihitung
    dan jumlahnya dilaporkan di akhir tiap video, jadi kalau angkanya
    membengkak Anda tetap tahu. Set TG_VERBOSE=1 untuk melihat aslinya
    satu per satu.
    """

    POLA = (
        "server closed the connection",
        "connection reset",
        "broken pipe",
        "wrong session id",
        "0 bytes read",
    )

    def __init__(self):
        super().__init__()
        self.jumlah = 0

    def filter(self, record):
        try:
            pesan = record.getMessage().lower()
        except Exception:
            return True

        if any(pola in pesan for pola in self.POLA):
            self.jumlah += 1

            return False

        return True

    def ambil(self):
        jumlah = self.jumlah
        self.jumlah = 0

        return jumlah


PEREDAM = PeredamKoneksi()


def pasang_peredam():
    if os.environ.get("TG_VERBOSE") == "1":
        return False

    logging.getLogger("telethon").addFilter(PEREDAM)

    return True


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


def validate_api_credentials(session_exists):
    """
    Periksa bentuk TG_API_ID / TG_API_HASH sebelum menyentuh jaringan.

    Telegram HANYA memvalidasi pasangan ini saat LOGIN. Selama file sesi
    masih sah, kredensial yang salah sama sekali tidak dipakai -- dan
    karena itu pemeriksaan ini TIDAK boleh menghentikan skrip selama
    sesinya ada. Menolak jalan dalam keadaan itu berarti memblokir
    pekerjaan yang sebenarnya bisa berjalan sempurna.

    Yang benar: kalau sesi ada, cukup diperingatkan -- ini ranjau yang
    baru meledak saat sesi mati. Kalau sesi tidak ada, login pasti
    terjadi, dan di situ kredensial yang salah memang menggagalkan
    semuanya; barulah berhenti lebih awal itu menolong.
    """
    problems = []

    if not (API_ID or "").strip().isdigit():
        problems.append(
            "TG_API_ID harus angka saja "
            f"(sekarang: {len(API_ID or '')} karakter, bukan angka murni)."
        )

    api_hash = (API_HASH or "").strip()

    if not re.fullmatch(r"[0-9a-fA-F]{32}", api_hash):
        problems.append(
            "TG_API_HASH harus 32 karakter heksadesimal "
            f"(sekarang: {len(api_hash)} karakter)."
        )

    if not problems:
        return

    print()
    print("========================================")

    if session_exists:
        print(" PERINGATAN: KREDENSIAL API BERMASALAH")
    else:
        print(" KREDENSIAL API TELEGRAM TIDAK VALID")

    print("========================================")

    for problem in problems:
        print(f"- {problem}")

    print()

    if session_exists:
        print(
            "Sesi login masih ada, jadi kredensial ini TIDAK dipakai "
            "sekarang dan skrip tetap jalan."
        )
        print(
            "Tapi begitu sesi itu mati, login berikutnya pasti gagal. "
            "Betulkan saat sempat."
        )
        print("========================================")
        print()

        return

    print(
        "Tidak ada file sesi, jadi login pasti terjadi -- dan dengan "
        "kredensial ini login itu pasti ditolak."
    )
    print()
    print(
        "Ambil keduanya berpasangan dari https://my.telegram.org "
        "-> API development tools."
    )
    print(
        "api_id dan api_hash HARUS dari akun yang sama; menukar salah "
        "satunya membuat pasangannya ditolak."
    )
    print("========================================")

    sys.exit(1)


def describe_crypto():
    """
    Pastikan AES-nya dikerjakan kode terkompilasi, bukan Python murni.

    Telethon jalan tanpa `cryptg`, hanya saja seluruh enkripsi jatuh ke
    implementasi Python murni. Tidak ada galat, tidak ada peringatan --
    yang terjadi cuma satu inti CPU terpakai penuh dan kecepatan mentok
    di sekitar 1-2 MB/s berapa pun koneksi yang dibuka.

    Ini paling menyesatkan justru karena diam. Gejalanya terlihat persis
    seperti masalah jaringan, dan menambah paralelisme sama sekali tidak
    menolong karena hambatannya ada di CPU satu proses.
    """
    try:
        import cryptg  # noqa: F401

        versi = getattr(cryptg, "__version__", "?")

        print(f"Enkripsi: cryptg {versi} (terkompilasi)")

        return True

    except ImportError:
        pass

    print("Enkripsi: PYTHON MURNI -- ini akan sangat lambat.")
    print()
    print("  cryptg tidak terpasang, jadi setiap byte didekripsi oleh")
    print("  Python. Kecepatan mentok di sekitar 1-2 MB/s dan satu inti")
    print("  CPU terpakai penuh, berapa pun koneksi yang dibuka.")
    print()
    print("  Pasang:")
    print(f"      {sys.executable} -m pip install cryptg")

    return False


def describe_session():
    """
    Beri tahu di awal apakah sesi login lama ditemukan.

    Kalau tidak ada, Telethon akan minta nomor HP -- dan lebih baik itu
    terbaca sebagai keterangan di awal daripada mengagetkan di tengah.
    """
    session_file = SESSION_PATH + ".session"

    print(f"Sesi    : {session_file}")

    if os.path.exists(session_file):
        size = os.path.getsize(session_file)

        print(f"          ditemukan ({size} byte), login dilewati.")

        return True

    print(
        "          TIDAK ADA -- Telethon akan meminta nomor HP "
        "dan kode OTP."
    )

    return False


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
    print(f"Folder  : {BASE_DIR}")

    session_exists = describe_session()

    describe_crypto()

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

    return session_exists


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

    unique = stats.get("unique_senders")
    asked = stats.get("connections_asked") or stats.get("connections")

    penjelasan = {
        "exported": "soket berdiri sendiri, masing-masing auth key sendiri",
        "main": "sender milik client -- sambungan tambahan tidak tersedia",
        "borrow": "satu sambungan pinjaman bersama (video di DC lain)",
        "clone": "soket kloningan, meminjam auth_key koneksi utama",
    }.get(stats.get("mode"), stats.get("mode"))

    if unique and asked and unique != asked:
        koneksi = f"{unique} dari {asked} diminta"
    else:
        koneksi = f"{unique}"

    print(
        f"[TG] Koneksi {koneksi} ({penjelasan}). "
        f"Request bersamaan: mulai {stats.get('inflight_start')}, "
        f"terendah {stats.get('inflight_min')}, "
        f"tertinggi {stats.get('inflight_max')}, "
        f"akhir {stats.get('inflight_end')}."
    )

    if stats.get("flood_hits") or stats.get("reset_hits"):
        print(
            f"[TG] Direm: flood {stats.get('flood_hits', 0)}x, "
            f"koneksi diputus server {stats.get('reset_hits', 0)}x "
            f"(total tunggu {stats['flood_seconds']}s)."
        )

    if stats.get("reset_hits", 0) > 20:
        print(
            "[TG] Koneksi diputus terlalu sering. Kalau ini berulang, "
            "coba TG_INFLIGHT_PER_CONN=1 -- dan pastikan tidak ada "
            "proses downloader lain yang jalan bersamaan."
        )

    for alasan in stats.get("open_errors", []):
        print(f"[TG] Sambungan gagal dibuka -> {alasan}")

    if stats.get("reference_refreshes"):
        print(
            f"[TG] file_reference diperbarui "
            f"{stats['reference_refreshes']}x di tengah jalan."
        )

    if stats.get("reconnects"):
        print(
            f"[TG] Koneksi diganti baru "
            f"{stats['reconnects']}x di tengah jalan."
        )


async def download_with_retry(
    downloader,
    client,
    entity,
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

    def lapor_koneksi(mode, soket, kesalahan):
        penjelasan = {
            "exported": "soket berdiri sendiri, auth key masing-masing",
            "main": "sender milik client -- sambungan tambahan DITOLAK",
            "borrow": "satu sambungan pinjaman bersama (DC lain)",
            "clone": "soket kloningan, meminjam auth_key utama",
        }.get(mode, mode)

        print(f"[TG] Terbuka {soket} soket ({penjelasan}).")

        for alasan in kesalahan:
            print(f"[TG] Gagal membuka -> {alasan}")

    async def ambil_pesan_baru():
        """
        Ambil ulang pesannya untuk mendapat file_reference yang segar.

        Daftar video dipindai sekali di awal, lalu diunduh satu per satu.
        Video terakhir bisa baru mulai berjam-jam kemudian, dan bukti
        kepemilikan dokumennya sudah basi jauh sebelum itu. Dipanggil oleh
        modul unduh begitu Telegram menolak dengan FILE_REFERENCE_EXPIRED.
        """
        return await client.get_messages(entity, ids=message.id)

    for attempt in range(1, DOWNLOAD_ATTEMPTS + 1):
        hasher = hashlib.sha256()

        progress.reset()

        try:
            path = await downloader.download(
                message,
                out_path,
                progress_callback=progress,
                hasher=hasher,
                refresh_message=ambil_pesan_baru,
                on_connect=lapor_koneksi,
            )

            print_download_stats(downloader.last_stats)

            diredam = PEREDAM.ambil()

            if diredam:
                print(
                    f"[TG] {diredam} pesan putus-sambung Telethon "
                    "diredam (tidak ada byte yang hilang; "
                    "TG_VERBOSE=1 untuk melihatnya)."
                )

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

    # Jalur cadangan juga butuh referensi yang segar. Memakai objek pesan
    # lama di sini berarti mengulang kegagalan yang sama dengan satu
    # koneksi -- lebih lambat, sama-sama gagal.
    try:
        segar = await client.get_messages(entity, ids=message.id)

        if segar is not None and segar.document is not None:
            message = segar

    except Exception as error:
        print(f"[TG] Gagal mengambil ulang pesan: {error}")

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

    # Banner dulu supaya status sesi terbaca, baru kredensial dinilai --
    # karena penilaiannya memang bergantung pada ada tidaknya sesi.
    session_exists = print_network_banner()

    validate_api_credentials(session_exists)

    if pasang_peredam():
        print(
            "[TG] Pesan putus-sambung Telethon diredam dan dihitung. "
            "TG_VERBOSE=1 untuk menampilkannya."
        )

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
        SESSION_PATH,
        int(API_ID),
        API_HASH,
    )

    try:
        await client.start()

    except ApiIdInvalidError:
        print()
        print("========================================")
        print(" LOGIN DITOLAK: api_id/api_hash SALAH")
        print("========================================")
        print(
            "Telegram hanya memeriksa pasangan ini saat LOGIN. Selama "
            "file sesi masih ada, kredensial yang salah tidak pernah "
            "ketahuan -- jadi error ini biasanya muncul bukan karena "
            "kredensialnya baru rusak, tapi karena sesinya baru hilang."
        )
        print()
        print(f"Sesi yang dicari : {SESSION_PATH}.session")
        print()
        print("Periksa dua hal, berurutan:")
        print()
        print("1. Sesi lamanya masih ada di tempat lain?")
        print("     find / -name 'telegram_session.session' 2>/dev/null")
        print(
            "   Kalau ketemu, salin ke sebelah skrip ini "
            f"({BASE_DIR}/) lalu ulangi."
        )
        print()
        print("2. Kalau sesinya memang sudah tidak ada, kredensialnya")
        print("   harus benar. Ambil ulang berpasangan dari")
        print("   https://my.telegram.org -> API development tools,")
        print("   lalu set keduanya sekaligus:")
        print()
        print("     export TG_API_ID=<angka>")
        print("     export TG_API_HASH=<32 karakter hex>")
        print("========================================")

        await client.disconnect()

        sys.exit(1)

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
                entity,
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
