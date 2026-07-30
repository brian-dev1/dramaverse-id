# Sprint 8.7–8.9 — Telegram Finalization

Selesai: 31 Juli 2026

Phase 8 selesai. Admin tools, otomatisasi, dan optimasi di atas seluruh
arsitektur Phase 7 dan Phase 8 sebelumnya — **tanpa mengubah satu pun fitur
yang sudah berjalan**.

Belum ada payment gateway, analytics, recommendation engine, AI subtitle,
AI translation, SEO, dan mobile app.

---

## Bug yang ditemukan lebih dulu

`ActivityLogger::log()` menerima `?Model` sebagai argumen ketiga, bukan `int`.
Sprint 8.1 dan 8.2 memanggilnya dengan `$video->id` dan `$id` di tiga tempat —
**TypeError setiap kali tombol Sync, Retry, atau Hapus menu ditekan.**

Lolos dari empat alat verifikasi statis karena tak satu pun memeriksa tipe
argumen. Ini kelas kesalahan yang persis disebut STATUS.md: hanya muncul saat
dieksekusi. Diperbaiki, dan pemeriksaannya ditambahkan ke
`audit-sprint-8-7.py` supaya tidak terulang.

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Services/Telegram/TelegramBulkService.php` | Lima aksi massal, semuanya lewat antrean |
| `app/Services/Telegram/TelegramHealthService.php` | Satu definisi "sehat" untuk semua pemanggil |
| `app/Services/Telegram/TelegramAlertService.php` | Peringatan ke log + Telegram, dengan penahan |
| `app/Services/Telegram/TelegramCacheService.php` | Cache `file_id` dan metadata episode |
| `app/Services/Telegram/TelegramRateLimiter.php` | Menahan laju sebelum Telegram menahannya |
| `app/Observers/EpisodeVideoObserver.php` | Auto sync + pembuangan cache |
| `app/Console/Commands/TelegramAutomation.php` | `telegram:auto retry\|health\|cleanup\|all` |
| `app/Jobs/VerifyTelegramFileId.php` | Memastikan `file_id` masih berlaku |
| `app/Http/Controllers/Admin/TelegramLogController.php` | Pembaca log Telegram |
| `resources/views/web/pages/admin/telegram-log.blade.php` | Halaman log |
| `tools/audit-sprint-8-7.py` | 132 pemeriksaan |

## Berkas yang disunting

| Berkas | Perubahan |
|---|---|
| `app/Http/Controllers/Admin/TelegramSyncController.php` | Search, filter, sort, bulk, health, statistik |
| `resources/views/web/pages/admin/telegram-sync.blade.php` | Kartu status, toolbar, bar aksi massal |
| `app/Services/Telegram/TelegramClient.php` | Pembatas laju sebelum tiap permintaan |
| `app/Services/Telegram/TelegramDeliveryService.php` | `file_id` lewat cache |
| `app/Services/Telegram/TelegramVideoSyncService.php` | Peringatan saat gagal |
| `app/Jobs/SyncEpisodeVideoToTelegram.php` | Peringatan saat job gagal |
| `app/Providers/AppServiceProvider.php` | Pendaftaran observer |
| `routes/console.php` | Tiga jadwal |
| `routes/web.php`, layout admin | 2 route + 1 menu |
| `config/telegram.php`, `.env.example` | 11 kunci baru |
| `app/Http/Controllers/Admin/TelegramMenuController.php` | Perbaikan ActivityLogger |

---

## Keputusan desain

### Observer, bukan panggilan di dalam service upload

Auto sync harus terjadi setiap kali baris `episode_videos` dibuat. Masalahnya,
ada **tiga jalur** yang membuatnya: unggahan satuan (7.5), antrean (7.7), dan
Batch Upload (7.9).

Menaruh "antrekan sinkronisasi" di salah satunya berarti dua jalur lain
diam-diam tidak melakukannya — dan tidak ada yang akan menyadarinya sampai ada
video yang tidak pernah tersinkron tanpa sebab yang terlihat.

Observer menangkap ketiganya sekaligus, dan tidak mengubah satu baris pun di
modul-modul itu. Ada pemeriksaan otomatis yang memastikan
`EpisodeVideoService` dan `UploadQueueService` tetap bersih dari
`SyncEpisodeVideoToTelegram`.

### Aksi satuan dan aksi massal memakai jalur yang sama

Ini yang paling mudah salah di sprint yang menambahkan bulk action di atas
aksi satuan yang sudah ada: menulis ulang aturannya, lalu keduanya berbeda
pendapat tentang berkas yang sama.

Karena itu `TelegramSyncController::retry()` **memanggil**
`TelegramBulkService::retry([$id])` — satu id, jalur yang sama persis. Batas
`sync.max_retry` hanya ada di `TelegramBulkService`, dan ada pemeriksaan
otomatis yang melarang string `max_retry` muncul di controller.

Aksi massal juga memakai `TelegramVideoSyncService::blocker()`, alasan
penolakan yang sama dengan tombol satuan.

### Bulk Cancel tidak menghentikan yang sedang berjalan

Hanya baris berstatus Menunggu yang dibatalkan. Memutus pengiriman berkas
separuh jalan meninggalkan berkas rusak di Telegram yang **tidak bisa
dibedakan dari yang utuh** — tidak ada penanda apa pun di sisi Telegram yang
mengatakan sebuah unggahan terpotong.

Baris yang benar-benar tersangkut dilepaskan `telegram:auto cleanup`, yang
menunggu batas waktu wajar (`TELEGRAM_STUCK_MINUTES`) lebih dulu.

### Bulk Verify memakai `getFile`, bukan mengirim ulang

`getFile` menanyakan metadata tanpa mengirim apa pun ke siapa pun. Murah, dan
tidak mengganggu pengguna mana pun. Mengirim video ke chat penyimpanan untuk
mengujinya akan menambah satu salinan setiap kali diperiksa.

Yang penting: **kegagalan jaringan tidak dianggap `file_id` rusak.** Menandai
FAILED karena gangguan sesaat akan membuat seluruh katalog tampak rusak setiap
kali koneksi VPS terganggu. Hanya penolakan tegas dari Telegram yang
mengubah status — dan sesudahnya videonya disinkronkan ulang **dari storage
provider**, tanpa ada yang perlu mengunggah apa pun dari komputer.

### Peringatan ditahan, tetapi log tidak

Bot yang sedang bermasalah menghasilkan ratusan kegagalan yang sama dalam
hitungan menit. Mengirim semuanya berarti yang membacanya akan mematikan
pemberitahuannya — persis kebalikan dari yang dimaksud.

Satu jenis peringatan dikirim sekali per `TELEGRAM_ALERT_THROTTLE` menit.
Penahannya `Cache::add()`, satu operasi yang memeriksa **dan** menandai tanpa
celah di antaranya, dan berlaku lintas worker.

Log tetap ditulis semuanya. Yang dibatasi hanya ketukan di bahu operator,
bukan jejak yang bisa ditelusuri.

Kegagalan mengirim peringatan ditelan dengan sengaja: kalau Telegram sedang
tumbang, peringatan "Telegram tumbang" jelas juga tidak akan sampai, dan
melempar exception dari sana hanya menambah satu kegagalan baru di atas
kegagalan yang sudah ada.

### Pembatas laju tidak menggantikan penanganan 429

`TelegramRateLimiter` mengambil kuota sebelum tiap permintaan. Ini
**bukan** jaminan: cache tanpa operasi atomik lintas proses bisa menghitung
kurang saat dua worker menambah bersamaan.

Yang memberi jaminan tetap penanganan 429 di `TelegramClient`, yang tidak
disentuh. Pembatas ini mengurangi seberapa sering itu terpakai — pada
broadcast ribuan penerima, separuh permintaan yang ditolak dulu sebelum
diterima adalah waktu yang terbuang percuma.

Ia juga **tidak pernah menggagalkan permintaan**. Menunggu melewati
`TELEGRAM_RATE_MAX_WAIT_MS` berarti menyerah menunggu dan tetap mengirim,
membiarkan Telegram yang memutuskan. Permintaan yang ditolak masih lebih baik
daripada permintaan yang tidak pernah dikirim karena pembatas kita sendiri.

Cache yang rusak juga diperlakukan sebagai "ada kuota", dengan alasan yang
sama.

### Cache dibuang eksplisit, bukan menunggu kedaluwarsa

TTL-nya satu jam, tetapi yang menjaga kebenarannya adalah `forget()` di
`EpisodeVideoObserver`. Menggantungkan diri pada TTL berarti ada rentang
sampai satu jam ketika bot mengirim `file_id` lama untuk video yang baru
diganti — dan gejalanya "**video salah**", bukan "video gagal", yang jauh
lebih sulit dikenali.

Cache dibuang pada setiap `saved`, bukan hanya saat `file_id` berubah.
Membuang terlalu sering berbiaya satu query; membuang terlalu jarang berarti
bot mengirim video yang salah.

Nilai kosong ikut disimpan. Tanpa itu, episode yang memang belum tersinkron
akan menembus cache pada setiap permintaan — dan justru episode itulah yang
paling sering diminta, karena tautannya tersebar sebelum videonya siap.

### Satu definisi "sehat"

`TelegramHealthService` dipakai tiga pemanggil: halaman admin, perintah
`telegram:auto health` yang dijalankan scheduler, dan pemeriksaan tersangkut.
Menulis pemeriksaannya di masing-masing berarti tiga definisi yang bisa
berbeda — dan panel akan mengatakan baik-baik saja sementara scheduler
mengirim peringatan.

Seluruh method di sana tidak pernah melempar. Ini alat pemeriksa; memeriksa
keadaan yang rusak tidak boleh ikut rusak.

### Log viewer membaca berkas dari ujung, bukan tabel

Menyimpan riwayat panggilan ke tabel berarti setiap pengiriman menambah satu
baris database, dan tabel itu tumbuh secepat pemakaian bot tanpa ada yang
memangkasnya. Log Laravel sudah berputar sendiri lewat channel `daily`.

Berkas log bisa puluhan megabyte, jadi dibaca mundur dari 2 MB terakhir —
bukan dimuat seluruhnya lalu diambil ekornya. Baris pertama yang terbaca
kemungkinan terpotong di tengah; ia dibuang karena tidak cocok dengan pola
tanggalnya.

### Kolom urut memakai daftar tertutup

Nama kolom yang datang dari query string dan langsung masuk ke `orderBy`
adalah jalan untuk membocorkan struktur tabel lewat pesan galat SQL. Yang
diterima hanya lima nama yang dipetakan ke kolom sungguhan; sisanya jatuh ke
`id`.

### Form massal berada di luar tabel

Teknik yang sama dengan editor prioritas 7.2D dan menu Telegram 8.1: form
massal ditutup **setelah** tabel, kotak centangnya dihubungkan lewat atribut
`form`. Form yang melingkupi tabel akan bersarang dengan form tombol per
baris, dan parser HTML membuang yang bersarang — bug yang masih tercatat di
STATUS.md untuk modul CRUD lain.

Lima tombol aksi memakai `name="aksi"` dengan nilai berbeda pada form yang
sama, sehingga hanya perlu satu route. Ada pemeriksaan otomatis yang
membandingkan posisi `</table>` dengan posisi `id="bulk-form"`.

### Scheduler wajib `withoutOverlapping()`

Tanpa itu, jalan yang lambat bertumpuk dengan jalan berikutnya, dan dua proses
yang sama-sama mengantrekan ulang video yang gagal akan mengirim berkas yang
sama dua kali.

`runInBackground()` supaya satu perintah lambat tidak menunda perintah lain di
menit yang sama.

### Auto retry berhenti di `max_retry`, bukan mengulang selamanya

Kegagalan permanen — berkas melewati batas 50 MB Bot API, chat penyimpanan
salah — akan gagal dengan cara yang sama berapa kali pun dicoba. Mengulanginya
tiap 15 menit hanya memenuhi log sampai kegagalan yang benar-benar baru tidak
terlihat lagi.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    73 blade, 0 bermasalah
python tools/check-css-coverage.py        257 kelas, semua punya aturan
python tools/check-php-structure.py       394 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
python tools/audit-sprint-8-1.py          81/81 lolos
python tools/audit-sprint-8-2.py          125/125 lolos
python tools/audit-sprint-8-7.py          132/132 lolos
```

