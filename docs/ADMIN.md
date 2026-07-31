# Panduan Admin

Masuk di `/admin/login` dengan email dan kata sandi. Panel admin **tidak**
memakai Telegram; itu hanya untuk pengguna biasa.

## Peta menu

| Menu | Yang dikerjakan di sana |
|---|---|
| Dashboard | Ringkasan harian |
| Analytics, Laporan | Angka dan tujuh laporan yang bisa diekspor |
| Drama, Episode, Genre, Negara, Banner | Katalog |
| Kelola aset (per drama) | Poster, cover, banner, galeri, subtitle |
| Unggah video (per episode) | Video episode ke storage provider |
| Upload Queue, Batch Upload | Antrean unggahan dan unggah massal |
| Storage Manager, Storage Monitoring, File Manager | Provider dan berkas |
| Telegram | Status bot, broadcast |
| Menu Telegram | Susunan tombol bot |
| Sinkron Telegram | Video dari storage ke Telegram |
| Log Telegram, Log Pembayaran, Log Sistem | Penelusuran |
| Membership, Subscription | Paket dan langganan |
| Invoice, Payment Provider | Tagihan dan gateway |
| Pengguna, Peran | Akun dan izin |
| Pengaturan | Identitas situs |
| Monitoring | Kesehatan sistem |

## Urutan menerbitkan satu episode

1. **Drama** — buat dramanya, lalu **Kelola aset** untuk poster dan cover.
2. **Episode** — buat episodenya. Beri tanggal tayang bila ingin terjadwal.
3. **Unggah video** — berkasnya masuk antrean, lalu naik ke storage provider.
   Pantau di **Upload Queue**.
4. **Sinkron Telegram** — tekan Sinkronkan. Video dikirim SEKALI ke channel
   penyimpanan, dan `file_id`-nya disimpan.
5. Sesudah itu setiap penonton menerima video lewat `file_id` — tidak ada
   bandwidth bucket yang terpakai lagi.

Episode berjadwal terbit sendiri lewat `episodes:publish` yang dijalankan
scheduler tiap lima menit.

## Aksi massal Sinkron Telegram

| Tombol | Yang terjadi |
|---|---|
| Bulk Sync | Antrekan sinkronisasi. Yang tidak memenuhi syarat dilewati beserta alasannya |
| Bulk Retry | Ulangi yang gagal, sampai batas `TELEGRAM_SYNC_MAX_RETRY` |
| Bulk Cancel | Batalkan yang berstatus Menunggu. **Tidak** menghentikan yang sedang berjalan |
| Refresh Status | Buang cache dan lepaskan baris yang tersangkut |
| Verifikasi file_id | Tanyakan ke Telegram apakah berkasnya masih ada |

Maksimal 100 baris sekali jalan. Semuanya lewat antrean.

## Pembayaran

**Payment Provider** — tambahkan provider, isi kredensial, aktifkan, tandai
satu sebagai default. Kredensial disimpan terenkripsi.

Driver `manual` (transfer bank) bekerja tanpa API mana pun dan diverifikasi
admin dari halaman **Invoice**. Driver `trakteer` memakai webhook. Midtrans,
Xendit, dan Tripay masih kerangka — terdaftar, tetapi menolak dipakai sampai
diselesaikan.

**Invoice** — cari, saring, urutkan, ekspor. Untuk transfer manual: buka
tagihannya, cocokkan dengan mutasi rekening, lalu **Verifikasi Manual**.
Membership aktif otomatis sesudahnya.

Verifikasi manual melewati jalur yang sama dengan callback otomatis, jadi
seluruh penjagaan — nominal, perpindahan status, idempotensi — tetap berlaku.

## Yang tidak bisa dibatalkan

- **Menghapus storage provider** — soft delete, bisa dipulihkan. Berkas di
  bucket tidak ikut terhapus.
- **Menghapus video episode** — belum ada tombolnya. Mengunggah ulang
  menggantinya.
- **Mengganti `APP_KEY`** — seluruh kredensial provider harus dimasukkan ulang.
