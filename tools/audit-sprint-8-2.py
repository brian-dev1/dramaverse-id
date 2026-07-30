#!/usr/bin/env python3
"""
Self-audit Sprint 8.2-8.6 - Telegram Integration.

Memeriksa integrasi antar modul, bukan cuma keberadaan berkas: apakah bot
benar-benar memakai service yang sama dengan website, apakah masih ada
kode yang menyalin aturan bisnis, dan apakah ada jalur yang mati.

Seperti audit 8.1, setiap pemeriksaan yang mencari token kode menjalankan
code_only() lebih dulu. Rekam jejak proyek ini: routeparse.py (7.6), audit
7.7, audit 7.8, dan audit 8.1 semuanya menghasilkan GAGAL palsu karena
menghitung sesuatu di dalam komentar atau string.
"""

import glob
import os
import re
import sys

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

PASSED, FAILED = [], []


def check(cond, label):
    (PASSED if cond else FAILED).append(label)
    if not cond:
        print("  GAGAL ", label)


def read(p):
    with open(p, encoding='utf-8') as fh:
        return fh.read()


def code_only(src):
    s = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    s = re.sub(r'//[^\n]*', '', s)
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", s)
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    return s


def norm(p):
    return p.replace('\\', '/')


APP = sorted(norm(f) for f in glob.glob('app/**/*.php', recursive=True))
HANDLERS = sorted(norm(f) for f in glob.glob('app/Telegram/Handlers/*.php'))

SYNC = 'app/Services/Telegram/TelegramVideoSyncService.php'
DELIVERY = 'app/Services/Telegram/TelegramDeliveryService.php'
DEEPLINK = 'app/Support/TelegramDeepLink.php'
JOB = 'app/Jobs/SyncEpisodeVideoToTelegram.php'
CTRL = 'app/Http/Controllers/Admin/TelegramSyncController.php'
CB = 'app/Telegram/Handlers/CallbackHandler.php'
CLIENT = 'app/Services/Telegram/TelegramClient.php'

# ------------------------------------------------------------- 1. berkas ada
print("\n== BERKAS ==")

for f in [SYNC, DELIVERY, DEEPLINK, JOB, CTRL,
          'app/Enums/TelegramSyncStatus.php',
          'app/Telegram/Keyboards/EpisodeKeyboard.php',
          'app/Telegram/Handlers/WatchHandler.php',
          'app/Telegram/Handlers/EpisodeListHandler.php',
          'resources/views/web/pages/admin/telegram-sync.blade.php']:
    check(os.path.exists(f), f"ada: {f}")

mig = glob.glob('database/migrations/*add_telegram_sync_to_episode_videos*.php')
check(len(mig) == 1, "migration kolom sinkronisasi ada")


# ---------------------------------------------------- 2. kolom yang diminta
print("\n== METADATA ==")

if mig:
    m = read(mig[0])
    for kolom in ['telegram_file_id', 'telegram_unique_file_id', 'sync_status',
                  'synced_at', 'last_error', 'retry_count']:
        check(f"'{kolom}'" in m, f"migration punya kolom {kolom}")

model = read('app/Models/EpisodeVideo.php')
for kolom in ['telegram_file_id', 'telegram_unique_file_id', 'sync_status',
              'synced_at', 'last_error', 'retry_count']:
    check(f"'{kolom}'" in model, f"$fillable memuat {kolom}")

check('TelegramSyncStatus::class' in model, "sync_status di-cast ke enum")

status = read('app/Enums/TelegramSyncStatus.php')
for s in ['PENDING', 'PROCESSING', 'SYNCED', 'FAILED']:
    check(f'case {s}' in status, f"status {s} ada")


# ------------------------------------------- 3. video diambil dari storage
print("\n== SUMBER VIDEO ==")

sync = read(SYNC)
check('StorageEngineInterface' in sync, "sinkronisasi memakai Storage Engine")
check('readStream(' in sync, "berkas dibaca sebagai stream, bukan dimuat penuh ke memori")
check('file_get_contents' not in code_only(sync),
      "tidak memuat seluruh video ke memori")
check('UploadedFile' not in code_only(sync),
      "tidak ada jalur unggah dari komputer di sinkronisasi")
check('finally' in sync, "berkas sementara dibersihkan di blok finally")
check('unlink' in sync, "berkas sementara benar-benar dihapus")

# Tidak boleh ada Storage:: langsung.
bad = [f for f in APP
       if f.startswith('app/Services/Telegram/')
       and re.search(r'\bStorage::', code_only(read(f)))]
check(not bad, "lapisan Telegram tidak menyentuh facade Storage")
for b in bad:
    print("        -", b)


# --------------------------------------- 4. tidak upload ulang bila ada id
print("\n== FILE_ID DIPAKAI ULANG ==")

delivery = read(DELIVERY)
CACHE = 'app/Services/Telegram/TelegramCacheService.php'

