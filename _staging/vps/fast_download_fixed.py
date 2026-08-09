"""
Modul download paralel multi-koneksi untuk Telethon.

Membagi file jadi beberapa bagian (part) dan mendownloadnya secara
bersamaan lewat beberapa koneksi ke Telegram DC, lalu menuliskannya ke
disk sesuai urutan.

Menangani 2 kasus:
1. Video di DC BERBEDA dari koneksi utama -> pakai exported sender
   (cara standar Telethon untuk pinjam koneksi ke DC lain).
2. Video di DC SAMA dengan koneksi utama -> Telegram tidak mengizinkan
   export authorization ke DC yang sama (karena sudah authorized di
   situ). Solusinya: buka koneksi baru langsung pakai auth_key yang
   sudah ada, tanpa proses export/import.

PERBEDAAN DARI VERSI LAMA
-------------------------
Versi lama menumpuk SELURUH file di dict `results` dan baru menulis ke
disk setelah semua chunk selesai. Untuk video 2 GB itu berarti 2 GB RAM
--> VPS kecil langsung masuk swap.

Versi ini menulis tiap batch ke disk begitu batch selesai, lalu
membuang isinya dari memori. Pemakaian RAM jadi tetap (konstan),
kira-kira:

    batch_size * CHUNK_SIZE = (num_connections * BATCH_MULTIPLIER) * 512 KB

Dengan default 4 koneksi -> sekitar 6 MB, berapa pun ukuran videonya.
"""

import asyncio
import math
import os

from telethon.errors import FloodWaitError
from telethon.network import MTProtoSender
from telethon.tl.functions.upload import GetFileRequest
from telethon.tl.types import InputDocumentFileLocation

# 512 KB per request (kelipatan wajib Telegram).
CHUNK_SIZE = 512 * 1024

# Berapa chunk yang boleh "in-flight" per koneksi.
# Makin besar = makin cepat tapi makin boros RAM.
BATCH_MULTIPLIER = 3

# Batas aman koneksi paralel. Lebih dari ini Telegram gampang
# melempar FLOOD_WAIT dan VPS kecil mulai tersengal.
MAX_CONNECTIONS = 8

# Berapa kali satu chunk dicoba ulang sebelum menyerah.
CHUNK_RETRIES = 3


class NotEnoughDiskSpace(Exception):
    """Sisa disk tidak cukup untuk menampung file."""


class ParallelDownloader:
    def __init__(
        self,
        client,
        num_connections=4,
        batch_multiplier=BATCH_MULTIPLIER,
        min_free_bytes=512 * 1024 * 1024,
    ):
        """
        client          : TelegramClient yang sudah terhubung.
        num_connections : jumlah koneksi paralel (dibatasi 1..MAX_CONNECTIONS).
        batch_multiplier: chunk in-flight per koneksi. Turunkan kalau RAM tipis.
        min_free_bytes  : sisa disk yang harus tetap tersedia setelah download.
        """
        self.client = client
        self.num_connections = max(
            1,
            min(int(num_connections), MAX_CONNECTIONS),
        )
        self.batch_multiplier = max(1, int(batch_multiplier))
        self.min_free_bytes = int(min_free_bytes)

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

    async def _download_chunk(self, sender, location, offset, sem):
        """
        Ambil satu chunk. `limit` selalu CHUNK_SIZE penuh -- itu kelipatan
        standar yang diizinkan Telegram. Untuk chunk terakhir, server
        otomatis mengembalikan lebih sedikit byte daripada yang diminta;
        itu normal, BUKAN error. Kalau limit dikecilkan manual di sini,
        Telegram menolak dengan "invalid limit".
        """
        last_error = None

        for attempt in range(CHUNK_RETRIES):
            try:
                async with sem:
                    result = await sender.send(
                        GetFileRequest(
                            location=location,
                            offset=offset,
                            limit=CHUNK_SIZE,
                        )
                    )

                return offset, result.bytes

            except FloodWaitError as error:
                # Telegram minta kita berhenti sejenak. Patuhi.
                last_error = error

                await asyncio.sleep(error.seconds + 1)

            except Exception as error:
                last_error = error

                # Backoff sederhana: 1s, 2s, 4s.
                await asyncio.sleep(2 ** attempt)

        raise RuntimeError(
            f"Chunk offset {offset} gagal setelah "
            f"{CHUNK_RETRIES} percobaan: {last_error}"
        )

    # --------------------------------------------------------
    # Disk
    # --------------------------------------------------------

    def _assert_disk_space(self, out_path, file_size):
        target_dir = os.path.dirname(os.path.abspath(out_path)) or "."

        os.makedirs(target_dir, exist_ok=True)

        free_bytes = os.statvfs(target_dir).f_bavail * os.statvfs(
            target_dir
        ).f_frsize

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

        total_chunks = math.ceil(file_size / CHUNK_SIZE)
        offsets = [i * CHUNK_SIZE for i in range(total_chunks)]

        batch_size = self.num_connections * self.batch_multiplier

        senders, same_dc = await self._get_dc_senders(dc_id)
        sem = asyncio.Semaphore(self.num_connections)

        downloaded = 0

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
                            sem,
                        )
                        for index, offset in enumerate(batch)
                    ]

                    batch_results = await asyncio.gather(*tasks)

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

        return out_path
