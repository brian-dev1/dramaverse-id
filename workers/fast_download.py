"""
Modul download paralel multi-koneksi untuk Telethon.

Membagi file jadi beberapa bagian (part) dan mendownloadnya secara
bersamaan lewat beberapa koneksi ke Telegram DC, lalu menuliskannya ke
disk sesuai urutan.

Menangani 2 kasus koneksi:
1. Video di DC BERBEDA dari koneksi utama -> pakai exported sender
   (cara standar Telethon untuk pinjam koneksi ke DC lain).
2. Video di DC SAMA dengan koneksi utama -> Telegram tidak mengizinkan
   export authorization ke DC yang sama (karena sudah authorized di
   situ). Solusinya: buka koneksi baru langsung pakai auth_key yang
   sudah ada, tanpa proses export/import.

PERBEDAAN DARI VERSI SEBELUMNYA
-------------------------------
Versi sebelumnya gagal total begitu Telegram melempar
FLOOD_PREMIUM_WAIT ("A wait of N seconds is required in non-premium
accounts"). Tiga hal yang bikin gagal:

1. FloodPremiumWaitError BUKAN turunan FloodWaitError di Telethon,
   jadi jatuh ke `except Exception` dan di-backoff 1s/2s/4s tanpa
   menghormati `error.seconds`.
2. Waktu satu chunk kena flood, koneksi lain tetap menembak. Telegram
   membaca itu sebagai flood berkelanjutan dan waktu tunggunya naik
   terus, sampai jatah 3 percobaan habis.
3. Jatah percobaan dipakai bersama antara error nyata dan flood wait.
   Flood wait itu backpressure, bukan kegagalan -- tidak seharusnya
   memotong jatah retry.

Versi ini:
- Mengenali flood lewat kelas error DAN teks pesannya, jadi aman untuk
  FloodWaitError, FloodPremiumWaitError, dan varian baru apa pun.
- Punya "flood gate" global: satu chunk kena flood -> SEMUA koneksi
  ikut berhenti selama durasi yang diminta Telegram, lalu jalan lagi.
- Punya limiter adaptif: jumlah request bersamaan otomatis turun tiap
  kali kena flood dan naik lagi pelan-pelan setelah lancar. Download
  menyesuaikan diri dengan jatah akun, tidak menyerah.
- Chunk 1 MB (batas maksimum Telegram) -> jumlah request separuh
  dibanding 512 KB, jadi jauh lebih jarang kena flood.
- Menulis tiap batch ke disk begitu selesai lalu membuangnya dari
  memori, jadi pemakaian RAM tetap konstan:

      batch_size * CHUNK_SIZE = (num_connections * BATCH_MULTIPLIER) * 1 MB

  Dengan default 4 koneksi -> sekitar 12 MB, berapa pun ukuran videonya.
"""

import asyncio
import math
import os
import random
import re
import time

from telethon.network import MTProtoSender
from telethon.tl.functions.upload import GetFileRequest
from telethon.tl.types import InputDocumentFileLocation

# ------------------------------------------------------------------
# Kelas error flood.
#
# FloodWaitError selalu ada. FloodPremiumWaitError baru muncul di
# Telethon versi baru, jadi importnya dibungkus supaya modul tetap
# jalan di versi lama.
# ------------------------------------------------------------------

from telethon.errors import FloodWaitError

_FLOOD_ERRORS = [FloodWaitError]

for _name in ("FloodPremiumWaitError", "FloodTestPhoneWaitError"):
    try:
        _FLOOD_ERRORS.append(
            getattr(
                __import__(
                    "telethon.errors",
                    fromlist=[_name],
                ),
                _name,
            )
        )
    except (AttributeError, ImportError):
        pass

FLOOD_ERRORS = tuple(_FLOOD_ERRORS)

# Cadangan terakhir: sebagian error flood hanya bisa dikenali dari
# teksnya ("A wait of 3 seconds is required in non-premium accounts").
_FLOOD_TEXT = re.compile(
    r"wait of (\d+) seconds",
    re.IGNORECASE,
)


# ------------------------------------------------------------------
# Konstanta
# ------------------------------------------------------------------

# 1 MB per request. Ini batas maksimum GetFileRequest dan tetap
# kelipatan yang sah (limit habis dibagi 4096, 1 MB habis dibagi limit).
# Dua kali lebih besar dari 512 KB = setengah jumlah request untuk file
# yang sama = jauh lebih jarang kena FLOOD_PREMIUM_WAIT.
CHUNK_SIZE = 1024 * 1024