# Sejak 8.9 file_id dibaca lewat TelegramCacheService, bukan langsung dari
# relasi. Yang diperiksa tetap invariannya: pengiriman memakai file_id, dan
# ada penjagaan status sinkron di jalur itu -- bukan di berkas mana
# tepatnya kodenya berada.
check('cache->fileId(' in delivery, "pengiriman ke pengguna memakai file_id (lewat cache)")
check(os.path.exists(CACHE) and 'isSyncedToTelegram()' in read(CACHE),
      "jalur file_id memeriksa status sinkron dulu")
check('readStream' not in code_only(delivery) and 'StorageEngine' not in code_only(delivery),
      "pengiriman ke pengguna TIDAK menyentuh storage sama sekali")
check('canStart' in read('app/Enums/TelegramSyncStatus.php'),
      "ada penjagaan supaya video tersinkron tidak dikirim ulang ke Telegram")


# ------------------------------------------------------------ 5. deep link
print("\n== DEEP LINK ==")

dl = read(DEEPLINK)
check("'watch_'" in dl, "awalan watch_ sesuai spesifikasi")
check('bot_username' in dl, "tautan memakai username bot dari config")
check('ctype_digit' in dl, "id deep link divalidasi, bukan dicast begitu saja")

start = read('app/Telegram/Handlers/StartHandler.php')
check('TelegramDeepLink::episodeId' in start, "StartHandler membaca deep link episode")
check('syncTelegramUser' in start, "akun disinkronkan sebelum deep link diproses")
check(start.index('syncTelegramUser') < start.index('TelegramDeepLink::episodeId'),
      "sinkronisasi akun terjadi SEBELUM pemeriksaan deep link")

# Website harus menghasilkan tautannya.
views = ''.join(read(f) for f in glob.glob('resources/views/**/*.blade.php', recursive=True))
check('TelegramDeepLink' in views, "website menghasilkan deep link")
check('isSyncedToTelegram' in views,
      "tombol Telegram di website hanya muncul bila videonya memang sudah ada di sana")


# ------------------------------------------------------------- 6. playback
print("\n== PLAYBACK ==")

kb = read('app/Telegram/Keyboards/EpisodeKeyboard.php')
for label in ['Sebelumnya', 'Daftar Episode', 'Berikutnya', 'Favorit', 'Website']:
    check(label in kb, f"tombol {label} ada")

check('previous()' in kb and 'next()' in kb,
      "Previous/Next dibangun dari relasi episode, bukan dari tebakan nomor")
check('episodeList' in kb, "ada keyboard daftar episode")

lst = read('app/Telegram/Handlers/EpisodeListHandler.php')
check('forPage(' in lst, "daftar episode berhalaman")
check('episode_page_size' in lst, "ukuran halaman dibaca dari config")
check('max(1, min(' in lst, "nomor halaman dari callback dijepit ke rentang yang sah")


# ----------------------------------------------------------- 7. membership
print("\n== MEMBERSHIP ==")

check('EpisodeAccessService' in delivery, "hak menonton ditanyakan ke EpisodeAccessService")
check('is_premium' not in code_only(delivery),
      "aturan premium TIDAK ditulis ulang di lapisan Telegram")
check('upgrade()' in kb, "ada tombol Upgrade saat akses ditolak")
check('EpisodeKeyboard::upgrade()' in delivery, "tombol Upgrade benar-benar dikirim")

premium = read('app/Telegram/Handlers/PremiumHandler.php')
check('Kedaluwarsa' in premium, "keadaan Expired dibedakan dari Free")


# --------------------------------------------- 8. continue watching & favorit
print("\n== SINKRON WEBSITE <-> TELEGRAM ==")

cont = read('app/Telegram/Handlers/ContinueWatchingHandler.php')
hist = read('app/Telegram/Handlers/HistoryHandler.php')
fav = read('app/Telegram/Handlers/FavoriteHandler.php')

check('WatchHistoryService' in cont, "lanjut menonton membaca WatchHistoryService")
check('WatchHistoryService' in hist, "riwayat membaca WatchHistoryService")
check('FavoriteService' in fav, "favorit memakai FavoriteService")
check('history->save(' in delivery, "riwayat ditulis setelah video terkirim")

# Tidak ada query mentah ke tabel yang sudah punya service.
bad = [f for f in HANDLERS
       if re.search(r'WatchHistory::|Favorite::query|DB::table', code_only(read(f)))]
check(not bad, "handler tidak menembus service untuk membaca tabel sendiri")
for b in bad:
    print("        -", b)


# ------------------------------------------------------------- 9. antrean
print("\n== ANTREAN ==")

job = read(JOB)
check('ShouldQueue' in job, "sinkronisasi berjalan di antrean")
check('public function failed' in job,
      "ada hook failed() supaya baris tidak tersangkut di PROCESSING")
check('sync.queue' in job, "nama antrean dibaca dari config")
check("SyncEpisodeVideoToTelegram::dispatch" in read(CTRL),
      "panel mengantrekan, tidak mengirim di dalam request")

ctrl = read(CTRL)
BULK = 'app/Services/Telegram/TelegramBulkService.php'

