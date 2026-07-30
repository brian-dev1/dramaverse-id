# Sprint 8.1 — Telegram Core Service

Selesai: 31 Juli 2026

Fondasi Phase 8. Multi Storage (7.1–7.3), Storage Engine (7.4), Upload Service
(7.5–7.6), Queue (7.7), dan Monitoring/File Manager/Batch Upload (7.8–7.9)
**tidak diubah satu baris pun**. Belum ada upload video ke Telegram,
`telegram_file_id`, deep link, episode navigation, membership, continue
watching, favorite, webhook baru, dan queue Telegram.

---

## Catatan pembuka: pembacaan folder di awal sesi kembali basi

Sesi ini dibuka dengan membaca `STATUS.md`, dan yang terbaca adalah versi
**sebelum 7.7** — berhenti di Sprint 7.6, 468 baris. Baseline keempat alat
verifikasi pun dijalankan di atas pohon yang belum lengkap: 344 berkas PHP,
66 blade, 222 kelas CSS. Angka-angka itu persis keadaan 7.6.

Yang sebenarnya ada di disk: `STATUS.md` 604 baris sampai Sprint 7.9,
`SPRINT-7-7-SELESAI.md` dan `SPRINT-7-8-7-9-SELESAI.md` keduanya ada, 366
berkas PHP, 70 blade, 241 kelas CSS, dan alat verifikasi kelima
(`tools/audit-sprint-7-8.py`) yang tidak muncul sama sekali di pembacaan awal.

Ini **persis kejadian yang sudah dicatat di STATUS.md poin 4** dari sesi 7.8.
Peringatannya sudah ada dan tetap terjadi lagi. Yang menyelamatkan kali ini:
angka hasil alat verifikasi tiba-tiba melompat (344 → 366 berkas) padahal saya
hanya menambah lima berkas bersih, dan selisih itu yang membuat saya memeriksa
ulang, bukan kewaspadaan di awal.

Akibat nyata: **audit awal saya berdiri di atas pohon yang tidak lengkap.**
Seluruh audit diulang di atas pohon penuh sebelum satu pun keputusan sprint ini
dikunci, dan `tools/audit-sprint-7-8.py` (143 pemeriksaan) dijalankan terhadap
hasil akhir — lolos semua. Tidak ada berkas 7.7–7.9 yang tertimpa: satu-satunya
berkas milik sprint lain yang saya sunting adalah yang memang wajib disentuh,
dan semuanya berkas Telegram.

Usulan supaya tidak terulang ketiga kalinya ada di bagian terakhir dokumen ini.

---

## Cara memakainya dari kode

```php
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;

public function __construct(
    protected TelegramServiceInterface $telegram
) {}

// Pesan biasa
$this->telegram->sendMessage($chatId, '<b>Halo</b>');

// Dengan tombol
$this->telegram->sendMessage($chatId, $teks, [
    'reply_markup' => HomeKeyboard::make(),
]);

// Gambar: URL, file_id, atau berkas di disk
$this->telegram->sendPhoto($chatId, 'https://.../poster.jpg', $caption);
$this->telegram->sendPhoto($chatId, new SplFileInfo($path), $caption);

// Sunting dan hapus
$this->telegram->editMessage($chatId, $messageId, $teksBaru);
$this->telegram->deleteMessage($chatId, $messageId);

// Tombol inline: hentikan animasi tunggu SEGERA
$this->telegram->answerCallbackQuery($callbackId);

// Berkas dan identitas bot
$file = $this->telegram->getFile($fileId);
$bot  = $this->telegram->getMe();

// Method Bot API yang belum punya pembungkus
$this->telegram->query('getWebhookInfo');
$this->telegram->call('setMyCommands', ['commands' => $daftar]);

// Untuk jalur yang ditunggu orang
$this->telegram->withTimeout(6)->withRetries(1)->getMe();
```

Kegagalan **dilempar**, tidak dikembalikan:

```php
try {
    $this->telegram->sendMessage($chatId, $teks);
} catch (TelegramException $e) {
    if ($e->isBlockedByUser())  { /* berhenti kirim ke chat ini */ }
    if ($e->isChatNotFound())   { /* pengguna belum tekan Start */ }
    if ($e->isRateLimited())    { /* $e->retryAfter detik */ }
    if ($e->isTokenProblem())   { /* urusan operator */ }
    $e->hint();       // saran langkah berikutnya, atau null
    $e->logContext(); // konteks siap-log, tanpa token
}
```