# Berapa chunk yang boleh "in-flight" per koneksi.
# Makin besar = makin cepat tapi makin boros RAM.
BATCH_MULTIPLIER = 3

# Batas aman koneksi paralel. Lebih dari ini Telegram gampang
# melempar FLOOD_WAIT dan VPS kecil mulai tersengal.
MAX_CONNECTIONS = 8

# Berapa kali satu chunk dicoba ulang untuk error NYATA (timeout,
# koneksi putus, dsb). Flood wait tidak memotong jatah ini.
CHUNK_RETRIES = 5

# Batas atas satu kali flood wait yang masih mau kita tunggu.
MAX_FLOOD_WAIT = 120

# Total waktu flood wait yang ditolerir untuk satu chunk sebelum
# menyerah. Melindungi dari akun yang benar-benar diblokir sementara.
MAX_FLOOD_TOTAL = 900

# Setelah sekian chunk lancar berturut-turut, izin request bersamaan
# dinaikkan satu lagi.
RECOVERY_STREAK = 20


class NotEnoughDiskSpace(Exception):
    """Sisa disk tidak cukup untuk menampung file."""


def flood_seconds(error):
    """
    Kembalikan lama tunggu (detik) kalau `error` adalah flood,
    atau None kalau bukan.
    """
    if isinstance(error, FLOOD_ERRORS):
        return max(1, int(getattr(error, "seconds", 1) or 1))

    seconds = getattr(error, "seconds", None)

    if isinstance(seconds, int) and seconds > 0:
        return seconds

    match = _FLOOD_TEXT.search(str(error))

    if match:
        return max(1, int(match.group(1)))

    return None


class _FloodGate:
    """
    Palang buka-tutup bersama. Begitu satu chunk kena flood, palang
    ditutup untuk SEMUA koneksi selama durasi yang diminta Telegram.

    Ini inti perbaikannya: dulu koneksi lain tetap menembak selama satu
    koneksi menunggu, jadi Telegram menaikkan hukumannya terus.
    """

    def __init__(self):
        self._open_at = 0.0
        self.total_paused = 0.0
        self.flood_hits = 0

    def pause(self, seconds):
        self.flood_hits += 1

        now = time.monotonic()

        # Tambah jeda kecil supaya tidak menembak tepat di detik batas.
        until = now + seconds + 0.5

        # Kalau palang sudah tertutup lebih lama, biarkan.
        if until > self._open_at:
            self.total_paused += until - max(now, self._open_at)
            self._open_at = until

    async def wait(self):
        while True:
            delay = self._open_at - time.monotonic()

            if delay <= 0:
                return

            # Tidur pendek-pendek supaya perpanjangan palang oleh
            # koneksi lain langsung terbaca.
            await asyncio.sleep(min(delay, 0.5))


class _AdaptiveLimiter:
    """
    Pembatas request bersamaan yang bisa berubah saat jalan.

    Kena flood -> jatah turun satu (minimal 1).
    Lancar terus -> jatah naik satu lagi (maksimal batas awal).
    """

    def __init__(self, start, maximum):
        self._max = max(1, int(maximum))
        self._limit = max(1, min(int(start), self._max))
        self._in_flight = 0
        self._streak = 0
        self._cond = asyncio.Condition()
        self.min_limit_seen = self._limit

    @property
    def limit(self):
        return self._limit

    async def acquire(self):
        async with self._cond:
            while self._in_flight >= self._limit:
                await self._cond.wait()

            self._in_flight += 1

    async def release(self):
        async with self._cond:
            self._in_flight -= 1

            self._cond.notify(1)

    async def penalize(self):
        async with self._cond:
            self._streak = 0

            if self._limit > 1:
                self._limit -= 1

                self.min_limit_seen = min(
                    self.min_limit_seen,
                    self._limit,
                )

    async def reward(self):
        async with self._cond:
            self._streak += 1

            if (
                self._streak >= RECOVERY_STREAK
                and self._limit < self._max
            ):
                self._limit += 1
                self._streak = 0

                self._cond.notify(1)


