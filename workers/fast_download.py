"""
Modul download paralel untuk Telethon.

Membagi file jadi potongan 1 MB, mengambil beberapa sekaligus, lalu
menuliskannya ke disk sesuai urutan.


TIGA CARA MENDAPAT KONEKSI, DAN KENAPA DEFAULTNYA YANG PALING TENANG
--------------------------------------------------------------------
"main"   Video ada di DC yang sama dengan sesi login. Dipakai sender
         milik client -- soket yang sudah terhubung dan sehat. Beberapa
         request 1 MB terbang bersamaan di atasnya; MTProto memang
         dirancang untuk itu. INI DEFAULT.

"borrow" Video ada di DC lain. Telethon meminjamkan sender lewat
         _borrow_exported_sender. Perlu diketahui: fungsi itu
         mengembalikan SATU objek sender yang sama untuk satu DC,
         berapa kali pun diminta -- ia cuma menghitung peminjaman.
         Jadi meminta 4 "koneksi" ke DC lain tidak pernah menghasilkan
         4 soket. Selalu 1.

"clone"  Beberapa soket dibuka sambil meminjam auth_key koneksi utama.
         Ini satu-satunya cara mendapat soket yang benar-benar paralel
         ke DC sendiri -- dan sumber masalah yang panjang. Telethon
         mencatat persis soal ini di _create_exported_sender:

             "Can't reuse self._sender._connection as it has its own
              seqno. If one were to do that, Telegram would reset the
              connection with no further clues."

         Gejalanya: banjir "connection reset by peer", "0 bytes read on
         a total of 8 expected bytes", dan "Server replied with a wrong
         session ID". Karena itu jalur ini HARUS diminta sadar-sadar
         lewat clone_senders=True.


PERBAIKAN YANG TIDAK MENAMBAH AGRESIVITAS SEDIKIT PUN
-----------------------------------------------------
Semua ini murni menghapus pemborosan, bukan menambah tekanan ke
Telegram, jadi aman dipakai berapa pun jumlah request bersamaannya:

1. TIDAK ADA LAGI BATCH BARRIER.
   Dulu offset diproses per rombongan dengan asyncio.gather, dan
   rombongan berikutnya baru mulai setelah SELURUH rombongan sebelumnya
   tuntas. Satu chunk lambat menahan sisanya, di ekor tiap rombongan
   paralelismenya meluruh sampai tinggal satu, dan selama penulisan ke
   disk tidak ada satu pun request yang terbang -- terukur pipa kosong
   30% waktu pada RTT rendah.

   Sekarang alirannya berkelanjutan: worker menarik offset berikutnya
   begitu selesai, penulis terpisah menuliskan sesuai urutan dari
   buffer penyusun ulang.

2. PENULISAN DISK TIDAK LAGI MEMBLOKIR EVENT LOOP.
   file.write() dan hasher.update() pindah ke thread penulis sendiri.

3. CHECKSUM DIHITUNG SAMBIL JALAN.
   Lewat parameter `hasher`, jadi file tidak perlu dibaca ulang
   seluruhnya setelah download.

4. RESUME.
   File ditulis ke <nama>.part berikut sidik jari dokumennya. Gagal di
   menit ke-9 dilanjutkan, bukan diulang dari nol.

5. CEK SISA DISK LINTAS-OS.
   shutil.disk_usage menggantikan os.statvfs, yang tidak ada di Windows
   dan diam-diam membuat seluruh mode paralel gagal di sana.

6. FLOOD DAN RESET SAMA-SAMA JADI REM.
   Flood wait sudah lama ditangani lewat palang bersama. Koneksi yang
   DIPUTUS server dulu tidak dianggap apa-apa, jadi skrip menyambung
   lagi dengan beban sama dan diputus lagi. Sekarang keduanya
   menurunkan jatah request bersamaan.

Pemakaian RAM tetap terbatas berapa pun ukuran videonya:

    window * CHUNK_SIZE  ~= (in-flight * 2) * 1 MB
"""

