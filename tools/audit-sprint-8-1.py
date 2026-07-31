#!/usr/bin/env python3
"""
Self-audit Sprint 8.1 - Telegram Core Service.

Sebagian pemeriksaan di sini berlaku terus, bukan hanya untuk sprint ini:

  - tidak ada Http:: ke Telegram di luar TelegramClient
  - controller tidak memanggil Telegram API langsung
  - setiap kunci config/telegram.php benar-benar dibaca kode
  - token bot tidak pernah bisa masuk ke konteks log

Yang terakhir itu sengaja dijadikan pemeriksaan otomatis. STORAGE_TIMEOUT
membuktikan kunci config bisa hidup berbulan-bulan tanpa ada yang membacanya,
dan tidak ada satu pun alat yang menyadarinya.

CATATAN SOAL SKRIP AUDIT ITU SENDIRI
Rekam jejak proyek ini: routeparse.py (7.6), audit 7.7, dan audit 7.8 semuanya
menghasilkan GAGAL palsu karena menghitung sesuatu di dalam komentar atau
string. Karena itu setiap pemeriksaan di bawah yang mencari token kode
menjalankan code_only() lebih dulu - komentar dan isi string dibuang sebelum
dicocokkan. Versi pertama skrip ini pun menghasilkan dua GAGAL palsu tanpa
langkah itu; keduanya dicatat di SPRINT-8-1-SELESAI.md.
"""

import glob
import os
import re
import sys

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

PASSED = []
FAILED = []


def check(condition, label):
    (PASSED if condition else FAILED).append(label)
    if not condition:
        print("  GAGAL ", label)


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read()


def code_only(path_or_src, is_src=False):
    """Buang komentar dan isi string literal.

    Tanpa ini, kalimat penjelasan yang menyebut `Http::` atau `api.telegram.org`
    terhitung sebagai kode - persis kegagalan palsu yang sudah tiga kali
    terjadi di proyek ini.
    """
    s = path_or_src if is_src else read(path_or_src)
    s = re.sub(r'/\*.*?\*/', '', s, flags=re.S)
    s = re.sub(r'//[^\n]*', '', s)
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", s)
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    return s


def methods(path):
    return set(re.findall(r'function\s+(\w+)\s*\(', code_only(path)))


APP = sorted(glob.glob('app/**/*.php', recursive=True))
CLIENT = 'app/Services/Telegram/TelegramClient.php'
SERVICE = 'app/Services/Telegram/TelegramService.php'
ISERVICE = 'app/Services/Telegram/Contracts/TelegramServiceInterface.php'
ICLIENT = 'app/Services/Telegram/Contracts/TelegramClientInterface.php'
EXC = 'app/Services/Telegram/Exceptions/TelegramException.php'
IREPO = 'app/Repositories/Contracts/TelegramRepositoryInterface.php'
REPO = 'app/Repositories/TelegramRepository.php'


def norm(p):
    return p.replace('\\', '/')


# ---------------------------------------------------------------- 1. berkas
print("\n== BERKAS ==")

for f in [CLIENT, SERVICE, ISERVICE, ICLIENT, EXC, REPO, IREPO,
          'app/Services/Telegram/TelegramResponse.php',
          'app/Console/Commands/TelegramTest.php',
          'config/telegram.php']:
    check(os.path.exists(f), f"ada: {f}")

for f in ['app/Services/TelegramService.php',
          'app/Telegram/Services/TelegramService.php']:
    check(not os.path.exists(f), f"sudah dihapus: {f}")


# ------------------------------------------------- 2. satu jalur HTTP saja
print("\n== SATU JALUR HTTP ==")

# Sejak Phase 10 ada pemakai HTTP kedua yang sah: driver pembayaran.
# Invarian yang dijaga bukan "tidak ada HTTP di mana pun", melainkan "tidak
# ada HTTP KE TELEGRAM di luar TelegramClient". Versi sebelumnya melarang
# `Http::` secara buta dan menghasilkan GAGAL palsu pada AbstractGateway yang
# tidak menyentuh Telegram sama sekali.
bad = [f for f in APP
       if norm(f) != CLIENT
       and not norm(f).startswith('app/Services/Payments/')
       and 'Http::' in code_only(f)]
check(not bad, "Http:: hanya ada di TelegramClient (di luar lapisan pembayaran)")
for b in bad:
    print("        -", b)