---

## Berkas yang dibuat

| Berkas | Isi |
|---|---|
| `app/Services/Telegram/Contracts/TelegramClientInterface.php` | Kontrak lapisan angkut |
| `app/Services/Telegram/Contracts/TelegramServiceInterface.php` | Kontrak Core Service, 9 method dasar + 4 serba guna |
| `app/Services/Telegram/TelegramClient.php` | HTTP, timeout, retry, multipart, redaksi token, log |
| `app/Services/Telegram/TelegramService.php` | Pembungkus Bot API. Nol HTTP |
| `app/Services/Telegram/TelegramResponse.php` | Jawaban yang sudah pasti berhasil |
| `app/Services/Telegram/Exceptions/TelegramException.php` | Satu jenis kegagalan, dengan pertanyaan siap pakai |
| `app/Console/Commands/TelegramTest.php` | `php artisan telegram:test` |
| `tools/audit-sprint-8-1.py` | 81 pemeriksaan |

## Berkas yang disunting

| Berkas | Perubahan |
|---|---|
| `config/telegram.php` | Ditulis ulang: api_url, timeout, retry, logging, parse_mode, batas unggah |
| `config/services.php` | Blok `telegram` tandingan dibuang |
| `.env.example` | 13 variabel baru, masing-masing dengan keterangan |
| `app/Providers/AppServiceProvider.php` | Dua binding singleton baru |
| `app/Repositories/Contracts/TelegramRepositoryInterface.php` | Dari pengirim HTTP jadi akses data |
| `app/Repositories/TelegramRepository.php` | Idem |
| `app/Services/BroadcastService.php` | Menyusun pesan episode + `sendEpisode()` |
| `app/Jobs/SendTelegramBroadcast.php` | Pakai kontrak, kenali galat lewat exception |
| `app/Jobs/BroadcastEpisodeTelegramJob.php` | Pakai `BroadcastService` |
| `app/Http/Controllers/Admin/TelegramController.php` | Nol `Http::`, segmen pindah ke repository |
| `app/Http/Controllers/TelegramWebhookController.php` | Menahan `TelegramException` |
| `app/Telegram/Handlers/*.php` (12 berkas) | Menerima kontrak, bukan kelas konkret |

## Berkas yang dihapus

- `app/Services/TelegramService.php`
- `app/Telegram/Services/TelegramService.php`

---

## Keadaan sebelum sprint ini: tiga jalur ke Telegram

Ini temuan audit yang menentukan seluruh bentuk sprint ini, jadi ditulis lebih
dulu sebelum keputusan-keputusannya.

| Jalur | Berkas | Sumber token | Timeout | Retry | Penanganan galat |
|---|---|---|---|---|---|
| 1 | `App\Telegram\Services\TelegramService` | `telegram.bot_token` | bawaan Guzzle | tidak ada | tidak ada |
| 2 | `App\Repositories\TelegramRepository` | **`services.telegram.bot_token`** | bawaan Guzzle | tidak ada | tidak ada |
| 3 | `Admin\TelegramController::call()` | `telegram.bot_token` | 6 detik | tidak ada | `catch (\Throwable)` lalu null |

Ketiganya merakit URL `https://api.telegram.org/bot<token>/<method>` sendiri.
Ketiganya harus disunting setiap kali ada satu hal yang berubah.

Yang paling berbahaya baris kedua: `config/services.php` dan
`config/telegram.php` sama-sama punya kunci `bot_token`, keduanya membaca env
yang sama, jadi selamanya terlihat benar. Sampai ada yang memindahkan sumber
token — misalnya menaruhnya di database supaya bisa diganti dari panel — dan
mengubahnya di satu berkas saja. Sesudah itu sebagian pengiriman memakai token
kosong, dan yang gagal cuma sebagian.

Sprint ini menyisakan **satu** jalur: `TelegramClient`. Diperiksa otomatis.

---

## Keputusan desain

### Client dan Service dipisah, bukan satu kelas

`TelegramClient` tahu HTTP tapi tidak tahu apa itu `sendMessage`.
`TelegramService` tahu setiap method Bot API tapi tidak tahu apa itu HTTP.

