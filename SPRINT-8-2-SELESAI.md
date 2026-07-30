# Sprint 8.2–8.6 — Telegram Integration

Selesai: 31 Juli 2026

Website, Storage, Database, dan Telegram Bot kini saling terhubung. Telegram
Core Service (8.1), Storage Engine (7.4), modul unggah (7.5–7.6), dan Queue
(7.7) **tidak diubah satu baris pun** — sprint ini hanya memakainya.

Belum ada payment gateway, analytics, recommendation engine, AI subtitle,
AI translation, CDN, live streaming, auto encoding, dan mobile app.

---

## Gagasan yang menentukan seluruh rancangan

Video **tidak pernah** dikirim dua kali ke Telegram.

Telegram menyimpan berkas yang pernah dikirim dan memberi `file_id`. Mengirim
video yang sama ke seribu pengguna berikutnya cukup dengan menyebut file_id
itu: tidak ada byte yang keluar dari server kita, tidak ada bandwidth bucket
yang terpakai, dan pengirimannya selesai dalam milidetik alih-alih menit.

Dari situ seluruh pembagian tugasnya mengikuti:

| Kapan | Siapa | Apa |
|---|---|---|
| Sekali per berkas | admin, lewat antrean | storage provider → Telegram, simpan `file_id` |
| Setiap kali ditonton | bot, seketika | kirim `file_id` ke pengguna |

Bot **tidak pernah** mengunggah video saat pengguna memintanya. Kalau video
belum tersinkron, bot mengatakannya terus terang — pengguna yang menunggu
berpuluh menit sambil menatap layar diam adalah kegagalan, bukan kesabaran.

---

## Alur lengkap