import asyncio
import json
import math
import os
import random
import re
import shutil
import time

from concurrent.futures import ThreadPoolExecutor

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
CHUNK_SIZE = 1024 * 1024

# Berapa request 1 MB yang boleh terbang bersamaan PER KONEKSI.
#
# DEFAULT 1 -- artinya total request bersamaan = jumlah koneksi, persis
# seperti perilaku asli sebelum revisi.
#
# Saya sempat menaikkannya ke 3 dan itu keliru. Alasannya: koneksi
# tambahan di modul ini dibuat dengan meminjam auth_key koneksi utama,
# dan Telethon sendiri memperingatkan pola itu -- "Telegram would reset
# the connection with no further clues". Dengan 4 request bersamaan
# polanya masih tertahan; dengan 12 ia meledak jadi banjir
# "connection reset by peer" dan "wrong session ID", dan kecepatannya
# justru jatuh.
#
# Naikkan lewat TG_INFLIGHT_PER_CONN kalau mau bereksperimen, tapi
# pantau baris "[TG] Direm:" di akhir download.
INFLIGHT_PER_CONNECTION = 1

# Batas aman koneksi paralel. Lebih dari ini Telegram gampang
# melempar FLOOD_WAIT dan VPS kecil mulai tersengal.
MAX_CONNECTIONS = 8

# Plafon keras jumlah request bersamaan, berapa pun setelan di atas.
MAX_INFLIGHT = 32

# Berapa kali satu chunk dicoba ulang untuk error NYATA (timeout,
# koneksi putus, dsb). Flood wait tidak memotong jatah ini.
CHUNK_RETRIES = 5

# Batas waktu satu request 1 MB. Tanpa ini, koneksi yang mati diam-diam
# membuat satu chunk menggantung selamanya dan seluruh download berhenti
# maju tanpa pernah melempar error.
#
# Sengaja longgar. Ini jaring pengaman untuk koneksi yang benar-benar
# mati, bukan alat pengatur kecepatan. Membatalkan request Telethon di
# tengah jalan meninggalkan sisa di _pending_state sender, jadi makin
# jarang ia terpicu makin baik.
REQUEST_TIMEOUT = 180

# Batas atas satu kali flood wait yang masih mau kita tunggu.
MAX_FLOOD_WAIT = 120

# Total waktu flood wait yang ditolerir untuk satu chunk sebelum
# menyerah. Melindungi dari akun yang benar-benar diblokir sementara.
MAX_FLOOD_TOTAL = 900

# Setelah sekian chunk lancar berturut-turut, izin request bersamaan
# dinaikkan satu lagi.
RECOVERY_STREAK = 5

# Lama palang ditutup saat Telegram MEMUTUS koneksi (bukan menyuruh
# menunggu). Server tidak menyebutkan angka dalam kasus ini, jadi kita
# pilih jeda pendek: cukup untuk memecah gelombang, tidak sampai
# membuang waktu kalau ternyata cuma satu soket yang apes.
RESET_PAUSE = 2


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


def is_connection_error(error):
    """
    True kalau `error` menandakan koneksinya yang bermasalah, bukan
    permintaannya -- artinya sender-nya layak diganti baru.
    """
    if isinstance(
        error,
        (
            asyncio.TimeoutError,
            ConnectionError,
            OSError,
            EOFError,
        ),
    ):
        return True

    text = str(error).lower()

    return any(
        mark in text
        for mark in (
            "disconnect",
            "not connected",
            "connection",
            "broken",
            "timed out",
            "timeout",
        )
    )