### Satu GAGAL palsu dari skrip audit saya sendiri

Sesuai kebiasaan proyek ini, dilaporkan apa adanya.

`audit-sprint-8-7.py` versi pertama melaporkan "pencarian menembus relasi
drama dan episode" GAGAL. Kodenya benar — yang salah pemeriksaannya: ia
mencari string `whereHas` persis, sedangkan kodenya memakai `orWhereHas`,
dengan **W besar**. Pencocokan huruf demi huruf pada nama method yang punya
varian berawalan `or` adalah kekeliruan yang akan berulang; diperbaiki dengan
pencocokan yang mengabaikan besar-kecil huruf.

Ini kegagalan palsu ketujuh yang tercatat di proyek ini, dan yang pertama
bukan disebabkan komentar atau string.

### Tiga GAGAL di `audit-sprint-8-2.py`, dan itu bukan regresi

Selama pengerjaan, audit 8.2 melaporkan tiga GAGAL:

- `pengiriman ke pengguna memakai file_id`
- `pengiriman memeriksa status sinkron dulu`
- `batas percobaan ulang ditegakkan`

Ketiganya **asersi yang kedaluwarsa**, bukan perilaku yang rusak. `file_id`
sekarang dibaca lewat `TelegramCacheService` dan pemeriksaan `isSynced` pindah
ke sana; batas percobaan ulang pindah ke `TelegramBulkService` justru supaya
tidak ada dua salinan.