bad = [f for f in APP if norm(f) != CLIENT and 'api.telegram.org' in code_only(f)]
check(not bad, "URL Telegram tidak dirakit di luar TelegramClient")
for b in bad:
    print("        -", b)

bad = [f for f in glob.glob('app/Http/Controllers/**/*.php', recursive=True)
       if 'Http::' in code_only(f) or 'api.telegram.org' in code_only(f)]
check(not bad, "nol controller memanggil Telegram API langsung")
for b in bad:
    print("        -", b)

check('Http' not in code_only(SERVICE), "TelegramService nol HTTP")
check('Http' not in code_only(REPO), "TelegramRepository nol HTTP")
check('curl' not in code_only(SERVICE).lower(), "TelegramService nol curl")

dead = [f for f in APP + glob.glob('routes/*.php')
        if 'App\\Telegram\\Services' in read(f)
        or re.search(r'App\\\\Services\\\\TelegramService[^A-Za-z]', read(f))]
check(not dead, "tidak ada referensi ke dua TelegramService lama")
for d in dead:
    print("        -", d)


# ------------------------------------------------------------ 3. konfigurasi
print("\n== KONFIGURASI ==")

cfg = read('config/telegram.php')
top = re.findall(r"^\s{4}'([a-z_]+)'\s*=>", cfg, re.M)
nested = re.findall(r"^\s{8}'([a-z_]+)'\s*=>", cfg, re.M)

others = ''.join(read(f) for f in APP)
others += ''.join(read(f) for f in glob.glob('config/*.php')
                  if norm(f) != 'config/telegram.php')
others += ''.join(read(f) for f in glob.glob('routes/*.php'))

unread = [k for k in top if k not in ('retry', 'logging')
          and f"telegram.{k}" not in others]
unread += ['retry/logging.' + k for k in nested if f".{k}'" not in others]

check(not unread, f"{len(top) + len(nested)} kunci config/telegram.php semuanya dibaca kode")
for u in unread:
    print("        - tidak pernah dibaca:", u)

check("'telegram'" not in code_only('config/services.php'),
      "config/services.php tidak lagi punya blok telegram tandingan")

env = read('.env.example')
for key in ['TELEGRAM_API_URL', 'TELEGRAM_TIMEOUT', 'TELEGRAM_CONNECT_TIMEOUT',
            'TELEGRAM_RETRY_TIMES', 'TELEGRAM_RETRY_SLEEP_MS',
            'TELEGRAM_RETRY_MAX_SLEEP_MS', 'TELEGRAM_RETRY_MAX_WAIT',
            'TELEGRAM_LOGGING', 'TELEGRAM_LOG_CHANNEL', 'TELEGRAM_LOG_PAYLOAD',
            'TELEGRAM_LOG_TEXT_LIMIT', 'TELEGRAM_PARSE_MODE',
            'TELEGRAM_UPLOAD_MAX_MB']:
    check(key in env, f".env.example memuat {key}")

# Nilai kosong di .env tidak boleh jatuh ke nol.
check('is_numeric' in cfg and 'max(' in cfg,
      "config/telegram.php menjaga nilai .env yang kosong (pelajaran STORAGE_TIMEOUT)")


# ----------------------------------------------------------------- 4. kontrak
print("\n== KONTRAK ==")

WAJIB = ['sendMessage', 'sendPhoto', 'sendVideo', 'sendDocument', 'editMessage',
         'deleteMessage', 'answerCallbackQuery', 'getFile', 'getMe']

iface_src = read(ISERVICE)
for m in WAJIB:
    check(f'function {m}(' in iface_src, f"kontrak punya {m}()")

for iface, impl in [(ISERVICE, SERVICE), (ICLIENT, CLIENT), (IREPO, REPO)]:
    missing = methods(iface) - methods(impl)
    check(not missing,
          f"{os.path.basename(impl)} mengimplementasi seluruh {os.path.basename(iface)}")
    for m in missing:
        print("        - belum diimplementasi:", m)

contract = methods(ISERVICE)
bad = []
for f in APP:
    src = read(f)
    if 'TelegramServiceInterface' not in src:
        continue
    for m in re.findall(r'telegram->(\w+)\(', code_only(src, is_src=True)):
        if m not in contract:
            bad.append(f"{f}: ->{m}()")
check(not bad, "semua pemanggil hanya memakai method yang ada di kontrak")
for b in bad:
    print("        - tidak ada di kontrak:", b)