Alasannya bukan simetri. Pemisahan ini yang membuat lapisan Telegram bisa diuji
tanpa jaringan sama sekali: ganti `TelegramClientInterface` dengan tiruan, dan
seluruh service beserta semua pemanggilnya bisa dijalankan. Kalau HTTP-nya
menempel di service, satu-satunya cara mengujinya adalah benar-benar memanggil
Telegram — dan di lingkungan asisten itu tidak mungkin dilakukan sama sekali.

Ini bentuk yang sama dengan `StorageManager` dan `StorageEngine` di 7.1–7.4,
dengan alasan yang sama.

### Kegagalan dilempar, bukan dikembalikan sebagai array

Perubahan perilaku terbesar sprint ini, dan disengaja.

Sebelumnya setiap method mengembalikan array mentah dari Telegram. Dari 20
tempat yang memanggilnya, **19 tidak pernah memeriksa `ok`**. Satu-satunya yang
memeriksa adalah `SendTelegramBroadcast`. Artinya: kalau pesan tidak terkirim
— token salah, pengguna memblokir bot, jaringan VPS putus — tidak ada satu
baris pun di mana pun yang mencatat bahwa itu terjadi. Gejalanya "kadang bot
tidak membalas", tanpa jejak untuk ditelusuri.

Sekarang setiap method mengembalikan `TelegramResponse` yang sudah pasti
berhasil, atau melempar `TelegramException`. Tidak ada nilai balik yang boleh
diabaikan, karena tidak ada nilai balik yang perlu diperiksa.

Konsekuensi yang harus ditangani, dan ditangani: lihat dua bagian berikutnya.

### Webhook menahan exception, kalau tidak Telegram mengirim ulang selamanya

Begitu lapisan Telegram melempar, `TelegramWebhookController` tiba-tiba bisa
menjawab 500 — dan Telegram membaca jawaban selain 2xx sebagai "update belum
diproses", lalu mengirimkan update yang sama lagi. Berulang-ulang, dengan
sebab yang tidak akan pernah berubah karena pengguna memang memblokir bot.

Jadi webhook menangkap `TelegramException` dan tetap menjawab `ok: true`.
Update-nya memang sudah diproses; yang gagal hanya balasannya, dan sebabnya
sudah dicatat lengkap oleh client.

Ini satu-satunya sentuhan ke webhook di sprint ini. Logika webhook-nya sendiri
— verifikasi rahasia, routing update — tidak diubah, sesuai batas scope.

### Halaman admin mematikan pengulangan

`Admin\TelegramController` dulu memakai `Http::timeout(6)` tanpa retry.
Memindahkannya ke service tanpa berpikir akan membuat halaman itu menunggu
sampai 3 × 15 detik ditambah backoff — sekitar 50 detik untuk memuat satu
halaman, saat Telegram sedang bermasalah.

Karena itu ada `withRetries(1)`. Untuk pekerjaan di antrean, mengulang itu
benar. Untuk halaman yang sedang ditunggu orang, mengulang hanya
melipatgandakan waktu tunggu sebelum kegagalan yang sama tetap muncul.

Batas waktu 6 detik dipertahankan apa adanya dari kode lama.

### Token bot tidak boleh masuk log, dan itu perlu dua langkah

Token ada di dalam URL setiap permintaan. Pesan galat Guzzle memuat URL itu apa
adanya. Tanpa penjagaan, **satu gangguan jaringan sudah cukup** untuk menulis
token bot ke `laravel.log` — berkas yang justru dibaca, disalin, dan
dikirimkan ke orang lain saat sedang menelusuri masalah.

Dua langkah:

1. `TelegramClient::redact()` mengganti token dengan `<token>`, ditambah
   jaring pengaman berupa pola `\d{6,12}:[A-Za-z0-9_-]{30,}` untuk token lama
   yang mungkin tertinggal di pesan yang di-cache.
2. **Exception Guzzle tidak dipasang sebagai `previous`.** Ini pengorbanan
   sadar: `previous` memberi jejak tumpukan yang lebih kaya, tetapi Laravel
   menuliskan seluruh rantai exception ke log saat ada yang tidak tertangkap,
   dan pada saat itu redaksi di langkah pertama tidak berlaku lagi. Sebagai
   gantinya, kelas exception asal dan pesannya yang sudah diredaksi disimpan
   sebagai teks.

### Konteks log dibangun dari daftar tertutup, bukan dari seluruh payload