Asersinya diperbarui untuk memeriksa **invariannya**, bukan lokasi kodenya —
itu perbedaan yang menentukan, dan pemeriksaan yang menguji lokasi akan selalu
menghalangi refactor yang benar.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
scheduler yang benar-benar berjalan, tidak ada video yang dikirim.

---

## Belum dikerjakan (sengaja)

- **Notifikasi in-app untuk admin.** Peringatan pergi ke log dan ke Telegram.
  Menambahkan baris ke tabel `notifications` berarti membangun halaman yang
  membacanya, dan halaman itu bukan bagian sprint ini.
- **Auto sync menyala secara bawaan.** Tetap mati. Menyalakannya berarti
  setiap unggahan langsung memakan kuota Telegram sebelum ada yang memutuskan
  berkas itu memang akan disajikan lewat bot.
- **Pembatas laju per-chat.** Telegram juga membatasi ~1 pesan/detik per chat,
  terpisah dari batas global. Yang ada sekarang hanya batas global; batas
  per-chat baru terasa pada broadcast ke satu grup besar, dan itu belum ada.
- **Verifikasi `file_id` terjadwal.** Tombol Bulk Verify ada; menjadwalkannya
  otomatis berarti memanggil `getFile` untuk seluruh katalog secara berkala,
  dan itu keputusan yang tergantung besar katalognya.
- **Membatalkan pekerjaan yang sudah berjalan.** Lihat alasannya di keputusan
  desain Bulk Cancel.