```
Admin  →  /admin/telegram/sync  →  SyncEpisodeVideoToTelegram (antrean)
                                        |
                                        v
                              TelegramVideoSyncService
                                        |  readStream dari StorageEngine
                                        |  ke berkas sementara
                                        v
                              TelegramServiceInterface::sendVideo()
                                        |
                                        v
                              episode_videos.telegram_file_id

Pengguna  →  t.me/bot?start=watch_42  →  StartHandler
                                              |  sinkronkan akun
                                              v
                                        WatchHandler
                                              v
                                  TelegramDeliveryService
                                    |  EpisodeAccessService  (membership)
                                    |  telegram_file_id      (storage)
                                    |  WatchHistoryService   (riwayat)
                                    v
                              video + inline keyboard
```

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Enums/TelegramSyncStatus.php` | Pending, Processing, Synced, Failed |
| `database/migrations/..._add_telegram_sync_to_episode_videos_table.php` | 7 kolom metadata |
| `app/Services/Telegram/TelegramVideoSyncService.php` | Storage → Telegram, sekali per berkas |
| `app/Services/Telegram/TelegramDeliveryService.php` | Telegram → pengguna, dengan seluruh penjagaan |
| `app/Jobs/SyncEpisodeVideoToTelegram.php` | Sinkronisasi di antrean |
| `app/Support/TelegramDeepLink.php` | Menyusun dan membaca `?start=` |
| `app/Telegram/Keyboards/EpisodeKeyboard.php` | Prev / Daftar / Next / Favorit / Website |
| `app/Telegram/Handlers/WatchHandler.php` | Permintaan menonton, dua jalan masuk |
| `app/Telegram/Handlers/EpisodeListHandler.php` | Daftar episode berhalaman |
| `app/Http/Controllers/Admin/TelegramSyncController.php` | Sync, Retry, Sync semua |
| `resources/views/web/pages/admin/telegram-sync.blade.php` | Panel sinkronisasi |
| `tools/audit-sprint-8-2.py` | 125 pemeriksaan |

## Berkas yang disunting

| Berkas | Perubahan |
|---|---|
| `app/Models/EpisodeVideo.php` | 7 kolom + `isSyncedToTelegram()` |
| `config/telegram.php` + `.env.example` | `storage_chat_id`, `sync.*`, `episode_page_size` |
| `app/Telegram/Handlers/StartHandler.php` | Deep link, sinkronisasi akun didahulukan |
| `app/Telegram/Handlers/CallbackHandler.php` | Callback berparameter + penanganan galat |
| `app/Telegram/Handlers/SearchHandler.php` | Hasil pencarian jadi tombol |
| `app/Telegram/Handlers/{ContinueWatching,Favorite,History,Premium}Handler.php` | Data sungguhan, bukan teks tetap |
| `app/Telegram/Handlers/{Help,Latest,Profile,Trending,Website}Handler.php` | `answerCallbackQuery` ganda dibuang |
| `routes/web.php`, `resources/views/web/layouts/admin.blade.php` | 4 route + menu |
| `resources/views/web/pages/episode.blade.php` | Tombol "Tonton di Telegram" |

---

## Keputusan desain

### Aturan bisnis tidak ditulis ulang di lapisan Telegram

`TelegramDeliveryService` menanyakan hak menonton ke `EpisodeAccessService` —
service yang sama persis dengan yang dipakai pemutar di website. Riwayat lewat
`WatchHistoryService`, favorit lewat `FavoriteService`.

Ini yang membuat "sinkron website dan Telegram" sebenarnya tidak memerlukan
sinkronisasi apa pun. Tidak ada dua salinan data yang perlu disamakan; ada satu
data, dan dua tampilan yang membacanya. Episode yang baru ditonton di laptop
langsung muncul di "Lanjut Menonton" bot, dan favorit yang ditambahkan dari bot
langsung ada di halaman profil — bukan karena ada proses yang menyalinnya, tapi
karena keduanya menulis ke tempat yang sama lewat pintu yang sama.

Menyalin aturan "premium yang kedaluwarsa tidak boleh menonton" ke lapisan
Telegram akan menghasilkan dua definisi yang wajib dijaga tetap sama, dan yang
satu pasti akan tertinggal. Ada pemeriksaan otomatis yang melarang string
`is_premium` muncul di `TelegramDeliveryService`.

### Video dialirkan ke berkas sementara, bukan dimuat ke memori

`file_get_contents` pada video 400 MB menaikkan pemakaian memori PHP sebesar
itu juga, dan `memory_limit` worker akan menghentikannya di tengah jalan —
dengan pesan galat yang tidak menyebutkan videonya sama sekali.

Karena itu `stream_copy_to_stream` ke berkas sementara. Harganya: ruang disk
sementara sebesar berkasnya. Itu dibayar karena Bot API mengunggah multipart
dan butuh berkas yang bisa dibaca ulang.

Berkasnya dihapus di blok `finally` — **termasuk saat gagal**. Sepuluh
kegagalan yang meninggalkan sisa sudah cukup untuk memenuhi disk VPS, dan itu
persis kelas masalah yang masih tercatat untuk berkas staging unggahan di
STATUS.md.

### Status SYNCED menolak sinkronisasi ulang

Mengirim ulang isi yang sama hanya menghasilkan `file_id` kedua untuk berkas
yang sama: kuota terpakai, waktu terpakai, dan sekarang ada dua id yang menunjuk
hal yang sama tanpa ada yang tahu mana yang dipakai.

PROCESSING juga ditolak, supaya tombol Sync yang ditekan berulang saat job
pertama masih berjalan tidak mengirim berkas dua kali.

### `telegram_file_id` tidak unique, `telegram_unique_file_id` juga tidak

Telegram memberi `file_id` **berbeda untuk bot yang berbeda** pada berkas yang
sama. Mengunci keunikannya akan menolak penyimpanan yang sah begitu botnya
diganti.

`telegram_unique_file_id` justru stabil lintas bot — itu yang dipakai untuk
mengenali "ini berkas yang sama". Tetap tidak unique, karena dua episode boleh
saja berisi berkas identik.

### Deep link memakai id numerik, bukan slug

Telegram membatasi parameter `start` sepanjang **64 karakter** dan hanya
menerima huruf, angka, garis bawah, dan tanda hubung. Judul drama berbahasa
Korea atau Mandarin akan melewati batas itu sebelum sampai ke nomor episodenya.

Id-nya divalidasi dengan `ctype_digit`, bukan dicast begitu saja: `(int) "12abc"`
menghasilkan 12, dan `(int) "-4"` menghasilkan -4. Keduanya terlihat seperti id
yang sah padahal datang dari tautan yang dikarang.

### Akun disinkronkan SEBELUM deep link diproses

Urutan ini menentukan, dan mudah terbalik. Tautan menonton yang dibuka orang
yang belum pernah membuka bot sampai di `StartHandler` sebagai pengguna yang
belum ada di basis data kita — dan pemeriksaan membership tanpa pengguna selalu
menjawab "tidak boleh".

Menyinkronkan belakangan berarti **pemilik langganan premium ditolak pada klik
pertamanya**, lalu berhasil pada klik kedua. Gejala yang sangat sulit
dilaporkan orang.

Ada pemeriksaan otomatis yang membandingkan posisi kedua baris itu di dalam
berkas.

### `answerCallbackQuery` hanya dari satu tempat

Telegram menerima **satu** jawaban per penekanan tombol. Yang kedua ditolak.

Sebelum sprint ini, `CallbackHandler` memanggilnya, lalu handler yang
dituju memanggilnya lagi. Selama nilai baliknya diabaikan, itu tidak terlihat.
Sejak 8.1 kegagalan Telegram dilempar — jadi pemanggilan kedua akan
membatalkan seluruh permintaan hanya karena konfirmasi kosmetik gagal.

Sekarang hanya `CallbackHandler` yang memanggilnya, sekali, di awal — dan
kegagalannya sengaja ditelan di sana, karena `callback_query_id` memang
kedaluwarsa setelah beberapa detik dan tombol pada pesan lama akan selalu
ditolak.

`WebsiteHandler` yang dulu memakainya untuk menyampaikan pesan sekarang
mengirim pesan biasa.

### Susunan `callback_data` dan batas 64 byte

```
w:<episodeId>            putar episode
el:<dramaId>:<halaman>   daftar episode
fv:<dramaId>             tambah/hapus favorit
up                       penawaran premium
```

Awalan dipisah titik dua. Nilai menu (`search`, `help`) tidak pernah memuat
titik dua, jadi keduanya tidak bisa bentrok — dan menu yang diatur admin dari
panel tetap lewat jalur yang sama tanpa perubahan apa pun.

### Halaman daftar episode dijepit ke rentang yang sah

Nomor halaman datang dari `callback_data`, jadi nilainya bisa apa saja —
termasuk halaman 9 dari tombol lama yang menempel di pesan lama setelah
sebagian episode dihapus. `max(1, min($halaman, $totalHalaman))`.

Halamannya sendiri bukan hiasan: Telegram membatasi jumlah tombol pada satu
keyboard, dan drama dengan 100 episode akan membuat **seluruh pesannya
ditolak**, bukan cuma terpotong.

### Riwayat ditulis setelah video terkirim, bukan sebelum

Menulisnya lebih dulu membuat episode yang gagal terkirim tetap muncul di
"lanjut menonton" — di bot maupun di website, karena keduanya membaca tabel
yang sama.

### Tombol "Tonton di Telegram" di website hanya muncul bila videonya ada di sana

Tombol yang menjanjikan tontonan lalu dijawab "belum siap" oleh bot adalah dead
link versi lintas aplikasi. Aturan nomor 4 proyek ini melarangnya, dan alasannya
tidak berubah cuma karena tujuannya di luar situs.

### Job memakai `tries = 1`, pengulangan diurus service

Antrean yang mengulang akan mengulang **seluruh job**, termasuk mengunduh ulang
berkas dari bucket. Untuk kegagalan yang sudah pasti — berkas terlalu besar,
chat penyimpanan salah — itu membuang kuota bucket berkali-kali untuk hasil
yang sama.

Yang dipakai `retry_count` di basis data, dengan batas `TELEGRAM_SYNC_MAX_RETRY`,
dan tombol Retry di panel. Hook `failed()` tetap ada untuk melepaskan baris yang
tersangkut di PROCESSING saat worker dibunuh paksa — kelas masalah yang sudah
tercatat untuk antrean unggahan di STATUS.md.

### Chat penyimpanan harus privat

Isinya seluruh katalog video, termasuk yang berbayar. Siapa pun yang bisa
membuka channel itu bisa menontonnya tanpa melewati satu pun pemeriksaan
membership. Ditulis di `config/telegram.php` dan di `.env.example`.

---

## Batas yang tidak bisa disiasati kode

**Bot API menolak berkas di atas 50 MB.** Itu batas Telegram, bukan batas
aplikasi, dan tidak ada rancangan di sisi kita yang bisa melewatinya.

Video episode drama umumnya jauh lebih besar. Artinya, dalam keadaan bawaan,
sebagian besar katalog **tidak akan bisa disinkronkan**.

Jalan keluarnya satu: menjalankan **Local Bot API Server** sendiri, lalu
mengarahkan `TELEGRAM_API_URL` ke sana. Batasnya naik jadi 2000 MB. Jalurnya
sudah disiapkan sejak 8.1 — `api_url` dan `upload_max_mb` keduanya dari config,
dan tidak ada satu pun URL Telegram yang dipatok di kode.

Saya tidak bisa mengujinya dari sini, dan tidak menyembunyikan bahwa itu belum
pernah dijalankan. Ditolaknya berkas besar akan muncul sebagai pesan yang
menyebutkan angkanya dan menyebutkan jalan keluarnya, bukan sebagai timeout.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    72 blade, 0 bermasalah
python tools/check-css-coverage.py        256 kelas, semua punya aturan
python tools/check-php-structure.py       382 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
python tools/audit-sprint-8-1.py          81/81 lolos
python tools/audit-sprint-8-2.py          125/125 lolos
```