prov = read('app/Providers/AppServiceProvider.php')
for i in ['TelegramClientInterface', 'TelegramServiceInterface',
          'TelegramRepositoryInterface']:
    check(f"{i}::class" in prov, f"{i} ter-bind di AppServiceProvider")

# Handler dan job menerima kontrak, bukan kelas konkret.
bad = [f for f in glob.glob('app/Telegram/Handlers/*.php')
       if 'TelegramServiceInterface $telegram' not in read(f)]
check(not bad, f"{len(glob.glob('app/Telegram/Handlers/*.php'))} handler menerima kontrak, bukan kelas konkret")
for b in bad:
    print("        -", b)


# ------------------------------------------------------------- 5. kerahasiaan
print("\n== TOKEN TIDAK BOCOR ==")

client = read(CLIENT)
check('function redact' in client, "TelegramClient punya redact()")
check(client.count('$this->redact(') >= 3,
      "redact() dipakai di jalur galat, jawaban tidak sah, dan payload log")
check(re.search(r'preg_replace\(.{0,40}\\d\{', client) is not None,
      "redact() punya jaring pengaman pola token, bukan hanya token yang sedang aktif")

exc_code = code_only(EXC)
check(not re.search(r'\$\w*previous\b', exc_code.replace('$previousClass', '')),
      "TelegramException tidak melampirkan exception Guzzle sebagai previous")
check('getPrevious' not in exc_code, "TelegramException tidak membaca previous")

# Konteks log dibangun dari daftar field tertutup, bukan dari seluruh payload.
listed = re.findall(r"foreach \(\[([^\]]+)\] as \$key\)", client)
check(any('chat_id' in blok for blok in listed),
      "konteks log dibangun dari daftar field yang ditulis eksplisit")
check('log_payload' in client,
      "isi pesan hanya ikut ke log bila log_payload dinyalakan")
check('bot_token' not in code_only(CLIENT).replace("config('", "config("),
      "tidak ada kunci token yang ditulis langsung ke konteks log")


# ------------------------------------------------------- 6. penanganan galat
print("\n== PENANGANAN GALAT ==")

for m in ['isBlockedByUser', 'isChatNotFound', 'isRateLimited',
          'isTokenProblem', 'isConnectionProblem', 'hint', 'logContext']:
    check(f'function {m}(' in read(EXC), f"TelegramException punya {m}()")

# Pengenalan "pengguna memblokir bot" tidak boleh lagi lewat pencocokan kata
# di tempat pemanggil.
bad = [f for f in APP
       if norm(f) != EXC
       and re.search(r"str_contains\(\s*\$\w*description", read(f))]
check(not bad, "tidak ada pemanggil yang menebak sebab galat dari potongan kalimat")
for b in bad:
    print("        -", b)

job = read('app/Jobs/SendTelegramBroadcast.php')
check('isBlockedByUser()' in job, "broadcast mengenali pemblokiran lewat exception")
check('deactivateByTelegramId' in job, "broadcast menonaktifkan lewat repository")
check('User::' not in code_only('app/Jobs/SendTelegramBroadcast.php'),
      "broadcast tidak lagi menyentuh model User langsung")

webhook = read('app/Http/Controllers/TelegramWebhookController.php')
check('TelegramException' in webhook,
      "webhook menahan TelegramException supaya Telegram tidak mengirim ulang update")

admin = read('app/Http/Controllers/Admin/TelegramController.php')
check('withRetries(1)' in admin,
      "halaman admin mematikan pengulangan supaya tidak menahan permintaan")
check('withTimeout(' in admin, "halaman admin memakai batas waktu lebih pendek")

check('retryDelayMs' in client, "client punya aturan pengulangan tersendiri")
check('retry_after' in client, "client menghormati retry_after dari Telegram")
check('max_retry_after' in client, "client menyerah bila jeda yang diminta terlalu lama")


# ---------------------------------------------------------------- 7. berkas
print("\n== PENGIRIMAN BERKAS ==")

check('assertFilesSendable' in client, "ukuran berkas diperiksa sebelum dikirim")
check('upload_max_mb' in client, "batas unggah dibaca dari config")
check('fileTooLarge' in read(EXC) and 'fileUnreadable' in read(EXC),
      "ada galat khusus untuk berkas terlalu besar dan tidak terbaca")
check('SplFileInfo' in read(ISERVICE),
      "kontrak menerima berkas di disk, bukan hanya URL dan file_id")


# ------------------------------------------------------------------ hasil
print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT SPRINT 8.1: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
