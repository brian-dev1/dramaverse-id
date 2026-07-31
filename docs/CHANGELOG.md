# Changelog

Setiap fase punya dokumen lengkapnya sendiri di akar repo
(`SPRINT-*-SELESAI.md`) berisi keputusan desain beserta alasannya, bug yang
ditemukan, dan apa yang sengaja tidak dikerjakan.

## Phase 12 — Final Launch & Optimization

Pemeriksaan seluruh proyek dengan alat yang **mencari masalah**, bukan
menegaskan yang sudah diketahui.

- **Bug ditemukan:** `episodes:publish` ada sejak Sprint 6 tetapi tidak pernah
  dijadwalkan — episode berjadwal tidak pernah terbit sendiri.
- 31 kelas mati dihapus, beserta repository dan job yang ikut yatim.
- Blok config `storage.limits` dibuang: tiga kunci yang tidak pernah dibaca
  siapa pun, salah satunya berpura-pura mengatur batas waktu S3.
- Kode kembar disatukan: `Bytes::forHumans()`, dan tiga trait untuk log
  pembayaran, normalisasi ekspor, serta checksum berkas.
- `php artisan env:check` — menolak peluncuran bila environment belum layak.
- Empat belas dokumen di `docs/`.

Detail: `SPRINT-12-SELESAI.md`.

## Phase 11 — Analytics & Business Intelligence

Dashboard lima seksi dan tujuh laporan yang bisa diekspor. Sumber pendapatan
diperbaiki.

## Phase 10 — Payment & Membership

Sistem pembayaran modular: provider tidak dipatok di kode, Business Logic
Membership terpisah dari gateway.

- **Bug ditemukan:** `users.is_premium` dan `episodes.access_type` dibaca kode
  tetapi **tidak pernah ada di migration mana pun**. Akibatnya `canWatch()`
  selalu bernilai salah — tidak ada satu pun episode yang bisa ditonton siapa
  pun, termasuk yang gratis. Gagal sepenuhnya diam.

## Phase 9 — Monitoring & Backup

Dashboard kesehatan, cadangan terjadwal beserta verifikasinya, log sistem,
audit autentikasi, indeks produksi.

## Phase 8 — Telegram Integration

- **8.1** Core service. Tiga jalur HTTP ke Telegram disatukan jadi satu.
- **8.2–8.6** Sinkronisasi video lewat `file_id`, deep link, playback,
  membership, riwayat dan favorit yang sinkron dua arah.
- **8.7–8.9** Admin tools, aksi massal, otomatisasi, scheduler, notifikasi,
  pembatas laju, cache.

## Phase 7 — Multi Storage

- **7.1–7.3** Fondasi, Storage Manager, Test Connection.
- **7.4** Storage Engine — satu-satunya pintu ke penyimpanan.
- **7.5–7.6** Upload video episode dan aset drama.
- **7.7** Queue & background upload.
- **7.8–7.9** Monitoring, File Manager, Batch Upload.

## Phase 6 — Panel Admin

CRUD katalog, dashboard, membership, subscription, pengguna, broadcast
Telegram, pengaturan, peran dan izin.

## Phase 0–2 — Fondasi

Audit awal yang menemukan tiga blocker, penulisan ulang migration, penyusunan
ulang routing, pemisahan data dummy.