Yang selalu ikut ke log: `chat_id`, `message_id`, `callback_query_id`,
`file_id`, panjang teks, durasi, jumlah percobaan, kode galat.

Yang **tidak** ikut kecuali `TELEGRAM_LOG_PAYLOAD=true`: isi pesan. Itu tulisan
pengguna, dan menaruhnya di log berarti menyimpannya di tempat yang aturan
penyimpanan dan aksesnya berbeda dari database. Meski dinyalakan, teksnya
dipotong pada `TELEGRAM_LOG_TEXT_LIMIT` dan tetap diredaksi.

Pendekatan daftar tertutup dipilih daripada daftar larangan karena daftar
larangan selalu ketinggalan: field baru dari Bot API otomatis ikut tercatat,
dan yang sensitif baru ketahuan setelah tercetak.

### Pengulangan: hanya yang pantas diulang

| Keadaan | Diulang? | Jedanya |
|---|---|---|
| Koneksi gagal / habis waktu | ya | backoff berganda |
| HTTP 5xx | ya | backoff berganda |
| HTTP 429 batas laju | ya | `retry_after` dari Telegram |
| 400 Bad Request | tidak | — |
| 401 Unauthorized | tidak | — |
| 403 diblokir pengguna | tidak | — |

400/401/403 adalah keputusan tetap. Mengulangnya hanya memperlambat kegagalan
yang sudah pasti — dan pada broadcast ribuan penerima, perlambatan itu berlipat
jadi berjam-jam antrean yang percuma.

Untuk 429, jedanya diambil dari `parameters.retry_after` milik Telegram, bukan
tebakan kita. Tapi ada batasnya: `TELEGRAM_RETRY_MAX_WAIT` (bawaan 30 detik).
Kalau Telegram minta menunggu lebih lama, kita menyerah dan melaporkannya —
proses PHP tidak boleh tidur semenit di dalam sebuah request.

**Peringatan jujur soal idempotensi.** Bot API tidak punya kunci idempoten.
Kalau pesan benar-benar sampai lalu koneksinya putus sebelum jawabannya
kembali, percobaan ulang mengirim pesan yang sama dua kali. Itu harga yang
dipilih di sini: pesan ganda lebih ringan daripada pesan yang hilang diam-diam.
`TELEGRAM_RETRY_TIMES=1` mematikannya.

### `TelegramRepository` diubah perannya, bukan dihapus

Repository yang membuka koneksi HTTP adalah repository yang salah tempat. Tapi
menghapusnya begitu saja membuang kontrak yang sudah ter-bind dan sudah masuk
pemeriksaan `verify-consistency.py`.

Yang dilakukan: perannya diganti jadi pekerjaan yang memang milik repository —
pertanyaan ke database. Isinya sekarang segmen penerima broadcast, jumlah per
segmen, angka ringkas halaman admin, pencarian pengguna berdasarkan
`telegram_id`, dan penonaktifan pengguna yang memblokir bot.

Semua itu sebelumnya tersebar: definisi segmen ditulis di dalam
`Admin\TelegramController`, penonaktifan ditulis di dalam
`SendTelegramBroadcast`. Dua tempat menyentuh tabel yang sama dengan aturan
yang sama, tanpa saling tahu. Ambang "tidak aktif" 30 hari kini satu konstanta,
karena segmen `active` dan `inactive` harus selalu jadi pasangan yang saling
melengkapi — kalau angkanya ditulis terpisah, mengubah salah satunya membuat
sebagian pengguna tidak masuk segmen mana pun, dan jumlahnya cuma berkurang
sedikit sehingga tidak ada yang menyadarinya.

### Nilai kosong di `.env` tidak boleh jatuh ke nol

`TELEGRAM_TIMEOUT=` (baris ada, nilainya kosong) menghasilkan string kosong,
bukan nilai yang belum diset — sehingga argumen default `env()` tidak berlaku
dan `(int) ''` menjadi 0. Untuk timeout, nol berarti menunggu selamanya.

Penjagaan yang sama sudah dipakai `config/storage.php` pada `allowed_drivers`
sejak 7.1, dengan persoalan yang identik.

### `call()` dan `query()` sebagai jalan keluar

Bot API punya lebih dari seratus method. Menulis pembungkus untuk semuanya
berarti merawat kode yang tidak pernah dipakai; tidak menulis apa-apa berarti
pemanggil kembali menyentuh HTTP begitu butuh method di luar sembilan yang ada.

