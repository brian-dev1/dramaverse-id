# Dokumentasi Telegram

## Aturan tunggal

**Tidak ada satu pun `Http::` ke api.telegram.org di luar `TelegramClient`.**
Seluruh pemanggilan lewat `TelegramServiceInterface`.

Sebelum Sprint 8.1 ada tiga jalur terpisah, masing-masing dengan token,
timeout, dan penanganan galat sendiri — salah satunya membaca kunci config
yang berbeda. Diperiksa otomatis oleh `tools/audit-sprint-8-1.py`.

## Lapisannya

```
Handler / Job / Controller
        v
TelegramServiceInterface     sembilan method Bot API + call()/query()
        v
TelegramClientInterface      HTTP, timeout, retry, rate limit, redaksi token
        v
              api.telegram.org
```

Kegagalan **dilempar** sebagai `TelegramException`, tidak dikembalikan sebagai
array yang boleh diabaikan. Tanyakan sebabnya pada exception-nya:
`isBlockedByUser()`, `isChatNotFound()`, `isRateLimited()`, `isTokenProblem()`.

## Token tidak pernah masuk log

Token ada di dalam URL setiap permintaan, dan pesan galat Guzzle memuat URL
itu apa adanya. `TelegramClient::redact()` menggantinya sebelum apa pun
ditulis, dan exception Guzzle **tidak** dipasang sebagai `previous` — Laravel
menuliskan seluruh rantai exception ke log saat ada yang tidak tertangkap.

Periksa sendiri: `grep -i "bot[0-9]" storage/logs/laravel.log` harus kosong.

## Video: sekali kirim, dipakai selamanya

Telegram menyimpan berkas yang pernah dikirim dan memberi `file_id`. Mengirim
ulang ke penonton berikutnya cukup menyebut file_id itu — nol byte keluar dari
server, nol bandwidth bucket, selesai dalam milidetik.

| Kapan | Siapa | Apa |
|---|---|---|
| Sekali per berkas | admin, lewat antrean | storage provider ke Telegram, simpan `file_id` |
| Setiap ditonton | bot, seketika | kirim `file_id` |

**Bot tidak pernah mengunggah video saat pengguna memintanya.** Kalau belum
tersinkron, bot mengatakannya terus terang.

Cloudflare R2 masuk di langkah pertama sebagai storage provider. Setelah R2
dijadikan default, unggahan video baru masuk ke R2. Admin lalu menjalankan
sinkronisasi Telegram dari `/admin/telegram/sync` atau antrean otomatis; service
akan membaca video dari R2, mengirimnya sekali ke channel privat
`TELEGRAM_STORAGE_CHAT_ID`, dan menyimpan `file_id`.

Aturan gratis/berbayar tidak berubah oleh R2. Saat penonton menekan tombol
episode di bot, `TelegramDeliveryService` tetap bertanya ke
`EpisodeAccessService`: episode non-VIP boleh ditonton siapa pun, episode VIP
hanya dikirim ke admin atau pengguna premium yang masih aktif.

### Batas 50 MB

Bot API menolak berkas di atas 50 MB. Itu batas Telegram, bukan batas
aplikasi, dan tidak ada rancangan di sisi kita yang bisa melewatinya.

Jalan keluarnya: **Local Bot API Server** sendiri, lalu arahkan
`TELEGRAM_API_URL` ke sana — batasnya naik jadi 2000 MB. Jalurnya siap
(`api_url` dan `upload_max_mb` keduanya dari config, nol URL yang dipatok di
kode), tetapi **belum pernah diuji**.

## Deep link

```
https://t.me/<bot>?start=watch_<episodeId>
https://t.me/<bot>?start=drama_<dramaId>
```

Parameter `start` dibatasi 64 karakter dan hanya menerima huruf, angka, garis
bawah, dan tanda hubung — karena itu id numerik, bukan slug.

Tombol "Tonton di Telegram" di halaman episode hanya dirender bila videonya
memang sudah ada di Telegram.

## Menu bot

Diatur dari `/admin/telegram/menu`. `TelegramMenuAction` adalah satu-satunya
daftar yang menghubungkan pilihan di panel, `callback_data`, dan handler-nya.

Tombol **Buka Website** terkunci: tidak bisa dihapus maupun dinonaktifkan. Ia
satu-satunya jalan pengguna masuk ke situs, dan tidak ada login email untuk
pengguna biasa.

## Perintah

```bash
php artisan telegram:test                       # token, koneksi, webhook
php artisan telegram:test --chat=<telegram_id>  # kirim pesan sungguhan
php artisan telegram:auto retry|health|cleanup|all
```

`getMe` membuktikan token dan jaringan; ia **tidak** membuktikan pesan bisa
sampai ke orang. Untuk itu sebut chat id-nya.

## Chat penyimpanan

`TELEGRAM_STORAGE_CHAT_ID` harus channel **PRIVAT** tempat bot jadi admin.
Isinya seluruh katalog video termasuk yang berbayar; siapa pun yang bisa
membukanya menonton tanpa melewati pemeriksaan membership.
