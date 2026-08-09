"""
Modul download paralel multi-koneksi untuk Telethon.
Membagi file jadi beberapa bagian (part) dan mendownloadnya
secara bersamaan lewat beberapa koneksi ke Telegram DC,
lalu menggabungkannya kembali sesuai urutan.

Menangani 2 kasus:
1. Video di DC BERBEDA dari koneksi utama -> pakai exported sender
   (cara standar Telethon untuk pinjam koneksi ke DC lain).
2. Video di DC SAMA dengan koneksi utama -> Telegram tidak mengizinkan
   export authorization ke DC yang sama (karena sudah authorized di
   situ). Solusinya: buka koneksi baru langsung pakai auth_key yang
   sudah ada, tanpa proses export/import.
"""
import asyncio
import math

from telethon.network import MTProtoSender
from telethon.tl.functions.upload import GetFileRequest
from telethon.tl.types import InputDocumentFileLocation

CHUNK_SIZE = 512 * 1024  # 512 KB per request (kelipatan wajib Telegram)


class ParallelDownloader:
    def __init__(self, client, num_connections=4):
        self.client = client
        self.num_connections = num_connections

    async def _get_dc_senders(self, dc_id):
        """
        Kembalikan (list_senders, same_dc).
        same_dc True kalau senders dibuat manual (harus di-disconnect manual),
        False kalau dipinjam lewat _borrow_exported_sender (harus di-return
        lewat _return_exported_sender).
        """
        my_dc_id = self.client.session.dc_id

        if dc_id == my_dc_id:
            # DC sama dengan koneksi utama: reuse auth_key yang sudah ada,
            # buka koneksi TCP baru langsung tanpa export/import.
            dc = await self.client._get_dc(dc_id)
            auth_key = self.client._sender.auth_key

            senders = []
            for _ in range(self.num_connections):
                sender = MTProtoSender(auth_key, loggers=self.client._log)
                await sender.connect(self.client._connection(
                    dc.ip_address,
                    dc.port,
                    dc.id,
                    loggers=self.client._log,
                    proxy=self.client._proxy,
                    local_addr=self.client._local_addr,
                ))
                senders.append(sender)
            return senders, True
        else:
            # DC berbeda: cara standar, pinjam exported sender.
            senders = []
            for _ in range(self.num_connections):
                sender = await self.client._borrow_exported_sender(dc_id)
                senders.append(sender)
            return senders, False

    async def _release_senders(self, senders, same_dc):
        if same_dc:
            for sender in senders:
                await sender.disconnect()
        else:
            for sender in senders:
                await self.client._return_exported_sender(sender)

    async def _download_chunk(self, sender, location, offset, limit, sem):
        async with sem:
            result = await sender.send(
                GetFileRequest(location=location, offset=offset, limit=limit)
            )
            return offset, result.bytes

    async def download(self, message, out_path, progress_callback=None):
        doc = message.document if message.document else None
        if not doc:
            raise ValueError("Media bukan dokumen/video, tidak didukung mode paralel.")

        file_size = doc.size
        dc_id = doc.dc_id

        location = InputDocumentFileLocation(
            id=doc.id,
            access_hash=doc.access_hash,
            file_reference=doc.file_reference,
            thumb_size="",
        )

        senders, same_dc = await self._get_dc_senders(dc_id)
        sem = asyncio.Semaphore(self.num_connections)

        total_chunks = math.ceil(file_size / CHUNK_SIZE)
        offsets = [i * CHUNK_SIZE for i in range(total_chunks)]

        downloaded = 0
        results = {}

        try:
            # Proses chunk secara batch supaya nggak overload memory untuk file besar
            batch_size = self.num_connections * 3
            for batch_start in range(0, len(offsets), batch_size):
                batch = offsets[batch_start:batch_start + batch_size]
                tasks = []
                for i, offset in enumerate(batch):
                    sender = senders[i % len(senders)]
                    # PENTING: limit harus selalu CHUNK_SIZE penuh (kelipatan
                    # standar yang diizinkan Telegram), bukan sisa byte.
                    # Untuk chunk terakhir, server otomatis mengembalikan
                    # lebih sedikit byte daripada yang diminta -- itu normal,
                    # BUKAN error. Jika limit dikecilkan manual di sini,
                    # Telegram menolak dengan "invalid limit".
                    tasks.append(self._download_chunk(sender, location, offset, CHUNK_SIZE, sem))

                batch_results = await asyncio.gather(*tasks)
                for offset, data in batch_results:
                    results[offset] = data
                    downloaded += len(data)
                    if progress_callback:
                        progress_callback(downloaded, file_size)
        finally:
            await self._release_senders(senders, same_dc)

        with open(out_path, "wb") as f:
            for offset in offsets:
                f.write(results[offset])

        return out_path