`call()` (POST) dan `query()` (GET) menutup celah itu. `getWebhookInfo` di
halaman admin dan di `telegram:test` sudah memakainya, dan keduanya tetap lewat
client yang sama — dengan timeout, retry, redaksi, dan log yang sama.

### Berkas ditolak sebelum dikirim, bukan sesudah

Telegram menolak berkas di atas 50 MB. Pemeriksaannya dilakukan di sisi kita:
mengirim berkas 300 MB lewat jaringan hanya untuk ditolak di akhir membuang
waktu dan kuota VPS, dan pada koneksi lambat kegagalannya muncul sebagai
timeout — gejala yang menunjuk ke arah yang salah.

Batasnya dari config, supaya bisa dinaikkan bersama `TELEGRAM_API_URL` yang
menunjuk Local Bot API Server sendiri (batasnya 2000 MB). Itu jalur yang akan
dipakai sprint upload video ke Telegram, tapi belum disentuh sekarang.

### HTML, bukan MarkdownV2

MarkdownV2 mewajibkan pelolosan pada belasan karakter biasa: titik, tanda
hubung, tanda seru, tanda kurung. Judul drama memuat karakter-karakter itu, dan
satu yang terlewat membuat Telegram menolak **seluruh pesan** dengan galat
parsing — bukan menampilkannya apa adanya.

---

## Bug yang ditemukan

### 1. `SearchHandler` memanggil method yang tidak ada — fatal error

`SearchHandler` menyuntik `App\Services\TelegramService` (yang hanya punya
`sendText()` dan `broadcastEpisode()`) lalu memanggil `sendMessage()` sebanyak
tiga kali.

Kelas itu tidak punya `sendMessage()`. Setiap pencarian drama lewat bot berakhir
dengan `Error: Call to undefined method`. Sebelas handler lain menyuntik
`App\Telegram\Services\TelegramService` yang memang punya method itu — nama
kelasnya sama, namespace-nya berbeda, dan itulah yang membuat kekeliruan ini
tidak terlihat saat dibaca sekilas.

Hilang sendiri setelah konsolidasi: sekarang semua handler menerima kontrak
yang sama, dan pemeriksaan otomatis memastikan tidak ada pemanggil yang memakai
method di luar kontrak.

### 2. Pengumuman episode mengirim path, bukan URL — selalu ditolak

`broadcastEpisode()` mengirim `$episode->thumbnail` apa adanya sebagai parameter
`photo`. Kolom itu berisi path relatif di disk `public` (`episode/thumbnail/
<uuid>.jpg`), bukan URL. Telegram membacanya sebagai `file_id` yang tidak
dikenal dan menolak seluruh pengiriman.

Karena `BroadcastEpisodeTelegramJob` tidak pernah memeriksa nilai baliknya,
kegagalan ini tidak pernah muncul di mana pun — bukan di log, bukan di
`failed_jobs`. Job-nya selesai dengan status sukses setiap kali.

Diperbaiki di `BroadcastService::thumbnailUrl()`. Tanpa thumbnail, pengumuman
dikirim sebagai teks saja — isi yang penting ada di teksnya, dan memaksa
`sendPhoto` dengan gambar kosong hanya membuat pengumumannya tidak sampai sama
sekali.

### 3. Pengenalan "pengguna memblokir bot" lewat pencocokan kalimat

`SendTelegramBroadcast` memakai `str_contains($description, 'blocked')`.
Kalimat galat Telegram bukan kontrak — kalau kalimatnya berubah, pengecekan itu
gagal diam-diam dan pengguna yang sudah pergi terus dikirimi selamanya, tiga
kali percobaan per broadcast.

Sekarang lewat `TelegramException::isBlockedByUser()`, yang mencocokkan
`error_code` **dan** kata kunci sekaligus: kode saja tidak cukup karena
Telegram memakai 403 untuk beberapa keadaan berbeda, dan kata kunci saja tidak
cukup karena kalimatnya bisa berubah. Ada pemeriksaan otomatis yang melarang
pemanggil kembali menebak sebab galat dari potongan kalimat.

### 4. Alur pencarian tidak bisa dijangkau pengguna sama sekali

