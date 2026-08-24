"""
Uji ParallelDownloader dengan sender tiruan.

Tidak menyentuh Telegram sama sekali: sender palsu mengembalikan byte
deterministik dengan latensi buatan, dan bisa disuruh melempar flood,
timeout, atau koneksi putus di offset tertentu.
"""

import asyncio
import hashlib
import os
import random
import shutil
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import fast_download
from fast_download import ParallelDownloader

try:
    from telethon.errors import FloodPremiumWaitError
except ImportError:
    # Telethon lama belum punya kelas ini. fast_download memang
    # dirancang mengenali flood dari teksnya juga, jadi pakai itu.
    from telethon.errors import FloodWaitError as FloodPremiumWaitError


RTT = 0.05  # latensi buatan per request, detik


# Buffer pola sekali bikin. Kalau tiap chunk dibangun ulang lewat
# loop Python, harness-nya sendiri makan ~50 ms CPU per MB dan itu
# yang jadi penghambat, bukan downloader-nya.
_PATTERN = bytes(range(251)) * (((4 * 1024 * 1024) // 251) + 2)


def payload(offset, length):
    """Byte deterministik supaya urutan bisa diverifikasi."""
    start = (offset // 4096) % 251

    return _PATTERN[start:start + length]


class FakeResult:
    def __init__(self, data):
        self.bytes = data


class FakeSender:
    def __init__(self, tracker, script=None):
        self.tracker = tracker
        self.script = script or {}
        self.alive = True
        self.sent = 0

    async def send(self, request):
        if not self.alive:
            raise ConnectionError("sender sudah tidak terhubung")

        offset = request.offset

        self.tracker.enter()

        try:
            behaviour = self.script.get(offset)

            if behaviour:
                kind, times = behaviour

                if times > 0:
                    self.script[offset] = (kind, times - 1)

                    if kind == "flood":
                        await asyncio.sleep(0.001)
                        raise FloodPremiumWaitError(1)

                    if kind == "dead":
                        self.alive = False
                        await asyncio.sleep(0.001)
                        raise ConnectionError("koneksi putus")

                    if kind == "hang":
                        await asyncio.sleep(30)

                    if kind == "boom":
                        await asyncio.sleep(0.001)
                        raise RuntimeError("error nyata")

            await asyncio.sleep(RTT)

            self.sent += 1

            remaining = self.tracker.file_size - offset

            length = max(0, min(request.limit, remaining))

            return FakeResult(payload(offset, length))

        finally:
            self.tracker.leave()

    async def disconnect(self):
        self.alive = False


class Tracker:
    """Catat berapa request yang benar-benar terbang bersamaan."""

    def __init__(self, file_size):
        self.file_size = file_size
        self.current = 0
        self.peak = 0
        self.total = 0

    def enter(self):
        self.current += 1
        self.total += 1
        self.peak = max(self.peak, self.current)

    def leave(self):
        self.current -= 1


class FakeDc:
    ip_address = "127.0.0.1"
    port = 443
    id = 2


class FakeSession:
    dc_id = 2


class FakeInnerSender:
    auth_key = b"k" * 8


class FakeClient:
    def __init__(self, tracker, script=None):
        self.session = FakeSession()
        self._sender = FakeInnerSender()
        self._log = None
        self._proxy = None
        self._local_addr = None
        self.tracker = tracker
        self.script = script if script is not None else {}
        self.senders_made = 0

    async def _get_dc(self, dc_id):
        return FakeDc()

    def _connection(self, *args, **kwargs):
        return object()


class FakeDoc:
    def __init__(self, size):
        self.id = 12345
        self.size = size
        self.dc_id = 2
        self.access_hash = 999
        self.file_reference = b"ref"


class FakeMessage:
    def __init__(self, size):
        self.document = FakeDoc(size)


def patch_senders(downloader, client):
    """Ganti pembuatan sender asli dengan sender tiruan."""

    async def _new_sender(dc_id, same_dc):
        client.senders_made += 1
        return FakeSender(client.tracker, client.script)

    downloader._new_sender = _new_sender


def expected_digest(file_size, chunk_size):
    sha = hashlib.sha256()

    offset = 0

    while offset < file_size:
        length = min(chunk_size, file_size - offset)
        sha.update(payload(offset, length))
        offset += chunk_size

    return sha.hexdigest()


def verify_file(path, file_size, chunk_size):
    assert os.path.getsize(path) == file_size, (
        f"ukuran salah: {os.path.getsize(path)} != {file_size}"
    )

    sha = hashlib.sha256()

    with open(path, "rb") as file:
        while True:
            block = file.read(1024 * 1024)
            if not block:
                break
            sha.update(block)

    want = expected_digest(file_size, chunk_size)

    assert sha.hexdigest() == want, "isi file tidak sesuai urutan"

    return sha.hexdigest()


# ==========================================================
# Uji
# ==========================================================

async def test_basic_order_and_speed(tmp):
    """Byte benar & berurutan, dan pipa tidak pernah mengosong."""
    file_size = 40 * 1024 * 1024 + 12345
    tracker = Tracker(file_size)
    client = FakeClient(tracker)

    downloader = ParallelDownloader(
        client,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "video.mp4")
    hasher = hashlib.sha256()

    started = time.monotonic()
    await downloader.download(FakeMessage(file_size), out, hasher=hasher)
    elapsed = time.monotonic() - started

    digest = verify_file(out, file_size, downloader.chunk_size)

    assert hasher.hexdigest() == digest, (
        "checksum sambil-jalan tidak sama dengan checksum baca-ulang"
    )

    assert not os.path.exists(out + ".part"), ".part harus sudah hilang"
    assert not os.path.exists(out + ".part.json"), "meta harus hilang"

    chunks = (file_size + downloader.chunk_size - 1) // downloader.chunk_size
    serial = chunks * RTT

    print(
        f"  chunk={chunks} waktu={elapsed:.2f}s "
        f"(serial 1 request akan {serial:.2f}s) "
        f"puncak in-flight={tracker.peak} "
        f"speedup={serial / elapsed:.1f}x"
    )

    assert tracker.peak > 4, (
        f"in-flight tidak pernah melebihi jumlah koneksi ({tracker.peak})"
    )
    assert elapsed < serial / 5, "tidak cukup paralel"

    return elapsed, chunks


async def test_old_style_one_per_connection(tmp):
    """Perilaku versi lama: in-flight dikunci = jumlah koneksi."""
    file_size = 40 * 1024 * 1024 + 12345
    tracker = Tracker(file_size)
    client = FakeClient(tracker)

    downloader = ParallelDownloader(
        client,
        num_connections=4,
        inflight_per_connection=1,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "old.mp4")

    started = time.monotonic()
    await downloader.download(FakeMessage(file_size), out)
    elapsed = time.monotonic() - started

    verify_file(out, file_size, downloader.chunk_size)

    print(f"  puncak in-flight={tracker.peak} waktu={elapsed:.2f}s")

    assert tracker.peak <= 4, "harusnya terkunci di 4"

    return elapsed


async def test_memory_window(tmp):
    """Buffer penyusun ulang tidak boleh tumbuh tanpa batas."""
    file_size = 60 * 1024 * 1024
    tracker = Tracker(file_size)
    client = FakeClient(tracker)

    downloader = ParallelDownloader(
        client,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    # Chunk pertama sengaja dibuat sangat lambat: penulis tertahan di
    # index 0 sementara worker terus berlari. Kalau tidak ada jendela,
    # seluruh 60 MB menumpuk di RAM.
    real_open = downloader._open_part
    peak_pending = {"value": 0}

    original_download_chunk = downloader._download_chunk

    async def slow_first(conn, location, offset, gate, limiter, dc_id, same_dc):
        if offset == 0:
            await asyncio.sleep(2.0)
        return await original_download_chunk(
            conn, location, offset, gate, limiter, dc_id, same_dc
        )

    downloader._download_chunk = slow_first

    out = os.path.join(tmp, "window.mp4")

    task = asyncio.ensure_future(
        downloader.download(FakeMessage(file_size), out)
    )

    # Intip berapa banyak request yang sudah dilayani selagi penulis
    # tertahan di chunk pertama.
    await asyncio.sleep(1.5)
    served_while_blocked = tracker.total

    await task

    verify_file(out, file_size, downloader.chunk_size)

    window = downloader.max_inflight * 2

    print(
        f"  request dilayani selagi penulis tertahan="
        f"{served_while_blocked} (jendela={window}, total chunk=60)"
    )

    assert served_while_blocked <= window + downloader.max_inflight, (
        "worker berlari melewati jendela, RAM tidak terkunci"
    )

    return True


async def test_flood_recovers(tmp):
    """Flood menurunkan jatah tapi download tetap tuntas dan benar."""
    file_size = 24 * 1024 * 1024
    tracker = Tracker(file_size)

    chunk = 1024 * 1024
    script = {
        offset: ("flood", 1)
        for offset in (3 * chunk, 4 * chunk, 5 * chunk, 11 * chunk)
    }

    client = FakeClient(tracker, script)

    downloader = ParallelDownloader(
        client,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "flood.mp4")
    await downloader.download(FakeMessage(file_size), out)

    verify_file(out, file_size, downloader.chunk_size)

    stats = downloader.last_stats

    print(
        f"  flood_hits={stats['flood_hits']} "
        f"in-flight mulai={stats['inflight_start']} "
        f"terendah={stats['inflight_min']} "
        f"akhir={stats['inflight_end']}"
    )

    assert stats["flood_hits"] > 0, "flood tidak terdeteksi"
    assert stats["inflight_min"] >= 4, (
        "jatah turun di bawah jumlah koneksi"
    )

    return True


async def test_connection_dies_and_renews(tmp):
    """Koneksi putus -> sender diganti baru, download lanjut."""
    file_size = 20 * 1024 * 1024
    tracker = Tracker(file_size)

    chunk = 1024 * 1024
    script = {7 * chunk: ("dead", 1)}

    client = FakeClient(tracker, script)

    downloader = ParallelDownloader(
        client,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "renew.mp4")
    await downloader.download(FakeMessage(file_size), out)

    verify_file(out, file_size, downloader.chunk_size)

    stats = downloader.last_stats

    print(f"  reconnects={stats['reconnects']}")

    assert stats["reconnects"] >= 1, "sender tidak pernah diganti"

    return True


async def test_hang_times_out(tmp):
    """Request yang menggantung harus kena timeout, bukan diam selamanya."""
    file_size = 8 * 1024 * 1024
    tracker = Tracker(file_size)

    chunk = 1024 * 1024
    script = {2 * chunk: ("hang", 1)}

    client = FakeClient(tracker, script)

    downloader = ParallelDownloader(
        client,
        num_connections=2,
        inflight_per_connection=3,
        min_free_bytes=1024,
        request_timeout=1.0,
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "hang.mp4")

    started = time.monotonic()
    await downloader.download(FakeMessage(file_size), out)
    elapsed = time.monotonic() - started

    verify_file(out, file_size, downloader.chunk_size)

    print(f"  selesai dalam {elapsed:.2f}s (request menggantung 30s)")

    assert elapsed < 10, "timeout tidak bekerja"

    return True


async def test_resume(tmp):
    """Gagal di tengah -> percobaan kedua melanjutkan, bukan mengulang."""
    file_size = 30 * 1024 * 1024
    chunk = 1024 * 1024

    # Percobaan 1: chunk ke-20 gagal terus sampai jatah retry habis.
    tracker1 = Tracker(file_size)
    script1 = {20 * chunk: ("boom", 99)}
    client1 = FakeClient(tracker1, script1)

    downloader1 = ParallelDownloader(
        client1,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    downloader1.chunk_retries = 1
    patch_senders(downloader1, client1)

    out = os.path.join(tmp, "resume.mp4")

    failed = False

    try:
        await downloader1.download(FakeMessage(file_size), out)
    except Exception as error:
        failed = True
        print(f"  percobaan 1 gagal seperti yang diharapkan: {error}")

    assert failed, "percobaan 1 seharusnya gagal"

    part = out + ".part"

    assert os.path.exists(part), ".part harus ditahan untuk resume"

    part_size = os.path.getsize(part)

    print(f"  .part tertinggal {part_size / (1024*1024):.0f} MB")

    assert part_size > 0, ".part kosong, tidak ada yang bisa dilanjut"
    assert not os.path.exists(out), "file akhir belum boleh ada"

    # Percobaan 2: tanpa gangguan, harus melanjutkan.
    tracker2 = Tracker(file_size)
    client2 = FakeClient(tracker2)

    downloader2 = ParallelDownloader(
        client2,
        num_connections=4,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader2, client2)

    hasher = hashlib.sha256()

    await downloader2.download(FakeMessage(file_size), out, hasher=hasher)

    digest = verify_file(out, file_size, downloader2.chunk_size)

    assert hasher.hexdigest() == digest, (
        "checksum setelah resume tidak cocok -- "
        "bagian lama tidak ikut dihitung"
    )

    stats = downloader2.last_stats

    resumed_mb = stats["resumed_bytes"] / (1024 * 1024)
    served = tracker2.total

    print(
        f"  percobaan 2 melanjutkan {resumed_mb:.0f} MB, "
        f"hanya {served} request baru (dari 30 chunk)"
    )

    assert stats["resumed_bytes"] > 0, "tidak melanjutkan apa pun"
    assert served < 30, "mengulang dari nol"

    assert not os.path.exists(part), ".part harus dibersihkan"
    assert not os.path.exists(out + ".part.json"), "meta harus dibersihkan"

    return True


async def test_resume_rejects_different_file(tmp):
    """.part dari video lain tidak boleh dipakai."""
    file_size = 10 * 1024 * 1024

    out = os.path.join(tmp, "beda.mp4")

    # .part palsu dari "video lain"
    with open(out + ".part", "wb") as file:
        file.write(b"x" * (5 * 1024 * 1024))

    with open(out + ".part.json", "w") as file:
        file.write('{"document_id": "999", "size": 1, "chunk_size": 1}')

    tracker = Tracker(file_size)
    client = FakeClient(tracker)

    downloader = ParallelDownloader(
        client,
        num_connections=2,
        inflight_per_connection=3,
        min_free_bytes=1024,
    )
    patch_senders(downloader, client)

    await downloader.download(FakeMessage(file_size), out)

    verify_file(out, file_size, downloader.chunk_size)

    print(
        f"  .part asing dibuang, "
        f"{downloader.last_stats['resumed_bytes']} byte dilanjutkan"
    )

    assert downloader.last_stats["resumed_bytes"] == 0

    return True


async def test_disk_space_guard(tmp):
    """Cek sisa disk harus jalan (dan lintas-OS, bukan os.statvfs)."""
    file_size = 10 * 1024 * 1024

    tracker = Tracker(file_size)
    client = FakeClient(tracker)

    downloader = ParallelDownloader(
        client,
        num_connections=2,
        min_free_bytes=10 ** 15,  # mustahil terpenuhi
    )
    patch_senders(downloader, client)

    out = os.path.join(tmp, "penuh.mp4")

    try:
        await downloader.download(FakeMessage(file_size), out)
    except fast_download.NotEnoughDiskSpace as error:
        print(f"  ditolak dengan benar: {str(error)[:60]}...")
        return True

    raise AssertionError("seharusnya menolak karena disk penuh")


async def main():
    tmp = tempfile.mkdtemp(prefix="dl-test-")

    tests = [
        ("urutan byte + kecepatan", test_basic_order_and_speed),
        ("perilaku lama (1 per koneksi)", test_old_style_one_per_connection),
        ("jendela RAM", test_memory_window),
        ("pulih dari flood", test_flood_recovers),
        ("koneksi putus -> ganti baru", test_connection_dies_and_renews),
        ("request menggantung -> timeout", test_hang_times_out),
        ("resume dari .part", test_resume),
        (".part asing ditolak", test_resume_rejects_different_file),
        ("penjaga sisa disk", test_disk_space_guard),
    ]

    results = {}
    failures = 0

    try:
        for name, test in tests:
            print(f"\n== {name}")

            try:
                results[name] = await test(tmp)
                print("  LULUS")
            except Exception as error:
                failures += 1
                print(f"  GAGAL: {type(error).__name__}: {error}")

                import traceback
                traceback.print_exc()

    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print("\n" + "=" * 50)

    new_time = results.get("urutan byte + kecepatan")
    old_time = results.get("perilaku lama (1 per koneksi)")

    if new_time and old_time:
        print(
            f"Perbandingan pada file yang sama (RTT buatan {RTT*1000:.0f} ms):"
        )
        print(f"  in-flight 1 per koneksi : {old_time:.2f}s")
        print(f"  in-flight 3 per koneksi : {new_time[0]:.2f}s")
        print(f"  -> {old_time / new_time[0]:.2f}x lebih cepat")

    print("=" * 50)
    print(f"{len(tests) - failures}/{len(tests)} lulus")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