`audit-sprint-8-2.py` memeriksa integrasi, bukan cuma keberadaan berkas:

- video diambil dari Storage Engine, bukan dari komputer — `UploadedFile` dan
  `file_get_contents` dilarang muncul di service sinkronisasi
- pengiriman ke pengguna **tidak menyentuh storage sama sekali**
- aturan premium tidak ditulis ulang di lapisan Telegram
- handler tidak menembus service untuk membaca tabel sendiri
- `answerCallbackQuery` hanya dipanggil dari satu tempat
- setiap awalan `callback_data` yang dibuat keyboard punya cabang di router
- setiap handler baru benar-benar dipanggil dari suatu tempat
- setiap kunci config baru benar-benar dibaca kode
- sinkronisasi akun terjadi sebelum pemeriksaan deep link, dibandingkan lewat
  posisi barisnya

Satu GAGAL dari `verify-consistency.py` selama pengerjaan, dan itu **benar**:
`&rarr;` di halaman sinkron melanggar aturan ikon proyek. Diganti teks biasa.
Tidak ada kegagalan palsu kali ini — `code_only()` sudah dipakai sejak baris
pertama skrip auditnya, pelajaran dari 8.1.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
migration yang dijalankan, tidak ada video yang benar-benar dikirim.

---

## Belum dikerjakan (sengaja)

- **Pembayaran dari dalam bot.** Tombol Upgrade mengantar ke website. Payment
  gateway disebut eksplisit di luar scope.
- **Progres tontonan dari bot.** Telegram tidak memberi tahu detik ke berapa
  pengguna berhenti menonton — tidak ada callback untuk itu. Yang tercatat dari
  bot adalah "episode ini ditonton", bukan posisinya. Progres per detik tetap
  hanya dari pemutar website.
- **Sinkronisasi otomatis setelah unggah.** Video baru berstatus PENDING dan
  menunggu admin menekan Sync. Mengantrekannya otomatis berarti setiap unggahan
  langsung memakan kuota Telegram sebelum ada yang memutuskan berkas itu memang
  mau disajikan lewat bot.
- **Menghapus video dari chat penyimpanan.** Menghapus baris `episode_videos`
  tidak menghapus pesannya di Telegram. `telegram_message_id` sudah disimpan
  supaya bisa dikerjakan nanti.
- **Verifikasi `file_id` masih berlaku.** Telegram bisa membuang berkas lama.
  Belum ada perintah yang memeriksanya, sama seperti checksum bucket yang juga
  belum pernah diverifikasi ulang.
