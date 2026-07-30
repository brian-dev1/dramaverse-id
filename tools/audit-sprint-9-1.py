#!/usr/bin/env python3
"""
Self-audit Phase 9 - Production Ready.

Menekankan hal yang paling gampang salah di sprint yang menambahkan lapisan
operasional di atas aplikasi yang sudah jalan:

  1. Menulis ulang pemeriksaan yang sudah ada (Storage 7.8, Telegram 8.9)
     alih-alih memanggilnya -- yang berakibat dua halaman bisa mengatakan hal
     berbeda tentang sistem yang sama.
  2. Menyalin logika penahan peringatan ke setiap pemakainya.
  3. Nama berkas dari luar yang langsung digabung ke path.
  4. Kunci config yang ditambahkan tapi tidak pernah dibaca.

Seperti audit sprint sebelumnya, setiap pemeriksaan yang mencari token kode
menjalankan code_only() lebih dulu -- pelajaran dari tujuh kegagalan palsu
yang tercatat di STATUS.md.
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

HEALTH = 'app/Services/Monitoring/SystemHealthService.php'
ALERT = 'app/Services/Monitoring/AlertService.php'
BACKUP = 'app/Services/Backup/BackupService.php'
READER = 'app/Support/LogFileReader.php'
MON = 'app/Http/Controllers/Admin/MonitoringController.php'
SYSLOG = 'app/Http/Controllers/Admin/SystemLogController.php'
TGLOG = 'app/Http/Controllers/Admin/TelegramLogController.php'
TGALERT = 'app/Services/Telegram/TelegramAlertService.php'
AUTH = 'app/Listeners/LogAuthenticationEvents.php'
JOB = 'app/Jobs/RunBackupJob.php'
CMD = 'app/Console/Commands/BackupRun.php'

# ---------------------------------------------------------------- 1. berkas
print("\n== BERKAS ==")

for f in [HEALTH, ALERT, BACKUP, READER, MON, SYSLOG, AUTH, JOB, CMD,
          'config/backup.php',
          'resources/views/web/pages/admin/monitoring.blade.php',
          'resources/views/web/pages/admin/system-log.blade.php']:
    check(os.path.exists(f), f"ada: {f}")

mig = glob.glob('database/migrations/*add_production_indexes*.php')
check(len(mig) == 1, "migration indeks produksi ada")


# ------------------------------------------------- 2. tidak menulis ulang
print("\n== MENGGABUNG, BUKAN MENULIS ULANG ==")

health = read(HEALTH)

check('TelegramHealthService' in health,
      "kesehatan Telegram dipanggil dari service yang sudah ada (8.9)")
check('StorageMonitorService' in health,
      "kesehatan Storage dipanggil dari service yang sudah ada (7.8)")
check('BackupService' in health, "keadaan cadangan dipanggil dari BackupService")
check('LogFileReader' in health, "statistik galat memakai pembaca log bersama")

# Tidak boleh memeriksa provider storage sendiri.
check('StorageProvider::' not in code_only(health),
      "monitoring tidak menghitung provider storage sendiri")
# `->getMe(` dengan tanda kurungnya, BUKAN substring `getMe`.
# Versi pertama pemeriksaan ini melaporkan GAGAL palsu karena
# `$e->getMessage()` memuat huruf `getMe` di dalamnya.
check(re.search(r'->getMe\s*\(', code_only(health)) is None,
      "monitoring tidak memanggil Telegram API sendiri")

# Log reader dipakai kedua halaman log, bukan disalin.
check('LogFileReader' in read(SYSLOG), "Log Sistem memakai LogFileReader")
check('LogFileReader' in read(TGLOG), "Log Telegram memakai LogFileReader")
check('fseek' not in code_only(read(TGLOG)) and 'fseek' not in code_only(read(SYSLOG)),
      "tidak ada parser log kedua di controller mana pun")
check('fseek' in read(READER), "pembacaan ekor berkas ada di satu tempat")

# Penahan peringatan hanya di satu tempat.
check('Cache::add' in read(ALERT), "penahan peringatan ada di AlertService")
check('Cache::add' not in code_only(read(TGALERT)),
      "TelegramAlertService tidak menyalin logika penahan")
check('AlertService' in read(TGALERT),
      "TelegramAlertService meneruskan ke AlertService")


# ------------------------------------------------------------- 3. keamanan
print("\n== KEAMANAN ==")

mon = read(MON)

check('preg_match' in mon, "nama berkas cadangan divalidasi dengan pola")
check('realpath' in mon, "path hasil digabung diperiksa ulang dengan realpath")
check('str_starts_with' in mon, "path wajib berada di dalam folder cadangan")

# Nama dari request tidak boleh langsung digabung ke path.
check(not re.search(r"directory\(\)\s*\.\s*['\"]/['\"]\s*\.\s*\$request", code_only(mon)),
      "nama dari request tidak langsung digabung ke path")

syslog = read(SYSLOG)
check('CHANNELS' in syslog, "penyaring log memakai daftar tertutup")
check('array_key_exists' in syslog, "nilai penyaring dari luar dicocokkan ke daftar")

auth = read(AUTH)
for peristiwa in ['handleLogin', 'handleLogout', 'handleFailed', 'handleLockout']:
    check(f'function {peristiwa}(' in auth, f"jejak audit {peristiwa} ada")

# Kata sandi tidak boleh ikut tercatat.
check("credentials['password']" not in auth and '$event->credentials)' not in code_only(auth),
      "kata sandi tidak pernah masuk jejak audit")
check('catch (Throwable' in auth,
      "pencatatan yang gagal tidak menggagalkan proses masuk")

prov = read('app/Providers/AppServiceProvider.php')
for ev in ['Login::class', 'Logout::class', 'Failed::class', 'Lockout::class']:
    check(ev in prov, f"listener {ev} terdaftar")

# mysqldump: kata sandi lewat environment, bukan argumen.
backup = read(BACKUP)
check('MYSQL_PWD' in backup, "kata sandi mysqldump lewat environment")
check('--password' not in backup,
      "kata sandi TIDAK dilewatkan sebagai argumen baris perintah")
check('chmod' in backup, "berkas cadangan dibatasi izin bacanya")


# ------------------------------------------------------------- 4. cadangan
print("\n== CADANGAN ==")

check('function create(' in backup, "bisa membuat cadangan")
check('function verify(' in backup, "bisa memverifikasi cadangan")
check('function prune(' in backup, "bisa memangkas cadangan lama")
check('function ageInHours(' in backup, "umur cadangan bisa dibaca monitoring")

check('finally' in backup, "dump mentah dibuang di blok finally")
check('single-transaction' in backup, "dump tidak mengunci tabel")
check('env.backup' in backup, "konfigurasi ikut dicadangkan")
check('tar' in backup and 'tzf' in backup, "verifikasi membongkar daftar isi arsip")

# Cadangan tidak boleh menyalin video.
check('StorageEngine' not in code_only(backup) and 'readStream' not in code_only(backup),
      "cadangan TIDAK menyalin berkas video dari storage")

job = read(JOB)
check('ShouldQueue' in job, "cadangan manual berjalan di antrean")
check('critical(' in job, "kegagalan cadangan memicu peringatan kritis")
check('public function failed' in job, "job cadangan punya hook failed")

console = read('routes/console.php')
check('backup:run' in console, "cadangan dijadwalkan")
check('dailyAt' in console, "cadangan berjalan harian pada jam sepi")
check(console.count('withoutOverlapping()') >= 4, "setiap jadwal tanpa tumpang tindih")


# ----------------------------------------------------------- 5. monitoring
print("\n== MONITORING ==")

for bagian in ['database', 'cache', 'queue', 'scheduler', 'backupStatus',
               'server', 'telegramStatus', 'storageStatus', 'errors']:
    check(f'function {bagian}(' in health, f"pemeriksaan {bagian} ada")

check('HEARTBEAT' in health, "ada detak scheduler")
check('SystemHealthService::HEARTBEAT' in console,
      "detak scheduler benar-benar ditulis oleh scheduler")
check('Cache::put' in health, "cache diperiksa dengan menulis lalu membaca")
check(health.count('catch (Throwable') >= 6,
      "setiap pemeriksaan menangkap galatnya sendiri")

# Alat pemeriksa tidak boleh melempar.
check('throw ' not in code_only(health), "SystemHealthService tidak pernah melempar")

view = read('resources/views/web/pages/admin/monitoring.blade.php')
for label in ['Basis data', 'Cache', 'Antrean', 'Scheduler', 'Cadangan',
              'Server', 'Telegram', 'Storage', 'Galat']:
    check(label in view, f"dashboard menampilkan {label}")


# --------------------------------------------------------- 6. tetap bersih
print("\n== TETAP BERSIH ==")

CLIENT = 'app/Services/Telegram/TelegramClient.php'

bad = [f for f in APP if norm(f) != CLIENT and 'Http::' in code_only(read(f))]
check(not bad, "Http:: masih hanya ada di TelegramClient")
for b in bad:
    print("        -", b)

# ActivityLogger menerima Model, bukan int.
bad = []
for f in glob.glob('app/**/*.php', recursive=True):
    for m in re.finditer(r"ActivityLogger::class\)->log\([^;]*?\)\s*;", read(f), re.S):
        if re.search(r",\s*\$\w+->id\s*[,)]", m.group(0)):
            bad.append(f"{norm(f)}: {m.group(0)[:70]}")
check(not bad, "ActivityLogger tidak pernah dipanggil dengan id")
for b in bad:
    print("        -", b)

# Setiap kelas baru dipakai.
for kelas in ['SystemHealthService', 'AlertService', 'BackupService',
              'LogFileReader', 'RunBackupJob', 'LogAuthenticationEvents']:
    check(SEMUA.count(kelas) >= 2, f"{kelas} dipakai, bukan kelas yatim")

routes = read('routes/web.php')
for nama in ['monitoring.index', 'monitoring.backup', 'monitoring.verify',
             'monitoring.download', 'monitoring.prune', 'system-log.index']:
    check(f"'{nama}'" in routes, f"route {nama} terdefinisi")

sidebar = read('resources/views/web/layouts/admin.blade.php')
check('admin.monitoring.index' in sidebar, "monitoring punya menu sidebar")
check('admin.system-log.index' in sidebar, "log sistem punya menu sidebar")

# Semua route monitoring terlindungi izin.
check('permission:setting.manage' in routes, "route monitoring memakai middleware izin")


# ------------------------------------------------------------- 7. config
print("\n== KONFIGURASI ==")

cfg = read('config/backup.php')
env = read('.env.example')

for kunci, envkey in [('keep', 'BACKUP_KEEP'),
                      ('max_age_hours', 'BACKUP_MAX_AGE_HOURS'),
                      ('verify_after_create', 'BACKUP_VERIFY')]:
    check(f"'{kunci}'" in cfg, f"config/backup.php punya {kunci}")
    check(envkey in env, f".env.example punya {envkey}")
    check(f"backup.{kunci}" in SEMUA, f"config backup.{kunci} benar-benar dibaca kode")

check('is_numeric' in cfg, "nilai .env yang kosong dijaga tidak jatuh ke nol")


# --------------------------------------------------------------- 8. performa
print("\n== PERFORMA ==")

deploy = read('deploy.sh')
for perintah in ['config:cache', 'route:cache', 'view:cache', 'event:cache']:
    check(perintah in deploy, f"deploy.sh menjalankan {perintah}")

check('optimize:clear' in deploy, "cache lama dibersihkan sebelum dibangun ulang")

if mig:
    m = read(mig[0])
    check('hasTable' in m and 'hasColumn' in m,
          "migration indeks memeriksa keberadaan tabel dan kolom lebih dulu")
    check('indexAda' in m,
          "migration indeks tidak menabrak indeks yang sudah ada")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT PHASE 9: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