check('blocker(' in ctrl, "panel memakai alasan penolakan yang sama dengan service")

# Sejak 8.7 Retry satuan dan Retry massal sama-sama lewat TelegramBulkService,
# jadi batasnya cukup ditegakkan di satu tempat -- justru itu yang dimaksud
# "tidak ada duplicate code".
check(os.path.exists(BULK) and 'max_retry' in read(BULK),
      "batas percobaan ulang ditegakkan di satu tempat")


# ------------------------------------------------------------- 10. logging
print("\n== LOGGING ==")

for peristiwa, berkas in [
    ('sync.started', SYNC), ('sync.success', SYNC), ('sync.failed', SYNC),
    ('sync.retry', SYNC),
    ('delivery.sent', DELIVERY), ('membership.denied', DELIVERY),
    ('history.updated', DELIVERY),
    ('favorite.updated', fav and 'app/Telegram/Handlers/FavoriteHandler.php'),
    ('callback.error', CB),
]:
    check(peristiwa in read(berkas), f"log peristiwa {peristiwa}")


# -------------------------------------------------------- 11. error handling
print("\n== PENANGANAN GALAT ==")

cb = read(CB)
check('catch (Throwable' in cb, "callback yang meledak tidak membuat pengguna diam")
check('TelegramException' in cb, "kegagalan Telegram diteruskan, bukan ditelan diam-diam")

watch = read('app/Telegram/Handlers/WatchHandler.php')
check('=== null' in watch, "episode yang tidak ada dijawab, bukan diabaikan")
check('missing_file_id' in delivery, "video tanpa file_id dicatat dan dijawab")

# Konfirmasi callback hanya di satu tempat: dua jawaban untuk satu penekanan
# ditolak Telegram, dan pemberitahuan yang kedua tidak sampai.
ganda = [f for f in HANDLERS
         if norm(f) != CB and 'answerCallbackQuery' in code_only(read(f))]
check(not ganda, "answerCallbackQuery hanya dipanggil dari CallbackHandler")
for g in ganda:
    print("        -", g)


# ------------------------------------------- 12. tidak ada jalur HTTP baru
print("\n== TETAP SATU PINTU ==")

bad = [f for f in APP if norm(f) != CLIENT and 'Http::' in code_only(read(f))]
check(not bad, "Http:: masih hanya ada di TelegramClient")
for b in bad:
    print("        -", b)

bad = [f for f in APP if norm(f) != CLIENT and 'api.telegram.org' in code_only(read(f))]
check(not bad, "URL Telegram tidak dirakit di luar TelegramClient")
for b in bad:
    print("        -", b)

for f in [SYNC, DELIVERY, CTRL] + HANDLERS:
    src = read(f)
    if 'telegram->' not in src and 'TelegramService' not in src:
        continue
    check('TelegramServiceInterface' in src or 'TelegramDeliveryService' in src
          or 'TelegramVideoSyncService' in src,
          f"{os.path.basename(f)} memakai kontrak, bukan kelas konkret")


# ------------------------------------------------------ 13. tidak dead code
print("\n== TIDAK ADA JALUR MATI ==")

routes = read('routes/web.php')
for nama in ['telegram-sync.index', 'telegram-sync.sync', 'telegram-sync.retry',
             'telegram-sync.all']:
    check(f"'{nama}'" in routes, f"route {nama} terdefinisi")

sidebar = read('resources/views/web/layouts/admin.blade.php')
check('admin.telegram-sync.index' in sidebar, "halaman sinkron punya menu sidebar")

# Setiap handler baru benar-benar dipanggil dari suatu tempat.
semua = ''.join(read(f) for f in APP)
for kelas in ['WatchHandler', 'EpisodeListHandler']:
    check(semua.count(kelas) >= 2, f"{kelas} dipanggil, bukan kelas yatim")

# Setiap awalan callback yang dibuat keyboard punya cabang di router.
for konstanta in ['WATCH', 'LIST', 'FAVORITE', 'UPGRADE']:
    check(f'EpisodeKeyboard::{konstanta}' in cb,
          f"awalan callback {konstanta} ditangani CallbackHandler")


# ------------------------------------------------------------ 14. config
print("\n== KONFIGURASI ==")

cfg = read('config/telegram.php')
env = read('.env.example')
for kunci, envkey in [('storage_chat_id', 'TELEGRAM_STORAGE_CHAT_ID'),
                      ('episode_page_size', 'TELEGRAM_EPISODE_PAGE_SIZE')]:
    check(f"'{kunci}'" in cfg, f"config punya {kunci}")
    check(envkey in env, f".env.example punya {envkey}")
    check(f"telegram.{kunci}" in ''.join(read(f) for f in APP),
          f"config {kunci} benar-benar dibaca kode")

for kunci in ['timeout', 'max_retry', 'queue']:
    check(f"telegram.sync.{kunci}" in ''.join(read(f) for f in APP),
          f"config sync.{kunci} benar-benar dibaca kode")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT SPRINT 8.2-8.6: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
