# Panduan Konfigurasi

Seluruh variabel ada di `.env.example` beserta keterangannya. Dokumen ini
menjelaskan yang paling menentukan dan yang paling sering salah.

## Aturan umum

**Setiap perubahan `.env` butuh `php artisan config:cache`.** Tanpa itu
nilainya tidak berlaku, dan tidak ada galat yang memberitahukannya.

**Baris kosong bukan berarti bawaan.** `TELEGRAM_TIMEOUT=` menghasilkan string
kosong, bukan nilai yang belum diset — argumen default `env()` tidak berlaku,
dan `(int) ''` menjadi 0. `config/telegram.php` dan `config/payment.php`
menjaga ini; config lain belum tentu.

## Yang menentukan

| Variabel | Kenapa penting |
|---|---|
| `APP_KEY` | Mengenkripsi kredensial storage dan payment. **Menggantinya membuat seluruh kredensial di basis data tidak terbaca dan harus dimasukkan ulang.** |
| `APP_DEBUG` | `true` di produksi menampilkan stack trace beserta isi environment kepada siapa pun yang memicu galat |
| `TELEGRAM_BOT_TOKEN` | Satu-satunya cara pengguna masuk. Tidak ada login email untuk pengguna biasa |
| `TELEGRAM_WEBHOOK_SECRET` | Tanpa ini endpoint webhook menerima permintaan dari siapa pun |
| `TELEGRAM_STORAGE_CHAT_ID` | Channel PRIVAT tempat video disimpan. Publik berarti seluruh katalog berbayar bisa ditonton tanpa membayar |
| `QUEUE_CONNECTION` | `sync` di produksi membuat unggahan memblokir halaman |

## Yang tidak ada di `.env`

Kredensial **storage provider** dan **payment provider** TIDAK ada di `.env`.
Keduanya ada di basis data, terenkripsi dengan `APP_KEY`, dan dimasukkan lewat
panel admin — supaya bisa diganti tanpa deploy saat satu provider bermasalah.

## Konfigurasi aplikasi

| Berkas | Isi |
|---|---|
| `config/telegram.php` | Bot, timeout, retry, rate limit, cache, otomatisasi |
| `config/storage.php` | Multi storage, engine, koleksi berkas |
| `config/payment.php` | Tagihan, verifikasi, cache membership |
| `config/backup.php` | Cadangan terjadwal |
| `config/analytics.php` | Cache dashboard |

Setiap kunci di kelima berkas itu dipakai kode. Diperiksa otomatis oleh
`python tools/audit-final.py`.
