#!/usr/bin/env python3
"""
Self-audit Sprint 8.7-8.9 - Telegram Finalization.

Memeriksa lapisan yang ditambahkan sprint ini: admin tools, otomatisasi,
scheduler, notifikasi, cache, pembatas laju, dan pemulihan.

Yang paling ditekankan: **tidak ada aturan yang ditulis dua kali**. Sprint ini
menambahkan aksi massal di atas aksi satuan yang sudah ada, dan itu jenis
perubahan yang paling gampang melahirkan dua definisi tentang hal yang sama.

Seperti audit 8.1 dan 8.2, setiap pemeriksaan yang mencari token kode
menjalankan code_only() lebih dulu.
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
SEMUA = ''.join(read(f) for f in APP)

BULK = 'app/Services/Telegram/TelegramBulkService.php'
HEALTH = 'app/Services/Telegram/TelegramHealthService.php'
ALERT = 'app/Services/Telegram/TelegramAlertService.php'
CACHE = 'app/Services/Telegram/TelegramCacheService.php'
RATE = 'app/Services/Telegram/TelegramRateLimiter.php'
OBS = 'app/Observers/EpisodeVideoObserver.php'
AUTO = 'app/Console/Commands/TelegramAutomation.php'
VERIFY = 'app/Jobs/VerifyTelegramFileId.php'
CTRL = 'app/Http/Controllers/Admin/TelegramSyncController.php'
LOGCTRL = 'app/Http/Controllers/Admin/TelegramLogController.php'
CLIENT = 'app/Services/Telegram/TelegramClient.php'
SYNCSVC = 'app/Services/Telegram/TelegramVideoSyncService.php'

# ---------------------------------------------------------------- 1. berkas
print("\n== BERKAS ==")

for f in [BULK, HEALTH, ALERT, CACHE, RATE, OBS, AUTO, VERIFY, CTRL, LOGCTRL,
          'resources/views/web/pages/admin/telegram-log.blade.php',
          'resources/views/web/pages/admin/telegram-sync.blade.php']:
    check(os.path.exists(f), f"ada: {f}")


# ------------------------------------------------------------ 2. admin tools
print("\n== ADMIN TOOLS ==")

ctrl = read(CTRL)

for bagian in ['health', 'stats', 'q', 'sort', 'dir']:
    check(f"'{bagian}'" in ctrl, f"halaman mengirim `{bagian}` ke view")

check('paginate(' in ctrl, "daftar berhalaman")
check('SORTABLE' in ctrl, "kolom urut memakai daftar tertutup")

# Nama kolom dari query string tidak boleh langsung masuk ke orderBy.
check(not re.search(r"orderBy\(\s*\$request->query", code_only(ctrl)),
      "nama kolom dari query string tidak langsung dipakai orderBy")

# `orWhereHas` juga dihitung. Versi pertama pemeriksaan ini mencari
# 'whereHas' persis dan melaporkan GAGAL palsu, karena kodenya memakai
# `orWhereHas` -- huruf W besar. Cocokkan tanpa peduli besar-kecilnya.
check(re.search(r'whereHas\(', ctrl, re.I) is not None,
      "pencarian menembus relasi drama dan episode")

view = read('resources/views/web/pages/admin/telegram-sync.blade.php')
for tombol in ['Bulk Sync', 'Bulk Retry', 'Bulk Cancel', 'Refresh Status',
               'Verifikasi file_id']:
    check(tombol in view, f"tombol {tombol} ada di panel")

# Form massal harus DI LUAR tabel: form bersarang dibuang parser HTML.
check('form="bulk-form"' in view, "kotak centang dihubungkan lewat atribut form")
check(view.index('</table>') < view.index('id="bulk-form"'),
      "form massal berada SETELAH tabel, bukan melingkupinya")

log = read(LOGCTRL)
READER = 'app/Support/LogFileReader.php'

check('telegram.' in log, "log viewer menyaring baris telegram")

# Sejak Phase 9 pembacaan ekor berkas diangkat ke LogFileReader supaya Log
# Sistem tidak menyalin parser kedua. Yang diperiksa tetap invariannya:
# berkas dibaca dari ujung, bukan dimuat seluruhnya -- bukan di berkas mana
# tepatnya kodenya berada.
check(os.path.exists(READER) and 'fseek' in read(READER) and 'LogFileReader' in log,
      "log dibaca dari ujung berkas lewat pembaca bersama")


# -------------------------------------------------------------- 3. bulk
print("\n== AKSI MASSAL ==")

bulk = read(BULK)

for aksi in ['sync', 'retry', 'cancel', 'refresh', 'verify']:
    check(f'function {aksi}(' in bulk, f"aksi massal {aksi}() ada")

check('LIMIT' in bulk, "aksi massal dibatasi jumlahnya")
check('blocker(' in bulk,
      "aksi massal memakai aturan penolakan yang sama dengan aksi satuan")
check('dispatch(' in bulk, "aksi massal lewat antrean, bukan dikerjakan langsung")

# Tidak boleh ada pengiriman langsung di service massal.
check('sendVideo' not in code_only(bulk), "aksi massal tidak mengirim berkas sendiri")

# Retry satuan dan massal harus lewat jalur yang sama.
check('bulk->retry(' in ctrl,
      "Retry satuan memakai TelegramBulkService, bukan menyalin aturannya")
check('max_retry' in bulk, "batas percobaan ulang ditegakkan di satu tempat")
check('max_retry' not in code_only(ctrl),
      "controller tidak menyalin batas percobaan ulang")


# ---------------------------------------------------------- 4. otomatisasi
print("\n== OTOMATISASI ==")

obs = read(OBS)
check('auto_sync' in obs, "auto sync dibaca dari config")
check('blocker(' in obs, "auto sync menyaring yang sudah pasti gagal lebih dulu")
check('dispatch(' in obs, "auto sync lewat antrean")

prov = read('app/Providers/AppServiceProvider.php')
check('EpisodeVideo::observe' in prov, "observer terdaftar")
check('EpisodeVideoObserver' in prov, "observer di-import")

# Observer, bukan panggilan di dalam service upload -- supaya ketiga jalur
# pembuatan baris ikut tertangkap tanpa mengubah modul lama.
for lama in ['app/Services/EpisodeVideoService.php',
             'app/Services/UploadQueueService.php']:
    if os.path.exists(lama):
        check('SyncEpisodeVideoToTelegram' not in read(lama),
              f"{os.path.basename(lama)} tidak disentuh untuk auto sync")

auto = read(AUTO)
for tugas in ['retry', 'healthCheck', 'cleanup']:
    check(f'function {tugas}(' in auto, f"perintah otomatisasi punya {tugas}()")

check('purgeTempFiles' in auto, "berkas sementara yang tertinggal dibersihkan")
check('tgsync_' in auto, "pembersihan menyasar pola berkas sementara yang benar")
check('schedulerError' in auto, "perintah terjadwal yang gagal mengirim peringatan")

console = read('routes/console.php')
check('telegram:auto retry' in console, "scheduler menjalankan retry")
check('telegram:auto health' in console, "scheduler menjalankan health check")
check('telegram:auto cleanup' in console, "scheduler menjalankan cleanup")
check(console.count('withoutOverlapping()') >= 3,
      "setiap jadwal memakai withoutOverlapping")
check('schedule:run' in console, "cara memasang cron ditulis di berkasnya")


# --------------------------------------------------------- 5. notifikasi
print("\n== NOTIFIKASI ==")

alert = read(ALERT)
GENERIC = 'app/Services/Monitoring/AlertService.php'

for jenis in ['syncFailed', 'queueFailed', 'apiError', 'botOffline', 'schedulerError']:
    check(f'function {jenis}(' in alert, f"peringatan {jenis} ada")

# Sejak Phase 9 penahan dan pengirimannya diangkat ke AlertService, supaya
# peringatan backup dan basis data tidak menyalin aturan yang sama. Yang
# diperiksa tetap invariannya, di tempatnya yang sekarang.
generic = read(GENERIC) if os.path.exists(GENERIC) else ''

check('Cache::add' in generic, "peringatan ditahan supaya tidak membanjiri")
check('throttle_minutes' in generic, "penahan dibaca dari config")
check('catch (Throwable)' in generic,
      "kegagalan mengirim peringatan tidak menggagalkan pekerjaan yang melaporkannya")
check('Log::channel' in generic, "peringatan selalu masuk log, tidak hanya Telegram")
check('AlertService' in alert,
      "TelegramAlertService meneruskan, bukan menyalin logika penahan")

check('alerts->syncFailed' in read(SYNCSVC), "sinkronisasi gagal memicu peringatan")
check('queueFailed' in read('app/Jobs/SyncEpisodeVideoToTelegram.php'),
      "job yang gagal memicu peringatan")


# ------------------------------------------------------------- 6. optimasi
print("\n== OPTIMASI ==")

rate = read(RATE)
check('per_second' in rate, "pembatas laju membaca kuota dari config")
check('max_wait_ms' in rate, "pembatas laju punya batas menunggu")
check('catch (Throwable)' in rate,
      "cache yang rusak tidak menghentikan pengiriman")

client = read(CLIENT)
check('limiter()' in client, "client memakai pembatas laju")
check('acquire()' in client, "kuota diambil sebelum permintaan dikirim")
# Pembatas laju tidak menggantikan penanganan 429.
check('retry_after' in client, "penanganan 429 tetap ada setelah pembatas laju")
check('backoffMs' in client, "backoff berganda tetap ada")

cache = read(CACHE)
check('function fileId(' in cache, "file_id di-cache")
check('function episode(' in cache, "metadata episode di-cache")
check('function forget(' in cache, "cache bisa dibuang secara eksplisit")
check('cache->forget(' in obs, "cache dibuang saat baris video berubah")
check("=== '' ? null" in cache or "'' ? null" in cache,
      "hasil kosong ikut di-cache supaya tidak menembus setiap permintaan")

# Query: relasi dimuat di awal, bukan satu per satu di dalam perulangan.
check("with(['episode.drama'" in ctrl or "with(['episode.drama', 'provider']" in ctrl,
      "daftar memuat relasi di awal, menghindari query N+1")


# ------------------------------------------------------- 7. pemulihan galat
print("\n== PEMULIHAN ==")

health = read(HEALTH)
check('stuckQuery' in health, "ada cara menemukan baris yang tersangkut")
check('stuck_minutes' in health, "batas tersangkut dibaca dari config")
check('catch (Throwable)' in health, "alat pemeriksa tidak ikut rusak saat memeriksa yang rusak")

check('stuckQuery' in auto, "cleanup memakai definisi tersangkut yang sama")
check('stuckQuery' not in code_only(bulk) or 'stuck_minutes' in bulk,
      "refresh massal memakai batas tersangkut, bukan angka tersendiri")

verify = read(VERIFY)
check('getFile' in verify, "verifikasi memakai getFile, bukan mengirim ulang berkas")
check('isConnectionProblem' in verify,
      "gangguan jaringan tidak dianggap file_id rusak")
check('Sinkronkan ulang dari storage' in verify,
      "file_id rusak diarahkan untuk disinkronkan ulang dari storage, bukan diunggah lagi")

# Tidak ada jalur pemulihan yang meminta unggah dari komputer.
for f in [BULK, AUTO, VERIFY, SYNCSVC]:
    check('UploadedFile' not in code_only(read(f)),
          f"{os.path.basename(f)} tidak punya jalur unggah dari komputer")


# ---------------------------------------------------------- 8. tetap bersih
print("\n== TETAP BERSIH ==")

bad = [f for f in APP if norm(f) != CLIENT and 'Http::' in code_only(read(f))]
check(not bad, "Http:: masih hanya ada di TelegramClient")
for b in bad:
    print("        -", b)

bad = [f for f in APP
       if f.startswith('app/Services/Telegram/')
       and re.search(r'\bStorage::', code_only(read(f)))]
check(not bad, "lapisan Telegram tidak menyentuh facade Storage")

# ActivityLogger menerima Model, bukan int -- kesalahan yang sudah pernah
# lolos ke kode di sprint ini.
bad = []
for f in glob.glob('app/Http/Controllers/**/*.php', recursive=True):
    for m in re.finditer(r"ActivityLogger::class\)->log\([^;]*?\)\s*;", read(f), re.S):
        if re.search(r",\s*\$\w+->id\s*[,)]", m.group(0)):
            bad.append(f"{f}: {m.group(0)[:70]}")
check(not bad, "ActivityLogger tidak pernah dipanggil dengan id, hanya Model atau null")
for b in bad:
    print("        -", b)

# Setiap kelas baru benar-benar dipakai.
for kelas in ['TelegramBulkService', 'TelegramHealthService', 'TelegramAlertService',
              'TelegramCacheService', 'TelegramRateLimiter', 'EpisodeVideoObserver',
              'VerifyTelegramFileId']:
    check(SEMUA.count(kelas) >= 2, f"{kelas} dipakai, bukan kelas yatim")

routes = read('routes/web.php')
for nama in ['telegram-sync.bulk', 'telegram-log.index']:
    check(f"'{nama}'" in routes, f"route {nama} terdefinisi")

sidebar = read('resources/views/web/layouts/admin.blade.php')
check('admin.telegram-log.index' in sidebar, "log viewer punya menu sidebar")


# ------------------------------------------------------------- 9. config
print("\n== KONFIGURASI ==")

cfg = read('config/telegram.php')
env = read('.env.example')

pasangan = [
    ('automation.auto_sync', 'TELEGRAM_AUTO_SYNC'),
    ('automation.auto_retry', 'TELEGRAM_AUTO_RETRY'),
    ('automation.stuck_minutes', 'TELEGRAM_STUCK_MINUTES'),
    ('automation.health_check', 'TELEGRAM_HEALTH_CHECK'),
    ('alerts.chat_id', 'TELEGRAM_ALERT_CHAT_ID'),
    ('alerts.throttle_minutes', 'TELEGRAM_ALERT_THROTTLE'),
    ('rate_limit.enabled', 'TELEGRAM_RATE_LIMIT'),
    ('rate_limit.per_second', 'TELEGRAM_RATE_PER_SECOND'),
    ('rate_limit.max_wait_ms', 'TELEGRAM_RATE_MAX_WAIT_MS'),
    ('cache.enabled', 'TELEGRAM_CACHE'),
    ('cache.ttl', 'TELEGRAM_CACHE_TTL'),
]

for kunci, envkey in pasangan:
    check(envkey in env, f".env.example punya {envkey}")
    check(f"telegram.{kunci}" in SEMUA, f"config telegram.{kunci} benar-benar dibaca kode")

check('$angka' in cfg and '$boolean' in cfg,
      "nilai .env yang kosong tetap dijaga tidak jatuh ke nol")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT SPRINT 8.7-8.9: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