`SearchHandler` ada, `TelegramRouter` sudah menangani state `SEARCH`, dan
`UserSessionService` sudah menyimpan statenya — tetapi **tidak ada satu pun
tombol atau perintah yang memulainya**. Menu `/start` hanya punya Continue,
Favorit, Riwayat, Website, Premium, Profil, dan Bantuan.

`CallbackHandler` juga tidak punya cabang `search`, jadi seandainya tombolnya
ada pun, ia akan jatuh ke `default` dan hanya menampilkan ulang menu.

Diperbaiki: tombol "Cari Drama" ditaruh paling atas, dan cabang `search`
ditambahkan. `SearchHandler::start()` menerima chat dan user terpisah karena
state percakapan disimpan per pengguna (`from.id`) sedangkan balasannya dikirim
ke chat (`chat.id`) — sama di chat pribadi, berbeda di grup.

Ini melengkapi bug nomor 1: alurnya fatal error **dan** tidak ada jalan untuk
mencapainya.

### 5. Dua sumber konfigurasi token yang berdampingan

Sudah dijelaskan di bagian "tiga jalur". `config/services.php` sekarang berisi
komentar yang menjelaskan kenapa blok itu sengaja kosong, bukan dihapus tanpa
jejak — supaya tidak ada yang menambahkannya kembali karena mengira terlupa.

### 6. `QueueService::telegram()` mengantrekan job yang tidak melakukan apa pun

`SendTelegramNotificationJob::handle()` isinya hanya komentar `TODO`.
`QueueService::telegram($message)` mengantrekannya. Kalau ada modul yang
memakainya, notifikasinya hilang tanpa jejak dan antreannya melaporkan sukses.

**Sengaja tidak diperbaiki di sprint ini** — alasannya di bagian berikutnya.
`QueueService` sendiri saat ini tidak dipanggil dari mana pun; tiga job lain
yang didaftarkannya (`BroadcastEpisodeJob`, `SendPremiumReminderJob`,
`GenerateVideoThumbnailJob`) juga masih kosong.

---

## Hasil verifikasi

```
python tools/verify-consistency.py        18/18 pemeriksaan lolos
python tools/check-blade-directives.py    70 blade, 0 bermasalah
python tools/check-css-coverage.py        241 kelas, semua punya aturan
python tools/check-php-structure.py       366 berkas, 0 bermasalah
python tools/audit-sprint-7-8.py          143/143 lolos
python tools/audit-sprint-8-1.py          81/81 lolos
node --check resources/js/admin.js        valid
```

`audit-sprint-8-1.py` memeriksa antara lain:

- nol `Http::` dan nol `api.telegram.org` di luar `TelegramClient`, diperiksa
  setelah komentar dan isi string dibuang
- nol controller yang memanggil Telegram API langsung
- **setiap kunci `config/telegram.php` benar-benar dibaca kode** — 19 kunci,
  semuanya. Pemeriksaan ini ada khusus karena `STORAGE_TIMEOUT` membuktikan
  kunci config bisa hidup berbulan-bulan tanpa ada yang membacanya
- setiap variabel `.env.example` baru benar-benar ada
- paritas ketiga kontrak dengan implementasinya
- tidak ada pemanggil yang memakai method di luar kontrak
- `redact()` ada dan dipakai di ketiga jalur galat
- exception Guzzle tidak dilampirkan sebagai `previous`
- konteks log dibangun dari daftar field tertutup
- tidak ada pemanggil yang menebak sebab galat dari potongan kalimat
- webhook menahan exception, halaman admin mematikan pengulangan

### Dua GAGAL palsu dari skrip audit saya sendiri

Sesuai kebiasaan proyek ini, dilaporkan apa adanya.

Versi pertama skrip audit ini menghasilkan dua GAGAL, keduanya salah:

1. **"URL Telegram ditulis di luar config"** — yang tertangkap adalah kalimat
   `Periksa koneksi keluar server ke api.telegram.org` di dalam pesan galat, dan
   docblock yang berbunyi `Tidak ada satu pun Http:: ke api.telegram.org di luar
   TelegramClient`. Prosa yang menjelaskan aturan justru dituduh melanggarnya.
2. **"TelegramException melampirkan previous"** — yang tertangkap adalah
   docblock yang berbunyi `exception Guzzle tidak dipasang sebagai previous`,
   ditambah nama parameter `$previousClass`.