class _FloodGate:
    """
    Palang buka-tutup bersama. Begitu satu chunk kena flood, palang
    ditutup untuk SEMUA koneksi selama durasi yang diminta Telegram.

    Tanpa ini, koneksi lain tetap menembak selama satu koneksi
    menunggu, jadi Telegram menaikkan hukumannya terus.
    """

    def __init__(self):
        self._open_at = 0.0
        self.total_paused = 0.0
        self.flood_hits = 0
        self.reset_hits = 0

        # Nomor gelombang. Naik HANYA saat palang yang tadinya terbuka
        # ditutup lagi -- jadi semua chunk yang kena gelombang yang sama
        # membawa nomor yang sama.
        self.generation = 0

    def pause(self, seconds, reason="flood"):
        """
        Tutup palang, kembalikan nomor gelombang saat ini.

        `reason` memisahkan dua bentuk penolakan yang butuh perlakuan
        sama tapi perlu dihitung terpisah di statistik:

        "flood" -- Telegram menyuruh menunggu secara eksplisit.
        "reset" -- Telegram memutus koneksi begitu saja (connection
                   reset / broken pipe). Ini juga backpressure, cuma
                   disampaikan dengan kasar. Versi sebelumnya tidak
                   menganggapnya begitu: jatah paralel tidak pernah
                   turun karenanya, jadi skrip terus menembak sekeras
                   tadi dan koneksinya diputus lagi, berulang-ulang.
        """
        if reason == "reset":
            self.reset_hits += 1
        else:
            self.flood_hits += 1

        now = time.monotonic()

        # Tambah jeda kecil supaya tidak menembak tepat di detik batas.
        until = now + seconds + 0.5

        # Palang sedang terbuka -> ini gelombang baru. Kalau masih
        # tertutup, chunk ini cuma korban gelombang yang sudah berjalan.
        if now >= self._open_at:
            self.generation += 1

        # Kalau palang sudah tertutup lebih lama, biarkan.
        if until > self._open_at:
            self.total_paused += until - max(now, self._open_at)
            self._open_at = until

        return self.generation

    async def wait(self):
        paused = False

        while True:
            delay = self._open_at - time.monotonic()

            if delay <= 0:
                break

            paused = True

            # Tidur pendek-pendek supaya perpanjangan palang oleh
            # koneksi lain langsung terbaca.
            await asyncio.sleep(min(delay, 0.5))

        if paused:
            # Jangan bangun serempak. Kalau semua koneksi menembak di
            # milidetik yang sama begitu palang dibuka, Telegram melihat
            # lonjakan yang sama persis seperti yang tadi dihukum, dan
            # langsung menutup palang lagi. Jeda acak kecil memecah
            # gelombang itu.
            await asyncio.sleep(random.uniform(0, 0.25))


class _AdaptiveLimiter:
    """
    Pembatas request bersamaan yang bisa berubah saat jalan.

    Satu GELOMBANG flood -> jatah turun satu (tidak pernah di bawah
    `floor`). Turun sekali per gelombang, bukan sekali per chunk: satu
    gelombang mengenai semua chunk yang sedang terbang sekaligus, dan
    tanpa penjagaan ini jatahnya anjlok dalam satu tarikan napas.

    Lancar terus -> jatah naik satu lagi, sampai `maximum`.
    """

    def __init__(self, start, maximum, floor):
        self._max = max(1, int(maximum))
        self._floor = max(1, min(int(floor), self._max))
        self._limit = max(self._floor, min(int(start), self._max))
        self._in_flight = 0
        self._streak = 0
        self._cond = asyncio.Condition()
        self.start_limit = self._limit
        self.min_limit_seen = self._limit
        self.max_limit_seen = self._limit

        # Gelombang flood terakhir yang sudah dihitung sebagai hukuman.
        self._last_generation = 0

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

    async def penalize(self, generation=None):
        """
        Turunkan jatah satu tingkat untuk gelombang flood `generation`.

        Gelombang yang sudah pernah dihukum diabaikan, jadi 12 chunk
        yang kena flood bersamaan tetap dihitung satu kali.
        """
        async with self._cond:
            if generation is not None:
                if generation <= self._last_generation:
                    return

                self._last_generation = generation

            # Flood itu backpressure, bukan kegagalan. Streak dipotong
            # separuh, tidak dinolkan -- kemajuan yang sudah dikumpulkan
            # tidak hangus seluruhnya setiap kali kena rem.
            self._streak //= 2

            if self._limit > self._floor:
                self._limit -= 1

                self.min_limit_seen = min(
                    self.min_limit_seen,
                    self._limit,
                )

    async def reward(self):
        async with self._cond:
            if self._limit >= self._max:
                return

            self._streak += 1

            if self._streak >= RECOVERY_STREAK:
                self._limit += 1
                self._streak = 0

                self.max_limit_seen = max(
                    self.max_limit_seen,
                    self._limit,
                )

                # Satu slot baru terbuka -> satu penunggu boleh jalan.
                self._cond.notify(1)