class ParallelDownloader:
    def __init__(
        self,
        client,
        num_connections=4,
        batch_multiplier=BATCH_MULTIPLIER,
        min_free_bytes=512 * 1024 * 1024,
        chunk_size=CHUNK_SIZE,
    ):
        """
        client          : TelegramClient yang sudah terhubung.
        num_connections : jumlah koneksi paralel (dibatasi 1..MAX_CONNECTIONS).
        batch_multiplier: chunk in-flight per koneksi. Turunkan kalau RAM tipis.
        min_free_bytes  : sisa disk yang harus tetap tersedia setelah download.
        chunk_size      : besar satu request. Harus kelipatan 4096 dan
                          membagi habis 1 MB.
        """
        self.client = client
        self.num_connections = max(
            1,
            min(int(num_connections), MAX_CONNECTIONS),
        )
        self.batch_multiplier = max(1, int(batch_multiplier))
        self.min_free_bytes = int(min_free_bytes)
        self.chunk_size = int(chunk_size)
        self.chunk_retries = CHUNK_RETRIES

        # Statistik ringkas, berguna buat log di worker.
        self.last_stats = {}

    # --------------------------------------------------------
    # Koneksi
    # --------------------------------------------------------

    async def _get_dc_senders(self, dc_id):
        """
        Kembalikan (list_senders, same_dc).

        same_dc True  -> senders dibuat manual, harus di-disconnect manual.
        same_dc False -> senders dipinjam lewat _borrow_exported_sender,
                         harus dikembalikan lewat _return_exported_sender.
        """
        my_dc_id = self.client.session.dc_id

        senders = []

        try:
            if dc_id == my_dc_id:
                # DC sama dengan koneksi utama: reuse auth_key yang sudah
                # ada, buka koneksi TCP baru tanpa export/import.
                dc = await self.client._get_dc(dc_id)
                auth_key = self.client._sender.auth_key

                for _ in range(self.num_connections):
                    sender = MTProtoSender(
                        auth_key,
                        loggers=self.client._log,
                    )

                    await sender.connect(
                        self.client._connection(
                            dc.ip_address,
                            dc.port,
                            dc.id,
                            loggers=self.client._log,
                            proxy=self.client._proxy,
                            local_addr=self.client._local_addr,
                        )
                    )

                    senders.append(sender)

                return senders, True

            # DC berbeda: cara standar, pinjam exported sender.
            for _ in range(self.num_connections):
                sender = await self.client._borrow_exported_sender(dc_id)
                senders.append(sender)

            return senders, False

        except Exception:
            # Jangan tinggalkan koneksi menggantung kalau gagal di tengah.
            await self._release_senders(
                senders,
                dc_id == my_dc_id,
            )
            raise

    async def _release_senders(self, senders, same_dc):
        for sender in senders:
            try:
                if same_dc:
                    await sender.disconnect()
                else:
                    await self.client._return_exported_sender(sender)
            except Exception:
                # Pelepasan koneksi tidak boleh menggagalkan download
                # yang sudah selesai.
                pass

    # --------------------------------------------------------
    # Chunk
    # --------------------------------------------------------

    async def _download_chunk(
        self,
        sender,
        location,
        offset,
        gate,
        limiter,
    ):
        """
        Ambil satu chunk.

        `limit` selalu chunk_size penuh -- itu kelipatan standar yang
        diizinkan Telegram. Untuk chunk terakhir, server otomatis
        mengembalikan lebih sedikit byte daripada yang diminta; itu
        normal, BUKAN error. Kalau limit dikecilkan manual di sini,
        Telegram menolak dengan "invalid limit".

        Flood wait TIDAK memotong jatah percobaan: itu perintah tunggu
        dari server, bukan kegagalan. Yang memotong jatah hanya error
        nyata (timeout, koneksi putus, dsb).
        """
        attempt = 0
        flood_total = 0.0
        last_error = None

        while attempt <= self.chunk_retries:
            # Hormati palang bersama SEBELUM ambil izin, supaya
            # koneksi yang menunggu tidak menahan slot orang lain.
            await gate.wait()
            await limiter.acquire()

            try:
                result = await sender.send(
                    GetFileRequest(
                        location=location,
                        offset=offset,
                        limit=self.chunk_size,
                    )
                )

                await limiter.release()
                await limiter.reward()

                return offset, result.bytes

            except Exception as error:
                await limiter.release()

                last_error = error

                seconds = flood_seconds(error)

                if seconds is None:
                    # Error nyata: potong jatah, backoff dengan jitter
                    # supaya semua koneksi tidak bangun serempak.
                    attempt += 1

                    if attempt > self.chunk_retries:
                        break

                    await asyncio.sleep(
                        (2 ** (attempt - 1))
                        + random.uniform(0, 0.5)
                    )

                    continue

                # Flood: turunkan jatah paralel dan tutup palang untuk
                # SEMUA koneksi selama durasi yang diminta Telegram.
                if seconds > MAX_FLOOD_WAIT:
                    last_error = RuntimeError(
                        f"Telegram minta tunggu {seconds} detik "
                        f"(batas toleransi {MAX_FLOOD_WAIT} detik)."
                    )
                    break

                flood_total += seconds

                if flood_total > MAX_FLOOD_TOTAL:
                    last_error = RuntimeError(
                        f"Total flood wait {flood_total:.0f} detik "
                        f"melewati batas {MAX_FLOOD_TOTAL} detik."
                    )
                    break

                await limiter.penalize()

                gate.pause(seconds)

        raise RuntimeError(
            f"Chunk offset {offset} gagal: {last_error}"
        )

    # --------------------------------------------------------
    # Disk
    # --------------------------------------------------------

    def _assert_disk_space(self, out_path, file_size):
        target_dir = os.path.dirname(os.path.abspath(out_path)) or "."

        os.makedirs(target_dir, exist_ok=True)

        stat = os.statvfs(target_dir)

        free_bytes = stat.f_bavail * stat.f_frsize

        needed = file_size + self.min_free_bytes

        if free_bytes < needed:
            raise NotEnoughDiskSpace(
                f"Butuh {needed / (1024 ** 3):.2f} GB "
                f"(file {file_size / (1024 ** 3):.2f} GB + "
                f"cadangan {self.min_free_bytes / (1024 ** 3):.2f} GB), "
                f"tersedia {free_bytes / (1024 ** 3):.2f} GB "
                f"di {target_dir}."
            )

    # --------------------------------------------------------
    # Download
    # --------------------------------------------------------

    async def download(
        self,
        message,
        out_path,
        progress_callback=None,
        hasher=None,
    ):
        """
        Download `message` ke `out_path` secara paralel.

        hasher : objek hashlib opsional (mis. hashlib.sha256()). Kalau
                 diisi, checksum dihitung SAMBIL menulis file sehingga
                 tidak perlu membaca ulang seluruh file setelahnya.
        """
        doc = message.document if message.document else None

        if not doc:
            raise ValueError(
                "Media bukan dokumen/video, tidak didukung mode paralel."
            )

        file_size = doc.size
        dc_id = doc.dc_id

        self._assert_disk_space(out_path, file_size)

        location = InputDocumentFileLocation(
            id=doc.id,
            access_hash=doc.access_hash,
            file_reference=doc.file_reference,
            thumb_size="",
        )

        total_chunks = math.ceil(file_size / self.chunk_size)
        offsets = [i * self.chunk_size for i in range(total_chunks)]

        batch_size = self.num_connections * self.batch_multiplier

        gate = _FloodGate()
        limiter = _AdaptiveLimiter(
            self.num_connections,
            self.num_connections,
        )

        senders, same_dc = await self._get_dc_senders(dc_id)

        downloaded = 0
        started_at = time.monotonic()

        try:
            # File dibuka sekali; tiap batch langsung ditulis lalu
            # dibuang dari memori. RAM tetap konstan.
            with open(out_path, "wb") as file:
                for batch_start in range(0, len(offsets), batch_size):
                    batch = offsets[batch_start:batch_start + batch_size]

                    tasks = [
                        self._download_chunk(
                            senders[index % len(senders)],
                            location,
                            offset,
                            gate,
                            limiter,
                        )
                        for index, offset in enumerate(batch)
                    ]

                    # return_exceptions=True supaya tugas lain dalam batch
                    # tetap tuntas sebelum kita melempar error. Tanpa ini,
                    # ada task menggantung yang masih memakai sender saat
                    # sender-nya sudah diputus di blok finally.
                    batch_results = await asyncio.gather(
                        *tasks,
                        return_exceptions=True,
                    )

                    failed = [
                        item
                        for item in batch_results
                        if isinstance(item, BaseException)
                    ]

                    if failed:
                        raise failed[0]

                    # Urutkan supaya penulisan tetap berurutan.
                    batch_results.sort(key=lambda item: item[0])

                    for _, data in batch_results:
                        file.write(data)

                        if hasher is not None:
                            hasher.update(data)

                        downloaded += len(data)

                        if progress_callback:
                            progress_callback(downloaded, file_size)

                    # Lepaskan referensi batch sebelum lanjut.
                    del batch_results

        except BaseException:
            # Jangan tinggalkan file setengah jadi memenuhi disk.
            try:
                if os.path.exists(out_path):
                    os.remove(out_path)
            except OSError:
                pass

            raise

        finally:
            await self._release_senders(senders, same_dc)

        self.last_stats = {
            "bytes": downloaded,
            "seconds": time.monotonic() - started_at,
            "flood_hits": gate.flood_hits,
            "flood_seconds": round(gate.total_paused, 1),
            "connections_start": self.num_connections,
            "connections_min": limiter.min_limit_seen,
            "connections_end": limiter.limit,
        }

        return out_path