Keduanya sebab yang sama, dan **sebab yang sama sudah tercatat empat kali** di
STATUS.md: `routeparse.py` (7.6) menghitung kurung di dalam string, audit 7.7
menghitung `<form` di dalam komentar Blade, audit 7.8 memotong blok dengan
jendela karakter tetap.

Diperbaiki dengan `code_only()` yang membuang komentar dan isi string literal
sebelum apa pun dicocokkan, dipakai di **setiap** pemeriksaan yang mencari token
kode. Sesudah itu 81/81 lolos.

**Semua verifikasi ini statis.** Tidak ada PHP yang dijalankan, tidak ada
permintaan HTTP yang dikirim, tidak ada halaman yang dirender. Yang hanya bisa
dibuktikan di server ada di bagian pengujian.

---

## Tambahan setelah pengujian di server

Tiga hal muncul saat Anda mencobanya di bot sungguhan. Dua bug, satu
permintaan fitur.

### Bug 7: tombol Cari ditekan, tidak terjadi apa-apa — foreign key

`user_sessions.user_id` adalah **foreign key ke `users.id`**. Yang dikirim ke
sana adalah `$callback['from']['id']` — **telegram_id** (11 digit), bukan id
pengguna di basis data kita.

`UserSession::updateOrCreate(['user_id' => 8947692769])` melanggar constraint,
melempar `QueryException`, dan webhook menjawab 500. Yang dilihat pengguna:
tombol ditekan, tidak ada apa pun yang terjadi.

Bug yang sama, versi diam, ada di `TelegramRouter`: `sessions->current()` juga
dipanggil dengan telegram_id. Itu tidak melempar apa-apa — mencari baris yang
tidak ada memang bukan kesalahan — tetapi artinya state `SEARCH` tidak pernah
ditemukan, jadi teks yang diketik setelahnya tidak pernah diproses. **Dua
lapisan rusak berurutan**: yang pertama membuat state tidak pernah tersimpan,
yang kedua membuat state tidak pernah terbaca.

Diperbaiki di kedua tempat dengan `TelegramRepositoryInterface::findByTelegramId()`
— method yang sudah dibuat di awal sprint ini untuk keperluan lain.

### Bug 8: broadcast tidak sampai — hampir pasti antrean, bukan Telegram

`php artisan telegram:test --chat=` membuktikan pengiriman berfungsi. Broadcast
berbeda: ia hanya **mengantrekan** `SendTelegramBroadcast` ke antrean
`default`, dan sejak 7.7 worker di server disetel mendengarkan `uploads`.
Worker yang tidak mendengarkan `default` membuat setiap broadcast menunggu
selamanya, tanpa satu pun galat di mana pun — gejala yang persis sama dengan
"Telegram menolak".

Tidak bisa saya pastikan dari sini karena konfigurasi supervisor tidak ada di
repo. Yang saya kerjakan: halaman `/admin/telegram` sekarang menampilkan
**koneksi antrean, nama antreannya, jumlah pekerjaan yang menunggu, dan jumlah
yang gagal**, plus perintah yang harus dijalankan bila angkanya tidak turun.
Sebelumnya satu-satunya cara mengetahuinya adalah masuk ke server dan membaca
tabel `jobs` sendiri.

### Menu bot bisa diatur dari panel admin

Halaman baru `/admin/telegram/menu`, izin `telegram.manage` (tidak ada izin
baru, jadi RoleSeeder tidak perlu dijalankan).

Susunan tombol pindah ke tabel `telegram_menus`: label, perbuatan, URL, baris,
posisi, aktif. Satu form untuk seluruh susunan, karena memindahkan satu tombol
hampir selalu berarti menggeser tetangganya — menyimpan satu per satu membuat
keadaan setengah jadi terlihat pengguna bot di antara dua penyimpanan.

**`TelegramMenuAction` jadi satu-satunya daftar** yang menghubungkan tiga hal:
pilihan di panel, `callback_data` yang dikirim Telegram, dan handler yang
menjalankannya. Sebelumnya daftar tombol ada di `HomeKeyboard` dan daftar
handler ada di `CallbackHandler`, ditulis terpisah — dan keduanya memang sempat
tidak sinkron. Itulah sebabnya tombol Cari tidak ada di menu **dan** cabang
`search` tidak ada di router: dua daftar, tidak ada yang mencocokkan.

Keputusan yang perlu disebut:

- **Bawaan tetap dipatok di kode.** Menu adalah satu-satunya cara memakai bot
  ini. Tabel kosong, seeder belum jalan, atau semua baris dinonaktifkan akan
  membuat pengguna menerima sambutan tanpa satu tombol pun. `TelegramMenuService`
  jatuh ke `DEFAULTS` dalam keadaan itu — termasuk bila basis datanya gagal
  dibaca, karena yang memanggilnya adalah bot yang sedang membalas orang.
- **Seeder memakai `firstOrCreate`, bukan `updateOrCreate`.** Label dan posisi
  yang sudah diubah admin tidak dikembalikan ke bawaan. Seeder yang menimpa
  hasil kerja orang adalah seeder yang tidak berani dijalankan lagi.
- **Tombol tautan tanpa URL ditolak di panel.** Telegram menolak **seluruh**
  keyboard bila ada satu tombol `url` beralamat kosong — bukan hanya tombol itu
  yang hilang, tetapi seluruh menunya. Diperiksa dua kali: saat disimpan, dan
  sekali lagi saat keyboard dibangun.
- **Form hapus berada di luar tabel**, dihubungkan dengan atribut `form`.
  Teknik yang sama dengan editor prioritas 7.2D, menghindari bug form bersarang
  yang masih tercatat untuk modul CRUD lain.
- **Callback yang tidak dikenal menampilkan menu utama.** Ini bukan sekadar
  penjagaan: tombol lama tetap menempel di pesan yang sudah terkirim setelah
  menunya diubah dari panel, dan pengguna yang menekannya harus mendapat
  sesuatu.

---

## Belum dikerjakan (sengaja)

- **Upload video ke Telegram, `telegram_file_id`, deep link, episode
  navigation, membership, continue watching, favorite, queue Telegram** — di
  luar scope 8.1, disebut eksplisit di spesifikasi.
- **Webhook** — hanya ditambahi penahan exception. Verifikasi rahasia dan
  routing update tidak disentuh.
- **`SendTelegramNotificationJob` yang kosong.** Job itu menerima `$message`
  saja, tanpa chat id. Mengisinya berarti memutuskan siapa penerimanya — semua
  pengguna? admin saja? satu chat khusus? Itu keputusan modul notifikasi, bukan
  keputusan core service, dan menebaknya sekarang berarti menanam perilaku yang
  tidak diminta siapa pun. Dicatat sebagai bug diketahui.
- **Menyimpan riwayat panggilan Telegram ke database.** Log Laravel sudah
  memuat semuanya. Tabel tersendiri baru berguna kalau ada halaman yang
  membacanya, dan halaman itu belum ada.
- **Rate limiter di sisi kita.** Telegram membatasi sekitar 30 pesan per detik.
  Yang ada sekarang hanya reaksi terhadap 429. Pembatas proaktif tempatnya di
  worker antrean, bersama sprint queue Telegram.
- **Local Bot API Server.** `TELEGRAM_API_URL` sudah bisa diarahkan ke sana dan
  batas unggahnya sudah bisa dinaikkan lewat config, tapi belum pernah diuji.

---

## Usul supaya pembacaan folder basi tidak terulang ketiga kalinya

Peringatan tertulis di STATUS.md sudah ada sejak 7.8, dan tetap terjadi lagi di
sesi ini. Peringatan saja terbukti tidak cukup.

Yang saya usulkan, dan sudah saya kerjakan bagian pertamanya:

1. **Angka pembanding.** `STATUS.md` mencantumkan jumlah berkas PHP, blade, dan
   kelas CSS. Kalau alat verifikasi di awal sesi memberi angka yang lebih kecil
   dari yang tertulis, pohonnya belum lengkap — berhenti dan baca ulang. Inilah
   yang akhirnya menyelamatkan sesi ini, walau secara kebetulan.
2. **`git log --oneline -5` sebagai langkah pertama, sebelum `STATUS.md`.**
   Riwayat commit tidak mungkin basi dengan cara yang sama, dan judul commit
   terakhir langsung menyebutkan sprint terakhir. Di sesi ini, satu perintah itu
   akan langsung menunjukkan `Sprint 7.8-7.9` dan membatalkan seluruh premis
   yang keliru dalam hitungan detik.

Usulan kedua saya tambahkan ke `STATUS.md` sebagai langkah 0.