class _Conn:
    """
    Pembungkus sender supaya koneksi yang putus bisa diganti baru
    tanpa mengubah siapa pun yang sedang memegangnya.

    Satu koneksi dipakai beberapa worker sekaligus -- itu memang
    tujuannya, karena MTProto sanggup melayani banyak request dalam
    satu sambungan. Konsekuensinya, kalau koneksi putus, semua worker
    yang memegangnya akan minta ganti pada saat yang hampir sama.
    `lock` + pemeriksaan identitas memastikan yang benar-benar dibuat
    cuma satu sender baru, bukan satu per worker.
    """

    __slots__ = ("sender", "renewals", "lock")

    def __init__(self, sender):
        self.sender = sender
        self.renewals = 0
        self.lock = asyncio.Lock()


class ParallelDownloader:
    def __init__(
        self,
        client,
        num_connections=4,
        max_connections=None,
        inflight_per_connection=INFLIGHT_PER_CONNECTION,
        batch_multiplier=None,
        min_free_bytes=512 * 1024 * 1024,
        chunk_size=CHUNK_SIZE,
        request_timeout=REQUEST_TIMEOUT,
        clone_senders=False,
    ):
        """
        client                  : TelegramClient yang sudah terhubung.

        num_connections         : jumlah koneksi TCP paralel ke DC
                                  (dibatasi 1..MAX_CONNECTIONS).

        max_connections         : plafon jumlah koneksi. Default sama
                                  dengan num_connections. Ini TIDAK
                                  lagi membatasi jumlah request
                                  bersamaan -- lihat di bawah.

        inflight_per_connection : berapa request 1 MB yang boleh
                                  terbang bersamaan di tiap koneksi.
                                  Inilah tombol kecepatan yang
                                  sebenarnya. Default 3. Isi 1 untuk
                                  meniru perilaku versi lama.

        batch_multiplier        : nama lama dari inflight_per_connection,
                                  masih diterima supaya kode pemanggil
                                  lama tidak rusak.

        min_free_bytes          : sisa disk yang harus tetap tersedia
                                  setelah download.

        chunk_size              : besar satu request. Harus kelipatan
                                  4096 dan membagi habis 1 MB.

        request_timeout         : batas waktu satu request, detik.
        """
        self.client = client

        # Membuka beberapa soket yang meminjam auth_key koneksi utama.
        # Benar-benar paralel, tapi Telegram sering memutusnya -- lihat
        # catatan di _new_sender. Default mati.
        self.clone_senders = bool(clone_senders)

        self.num_connections = max(
            1,
            min(int(num_connections), MAX_CONNECTIONS),
        )

        self.max_connections = max(
            self.num_connections,
            min(
                int(max_connections or self.num_connections),
                MAX_CONNECTIONS,
            ),
        )

        if batch_multiplier is not None:
            inflight_per_connection = batch_multiplier

        self.inflight_per_connection = max(
            1,
            int(inflight_per_connection),
        )

        self.max_inflight = max(
            self.num_connections,
            min(
                self.num_connections * self.inflight_per_connection,
                MAX_INFLIGHT,
            ),
        )

        self.min_free_bytes = int(min_free_bytes)
        self.chunk_size = int(chunk_size)
        self.chunk_retries = CHUNK_RETRIES
        self.request_timeout = float(request_timeout)

        # Statistik ringkas, berguna buat log di worker.
        self.last_stats = {}

    # --------------------------------------------------------
    # Koneksi
    # --------------------------------------------------------

    async def _new_sender(self, dc_id, same_dc):
        if not same_dc:
            return await self.client._borrow_exported_sender(dc_id)

        # DC sama dengan koneksi utama: buka koneksi TCP baru sambil
        # MEMINJAM auth_key yang sudah ada, tanpa export/import.
        #
        # PERINGATAN: inilah sumber "connection reset by peer",
        # "0 bytes read on a total of 8 expected bytes", dan "Server
        # replied with a wrong session ID". Telethon memberi catatan
        # tepat soal ini di _create_exported_sender:
        #
        #   "Can't reuse self._sender._connection as it has its own
        #    seqno. If one were to do that, Telegram would reset the
        #    connection with no further clues."
        #
        # Beberapa auth_key yang sama dipakai beberapa sesi MTProto
        # sekaligus, dan Telegram menutup sambungannya tanpa penjelasan.
        # Karena itu jalur ini sekarang HARUS diminta secara sadar
        # lewat clone_senders=True.
        dc = await self.client._get_dc(dc_id)

        sender = MTProtoSender(
            self.client._sender.auth_key,
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

        return sender

    async def _open_connections(self, dc_id):
        """
        Kembalikan (list_conn, mode).

        mode "main"   -> memakai sender utama milik client. Satu soket,
                         sehat, dan TIDAK boleh diputus di akhir karena
                         client masih memakainya.
        mode "borrow" -> sender pinjaman dari Telethon. Satu soket
                         bersama per DC; dikembalikan lewat
                         _return_exported_sender.
        mode "clone"  -> beberapa soket yang meminjam auth_key utama.
                         Benar-benar paralel, tapi Telegram sering
                         memutusnya. Harus di-disconnect manual.
        """
        same_dc = dc_id == self.client.session.dc_id

        if same_dc and not self.clone_senders:
            # Sender utama client sudah terhubung dan sehat. Empat
            # request 1 MB bisa terbang bersamaan di atasnya -- MTProto
            # memang dirancang begitu -- tanpa satu pun sesi tambahan
            # yang membuat Telegram curiga.
            return [_Conn(self.client._sender)], "main"

        mode = "clone" if same_dc else "borrow"

        conns = []

        try:
            for _ in range(self.num_connections):
                conns.append(
                    _Conn(
                        await self._new_sender(dc_id, same_dc)
                    )
                )

            return conns, mode

        except Exception:
            # Jangan tinggalkan koneksi menggantung kalau gagal di tengah.
            await self._close_connections(conns, mode)
            raise

    async def _drop_sender(self, sender, mode):
        if mode == "main":
            # Ini sender milik client. Memutusnya akan mematikan
            # seluruh sesi Telegram, bukan cuma download ini.
            return

        try:
            if mode == "clone":
                await sender.disconnect()
            else:
                await self.client._return_exported_sender(sender)
        except Exception:
            # Pelepasan koneksi tidak boleh menggagalkan download
            # yang sudah selesai.
            pass

    async def _close_connections(self, conns, mode):
        for conn in conns:
            await self._drop_sender(conn.sender, mode)

    async def _renew(self, conn, dc_id, mode, stale):
        """
        Ganti sender yang koneksinya sudah tidak sehat dengan yang baru.

        `stale` adalah sender yang tadi gagal. Kalau ternyata koneksinya
        sudah diganti worker lain, tidak ada yang perlu dikerjakan --
        tanpa penjagaan ini, 12 worker yang jatuh bersamaan akan membuka
        12 koneksi baru sekaligus.

        Kalau pembuatan yang baru gagal, sender lama dibiarkan terpasang
        -- Telethon masih bisa menyambung ulang sendiri, dan percobaan
        berikutnya akan mencoba mengganti lagi.
        """
        if mode != "clone":
            # "main"   -> sender milik client; Telethon yang mengurus.
            # "borrow" -> _borrow_exported_sender mengembalikan objek
            #             yang SAMA untuk satu DC, ia cuma menghitung
            #             peminjaman. "Mengganti" di sini tidak
            #             menghasilkan koneksi baru sama sekali; yang
            #             terjadi hanya naik-turun penghitung, dan kalau
            #             sempat menyentuh nol Telethon memutus soket
            #             yang masih dipakai worker lain.
            #
            # Keduanya menyambung ulang sendiri. Tugas kita cuma
            # menunggu dan mencoba lagi.
            return False

        async with conn.lock:
            if conn.sender is not stale:
                return True

            try:
                new_sender = await self._new_sender(dc_id, True)
            except Exception:
                return False

            conn.sender = new_sender
            conn.renewals += 1

        await self._drop_sender(stale, mode)

        return True

    # --------------------------------------------------------
    # Chunk
    # --------------------------------------------------------

    async def _download_chunk(
        self,
        conn,
        location,
        offset,
        gate,
        limiter,
        dc_id,
        mode,
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

            # Dicatat sebelum dikirim: kalau gagal, inilah sender yang
            # harus diganti -- bukan sender yang barangkali sudah
            # dipasang worker lain sementara kita menunggu.
            sender = conn.sender

            try:
                result = await asyncio.wait_for(
                    sender.send(
                        GetFileRequest(
                            location=location,
                            offset=offset,
                            limit=self.chunk_size,
                        )
                    ),
                    timeout=self.request_timeout,
                )

                await limiter.release()
                await limiter.reward()

                return result.bytes

            except asyncio.CancelledError:
                await limiter.release()
                raise

            except Exception as error:
                await limiter.release()

                last_error = error

                seconds = flood_seconds(error)

                if seconds is None:
                    # Error nyata: potong jatah percobaan.
                    attempt += 1

                    if attempt > self.chunk_retries:
                        break

                    if is_connection_error(error):
                        # Koneksi diputus server. Perlakukan sebagai
                        # backpressure, sama seperti flood: tutup palang
                        # sebentar untuk SEMUA koneksi dan turunkan
                        # jatah paralel.
                        #
                        # Tanpa ini, satu koneksi yang diputus langsung
                        # disambung lagi dengan beban yang sama persis,
                        # dan Telegram memutusnya lagi. Itu yang membuat
                        # log dibanjiri "connection reset by peer" tanpa
                        # kecepatan pernah membaik.
                        generation = gate.pause(
                            RESET_PAUSE,
                            reason="reset",
                        )

                        await limiter.penalize(generation)

                        # Ganti soket mati dengan yang baru.
                        await self._renew(conn, dc_id, mode, sender)

                    # Backoff dengan jitter supaya semua koneksi tidak
                    # bangun serempak.
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

                # Palang ditutup lebih dulu supaya nomor gelombangnya
                # keluar; limiter memakai nomor itu agar satu gelombang
                # flood hanya menurunkan jatah SEKALI.
                generation = gate.pause(seconds)

                await limiter.penalize(generation)

        raise RuntimeError(
            f"Chunk offset {offset} gagal: {last_error}"
        )

    # --------------------------------------------------------
    # Disk
    # --------------------------------------------------------

    def _assert_disk_space(self, out_path, file_size):
        target_dir = os.path.dirname(os.path.abspath(out_path)) or "."

        os.makedirs(target_dir, exist_ok=True)

        # shutil.disk_usage jalan di Linux maupun Windows. os.statvfs
        # tidak ada di Windows, dan dulu itu membuat mode paralel
        # gagal seketika lalu diam-diam jatuh ke download satu koneksi.
        free_bytes = shutil.disk_usage(target_dir).free

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
    # Resume
    # --------------------------------------------------------

    @staticmethod
    def part_paths(out_path):
        return out_path + ".part", out_path + ".part.json"

    @classmethod
    def cleanup_partial(cls, out_path):
        """Buang sisa file .part. Dipanggil kalau sudah menyerah."""
        removed = False

        for path in cls.part_paths(out_path):
            try:
                if os.path.exists(path):
                    os.remove(path)
                    removed = True
            except OSError:
                pass

        return removed

    def _resume_offset(self, out_path, doc, file_size, resume):
        """
        Berapa byte dari .part yang masih bisa dipakai.

        Hanya potongan utuh yang dipakai: ekor yang tidak genap satu
        chunk dibuang, supaya offset request tetap rapi di kelipatan
        chunk_size.
        """
        part_path, meta_path = self.part_paths(out_path)

        fingerprint = {
            "document_id": str(getattr(doc, "id", "")),
            "size": int(file_size),
            "chunk_size": int(self.chunk_size),
        }

        usable = 0

        if resume and os.path.exists(part_path):
            meta = None

            try:
                with open(meta_path, "r", encoding="utf-8") as file:
                    meta = json.load(file)
            except (OSError, ValueError):
                meta = None

            if meta == fingerprint:
                have = min(os.path.getsize(part_path), file_size)

                usable = (have // self.chunk_size) * self.chunk_size

        if usable <= 0:
            self.cleanup_partial(out_path)

        try:
            with open(meta_path, "w", encoding="utf-8") as file:
                json.dump(fingerprint, file)
        except OSError:
            # Tanpa sidik jari, resume berikutnya batal -- tapi
            # download sekarang tetap boleh jalan.
            pass

        return usable

    def _open_part(self, out_path, resume_bytes, hasher):
        part_path, _ = self.part_paths(out_path)

        if resume_bytes <= 0:
            return open(part_path, "wb")

        file = open(part_path, "r+b")

        try:
            file.truncate(resume_bytes)

            if hasher is not None:
                # Checksum harus mencakup bagian yang sudah ada di
                # disk, bukan cuma yang diunduh sesi ini.
                file.seek(0)

                left = resume_bytes

                while left > 0:
                    block = file.read(min(1024 * 1024, left))

                    if not block:
                        break

                    hasher.update(block)
                    left -= len(block)

            file.seek(resume_bytes)

        except BaseException:
            file.close()
            raise

        return file

    # --------------------------------------------------------
    # Download
    # --------------------------------------------------------

    async def download(
        self,
        message,
        out_path,
        progress_callback=None,
        hasher=None,
        resume=True,
    ):
        """
        Download `message` ke `out_path` secara paralel.

        hasher : objek hashlib opsional (mis. hashlib.sha256()). Kalau
                 diisi, checksum dihitung SAMBIL menulis file sehingga
                 tidak perlu membaca ulang seluruh file setelahnya.

        resume : lanjutkan dari `<out_path>.part` kalau sisa download
                 sebelumnya masih cocok dengan dokumen yang sama.
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

        resume_bytes = self._resume_offset(
            out_path,
            doc,
            file_size,
            resume,
        )

        start_index = resume_bytes // self.chunk_size

        part_path, meta_path = self.part_paths(out_path)

        gate = _FloodGate()

        # Jumlah request bersamaan sekarang lepas dari jumlah koneksi.
        # Mulai dari separuh plafon lalu memanjat kalau lancar: naik
        # bertahap jauh lebih jarang memicu flood daripada langsung
        # menembak di plafon sejak byte pertama.
        limiter = _AdaptiveLimiter(
            start=max(
                self.num_connections,
                self.max_inflight // 2,
            ),
            maximum=self.max_inflight,
            floor=self.num_connections,
        )

        # Sejauh mana worker boleh berlari mendahului penulis. Ini yang
        # mengunci pemakaian RAM: chunk yang sudah tiba tapi belum
        # gilirannya ditulis menunggu di sini.
        window = self.max_inflight * 2

        conns, mode = await self._open_connections(dc_id)

        state = {
            "claim": start_index,
            "write": start_index,
            "error": None,
            "stop": False,
        }

        pending = {}
        cond = asyncio.Condition()

        loop = asyncio.get_running_loop()

        writer_pool = ThreadPoolExecutor(
            max_workers=1,
            thread_name_prefix="dl-writer",
        )

        downloaded = resume_bytes
        started_at = time.monotonic()

        async def worker(conn):
            while True:
                async with cond:
                    while (
                        not state["stop"]
                        and state["error"] is None
                        and state["claim"] < total_chunks
                        and state["claim"] - state["write"] >= window
                    ):
                        await cond.wait()

                    if (
                        state["stop"]
                        or state["error"] is not None
                        or state["claim"] >= total_chunks
                    ):
                        return

                    index = state["claim"]
                    state["claim"] += 1

                try:
                    data = await self._download_chunk(
                        conn,
                        location,
                        index * self.chunk_size,
                        gate,
                        limiter,
                        dc_id,
                        mode,
                    )

                except asyncio.CancelledError:
                    raise

                except BaseException as error:
                    async with cond:
                        if state["error"] is None:
                            state["error"] = error

                        cond.notify_all()

                    return

                async with cond:
                    pending[index] = data
                    cond.notify_all()

        def sink(data):
            file.write(data)

            if hasher is not None:
                hasher.update(data)

        file = self._open_part(out_path, resume_bytes, hasher)

        worker_tasks = []

        try:
            # Satu worker = satu request yang bisa terbang. Jumlahnya
            # mengikuti plafon in-flight, BUKAN jumlah koneksi: kalau
            # worker hanya sebanyak koneksi, tiap koneksi selamanya
            # cuma punya satu request di udara dan plafon limiter tidak
            # pernah tercapai. Beberapa worker berbagi satu koneksi --
            # persis kemampuan yang memang disediakan MTProto.
            worker_tasks = [
                asyncio.ensure_future(
                    worker(conns[slot % len(conns)])
                )
                for slot in range(self.max_inflight)
            ]

            while state["write"] < total_chunks:
                async with cond:
                    while state["write"] not in pending:
                        if state["error"] is not None:
                            raise state["error"]

                        await cond.wait()

                    data = pending.pop(state["write"])
                    state["write"] += 1

                    cond.notify_all()

                # Menulis ke disk dan mengaduk checksum dilakukan di
                # thread lain, jadi request tetap terbang selama disk
                # sibuk.
                await loop.run_in_executor(writer_pool, sink, data)

                downloaded += len(data)

                if progress_callback:
                    progress_callback(downloaded, file_size)

            file.close()
            file = None

            os.replace(part_path, out_path)

            try:
                os.remove(meta_path)
            except OSError:
                pass

        finally:
            # Suruh worker berhenti, apa pun yang terjadi di atas.
            async with cond:
                state["stop"] = True
                cond.notify_all()

            for task in worker_tasks:
                task.cancel()

            if worker_tasks:
                await asyncio.gather(
                    *worker_tasks,
                    return_exceptions=True,
                )

            pending.clear()

            if file is not None:
                # Gagal di tengah: file .part SENGAJA ditahan supaya
                # percobaan berikutnya melanjutkan, bukan mengulang.
                try:
                    file.close()
                except OSError:
                    pass

            writer_pool.shutdown(wait=False)

            await self._close_connections(conns, mode)

        self.last_stats = {
            "bytes": downloaded,
            "resumed_bytes": resume_bytes,
            "seconds": time.monotonic() - started_at,
            "flood_hits": gate.flood_hits,
            "reset_hits": gate.reset_hits,
            "flood_seconds": round(gate.total_paused, 1),
            "connections": len(conns),
            # Berapa SOKET yang benar-benar berbeda. Untuk video di DC
            # lain, Telethon memberi satu sender pinjaman yang sama
            # berapa kali pun diminta -- jadi angka ini bisa 1 meski
            # num_connections 4. Lebih baik ditampilkan apa adanya
            # daripada melaporkan "4 koneksi" yang tidak pernah ada.
            "unique_senders": len({id(conn.sender) for conn in conns}),
            "mode": mode,
            "reconnects": sum(conn.renewals for conn in conns),
            "inflight_start": limiter.start_limit,
            "inflight_min": limiter.min_limit_seen,
            "inflight_max": limiter.max_limit_seen,
            "inflight_end": limiter.limit,
        }

        return out_path
