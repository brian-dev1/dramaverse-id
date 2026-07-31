#!/usr/bin/env python3
"""
Final audit Phase 12 - pemeriksaan seluruh proyek.

Bedanya dengan sembilan alat lain: yang ini **mencari masalah**, bukan
memastikan fakta yang sudah diketahui. Audit per-sprint menegaskan bahwa
keputusan sprint itu masih berlaku; alat ini menyisir seluruh pohon untuk
menemukan yang tidak sengaja tertinggal.

Yang dicari:
  - route yang menunjuk controller atau method yang tidak ada
  - kelas yang tidak pernah dirujuk siapa pun (dead code)
  - import yang tidak dipakai
  - kunci config yang tidak pernah dibaca
  - variabel .env.example yang tidak pernah dibaca config mana pun
  - view yang tidak pernah dirender
  - migration yang tabelnya tidak pernah disentuh kode
  - tanda-tanda kode kembar
  - hal-hal keamanan yang bisa dilihat secara statis

Seperti audit sebelumnya, setiap pencarian token kode menjalankan code_only()
lebih dulu. Rekam jejak proyek ini: tujuh kegagalan palsu, hampir semuanya
karena menghitung sesuatu di dalam komentar atau string.
"""

import glob
import os
import re
import sys
from collections import defaultdict

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

PASSED, FAILED = [], []


def check(cond, label, detail=None):
    (PASSED if cond else FAILED).append(label)
    if not cond:
        print("  GAGAL ", label)
        for d in (detail or [])[:12]:
            print("        -", d)
        if detail and len(detail) > 12:
            print(f"        ... dan {len(detail) - 12} lainnya")


def read(p):
    with open(p, encoding='utf-8') as fh:
        return fh.read()


def code_only(src):
    s = strip_comments(src)
    s = re.sub(r"'(?:\\.|[^'\\])*'", "''", s)
    s = re.sub(r'"(?:\\.|[^"\\])*"', '""', s)
    return s


def strip_comments(src):
    """Buang komentar TANPA menyentuh kode.

    Hanya docblock `/** */` dan komentar baris yang dibuang. Blok `/* */`
    biasa DIBIARKAN.

    Alasannya mahal: versi pertama alat ini memakai `/\*.*?\*/` untuk semua
    blok komentar, dan pada TelegramClient.php pola itu menelan bagian berkas
    sampai `Log::channel()` ikut hilang dari pandangan. Hasilnya
    `use ...\Log;` dilaporkan tidak dipakai -- dan ketika laporan itu
    dipercaya, import-nya benar-benar dibuang dan kelasnya jadi fatal error.
    """
    s = re.sub(r'/\*\*.*?\*/', '', src, flags=re.S)
    s = re.sub(r'^\s*(?:\*|//|\|).*$', '', s, flags=re.M)
    return s


def norm(p):
    return p.replace('\\', '/')


PHP = sorted(norm(f) for f in glob.glob('app/**/*.php', recursive=True))
BLADE = sorted(norm(f) for f in glob.glob('resources/views/**/*.blade.php', recursive=True))
ROUTES = sorted(norm(f) for f in glob.glob('routes/*.php'))
CONFIGS = sorted(norm(f) for f in glob.glob('config/*.php'))
MIGRATIONS = sorted(norm(f) for f in glob.glob('database/migrations/*.php'))
SEEDERS = sorted(norm(f) for f in glob.glob('database/seeders/**/*.php', recursive=True))

SRC = {f: read(f) for f in PHP + ROUTES + CONFIGS + SEEDERS}
VIEWSRC = {f: read(f) for f in BLADE}

ALL_PHP_TEXT = ''.join(SRC.values())
ALL_TEXT = ALL_PHP_TEXT + ''.join(VIEWSRC.values())


# ============================================================ 1. ROUTE -> KODE
print("\n== ROUTE MENUNJUK KODE YANG ADA ==")

# Peta nama kelas pendek -> daftar berkas. Nama pendek bisa bertabrakan
# antar namespace (Api\DashboardController vs Admin\DashboardController),
# jadi disimpan sebagai daftar dan disaring dengan prefix namespace-nya.
class_files = {}
short_files = defaultdict(list)
for f in PHP:
    m = re.search(r'^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)', SRC[f], re.M)
    if m:
        class_files.setdefault(m.group(1), f)
        short_files[m.group(1)].append(f)

parents = {}
for f in PHP:
    m = re.search(r'^(?:final\s+|abstract\s+)?class\s+\w+\s+extends\s+(\w+)', SRC[f], re.M)
    if m:
        parents[f] = m.group(1)


def has_method(f, method, depth=0):
    """Method ada di kelas ini atau di salah satu induknya.

    Tanpa menelusuri induk, setiap controller yang mewarisi index() dari
    AdminCrudController dilaporkan tidak punya index() -- dan itu memang
    yang terjadi pada versi pertama alat ini.
    """
    if f is None or depth > 6:
        return False
    if re.search(rf'function\s+{re.escape(method)}\s*\(', SRC[f]):
        return True
    return has_method(class_files.get(parents.get(f)), method, depth + 1)


def resolve(prefix, kelas):
    """Pilih berkas yang namespace-nya cocok dengan prefix di route."""
    kandidat = short_files.get(kelas, [])
    if prefix:
        cocok = [f for f in kandidat if f"/{prefix.strip(chr(92))}/" in f]
        if cocok:
            return cocok[0]
    return kandidat[0] if kandidat else None


route_src = ''.join(SRC[f] for f in ROUTES)

missing = []
for m in re.finditer(r'\[\s*((?:\w+\\)*)(\w+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]', route_src):
    prefix, kelas, method = m.group(1), m.group(2), m.group(3)
    f = resolve(prefix, kelas)
    if f is None:
        missing.append(f"{kelas} tidak ditemukan sebagai kelas")
        continue
    if not has_method(f, method):
        missing.append(f"{kelas}::{method}() tidak ada di {f} maupun induknya")

check(not missing, "semua route menunjuk controller dan method yang ada", missing)

# Controller __invoke: Route::x(..., Kelas::class)
invoke_bad = []
for m in re.finditer(r'Route::\w+\(\s*[\'"][^\'"]*[\'"]\s*,\s*((?:\w+\\)*)(\w+)::class\s*\)', route_src):
    kelas = m.group(2)
    f = resolve(m.group(1), kelas)
    if f and 'function __invoke' not in SRC[f]:
        invoke_bad.append(f"{kelas} dipakai sebagai single-action tapi tidak punya __invoke()")
check(not invoke_bad, "controller single-action punya __invoke()", invoke_bad)


# ================================================================ 2. DEAD CODE
print("\n== DEAD CODE ==")

# Kelas yang namanya tidak pernah muncul di berkas lain mana pun.
#
# Yang dikecualikan: kelas yang ditemukan framework lewat konvensi, bukan
# lewat penyebutan nama - migration anonim, seeder, provider, middleware
# (terdaftar lewat alias), policy, dan command (auto-discover).
SKIP_DIRS = ('app/Providers/', 'app/Console/Commands/', 'app/Policies/',
             'app/Http/Middleware/', 'app/Exceptions/')

# Kelas yang sengaja dipertahankan meski belum dirujuk, beserta alasannya.
# Daftar ini harus tetap pendek: setiap tambahan adalah utang yang harus
# dijelaskan, bukan cara mendiamkan alat.
DIPERTAHANKAN = {
    # Tabelnya sudah ada di produksi dan berisi data. Model ini satu-satunya
    # jalan menjangkaunya; menghapusnya berarti data itu tidak bisa dibaca
    # lagi tanpa SQL mentah. Fiturnya menyusul, tabelnya tidak dibuang.
    'Media': 'tabel media ada di produksi',
    'Review': 'tabel reviews ada di produksi, modul ulasan belum dikerjakan',
    # Dipakai lewat resolusi otomatis Laravel, bukan lewat penyebutan nama.
    'UserResource': 'dipakai API resource collection',
    'DramaFilter': 'dipakai lewat pipeline filter',
}

yatim = []
for kelas, f in class_files.items():
    if any(f.startswith(d) for d in SKIP_DIRS):
        continue
    if kelas in DIPERTAHANKAN:
        continue
    # Hitung penyebutan di luar berkasnya sendiri.
    lain = sum(1 for g, s in SRC.items() if g != f and re.search(rf'\b{re.escape(kelas)}\b', s))
    lain += sum(1 for s in VIEWSRC.values() if re.search(rf'\b{re.escape(kelas)}\b', s))
    if lain == 0:
        yatim.append(f"{kelas} ({f}) tidak dirujuk berkas mana pun")

check(not yatim, f"{len(class_files)} kelas semuanya dirujuk dari suatu tempat", yatim)

# Import yang tidak dipakai.
unused_use = []
for f in PHP + ROUTES:
    src = SRC[f]
    # strip_comments, bukan code_only: nama kelas bisa muncul sebagai
    # type hint di dalam string tipe generik, dan yang penting di sini
    # adalah TIDAK menelan kode.
    body = strip_comments(src)
    # Buang baris use itu sendiri sebelum mencari pemakaiannya.
    body_wo_use = re.sub(r'^use\s+[^;]+;', '', body, flags=re.M)
    for m in re.finditer(r'^use\s+([\w\\]+)(?:\s+as\s+(\w+))?;', src, re.M):
        alias = m.group(2) or m.group(1).split('\\')[-1]
        if not re.search(rf'\b{re.escape(alias)}\b', body_wo_use):
            unused_use.append(f"{f}: use {m.group(1)}")

check(not unused_use, "tidak ada import yang tidak dipakai", unused_use)


# ============================================================== 3. KONFIGURASI
print("\n== KONFIGURASI ==")

# Kunci tingkat atas tiap config yang tidak pernah dibaca.
#
# config/app.php, auth, cache, database, filesystems, logging, mail, queue,
# session dibaca framework sendiri - tidak diperiksa.
FRAMEWORK_CFG = {'app', 'auth', 'cache', 'database', 'filesystems', 'logging',
                 'mail', 'queue', 'session', 'services'}

cfg_unread = []
for f in CONFIGS:
    nama = os.path.basename(f)[:-4]
    if nama in FRAMEWORK_CFG:
        continue
    lain = ''.join(s for g, s in SRC.items() if g != f) + ''.join(VIEWSRC.values())
    for m in re.finditer(r"^\s{4}'([a-z_]+)'\s*=>", SRC[f], re.M):
        kunci = m.group(1)
        if f"{nama}.{kunci}" not in lain:
            cfg_unread.append(f"config/{nama}.php: '{kunci}' tidak pernah dibaca")

check(not cfg_unread, "semua kunci config aplikasi dibaca kode", cfg_unread)

# Variabel .env.example yang tidak dibaca config mana pun.
# Dibaca config bawaan Laravel di vendor/, yang tidak ikut dipindai.
ENV_FRAMEWORK = {
    'APP_TIMEZONE', 'BCRYPT_ROUNDS', 'VITE_APP_NAME', 'APP_MAINTENANCE_DRIVER',
    'APP_FAKER_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_LOCALE',
}

env_unread = []
if os.path.exists('.env.example'):
    cfg_text = ''.join(SRC[f] for f in CONFIGS) + ALL_PHP_TEXT
    for m in re.finditer(r'^([A-Z][A-Z0-9_]+)=', read('.env.example'), re.M):
        v = m.group(1)
        if v in ENV_FRAMEWORK:
            continue
        if f"'{v}'" not in cfg_text and f'"{v}"' not in cfg_text:
            env_unread.append(f".env.example: {v} tidak dibaca env() mana pun")

check(not env_unread, "semua variabel .env.example dibaca kode", env_unread)


# ===================================================================== 4. VIEW
print("\n== VIEW ==")

# View yang tidak pernah dirender dan tidak pernah di-include.
#
# Komponen Blade (resources/views/components) dipanggil lewat tag <x-...>,
# jadi dicocokkan dengan namanya, bukan dengan path titik.
view_unused = []
for f in BLADE:
    rel = f[len('resources/views/'):-len('.blade.php')]

    if rel.startswith('components/'):
        nama = rel[len('components/'):].replace('/', '.')
        tag = '<x-' + nama.replace('.', '.')
        if tag in ALL_TEXT or f'x-{nama}' in ALL_TEXT:
            continue
        # Komponen index: <x-foo> memanggil foo/index.blade.php
        if nama.endswith('.index') and f'x-{nama[:-6]}' in ALL_TEXT:
            continue
        view_unused.append(f"komponen tidak dipakai: {f}")
        continue

    if rel.startswith('layouts/'):
        titik = rel.replace('/', '.')
        if titik in ALL_TEXT:
            continue
        view_unused.append(f"layout tidak dipakai: {f}")
        continue

    titik = rel.replace('/', '.')
    if titik in ALL_TEXT:
        continue

    # Nama view yang dirakit dari potongan, misalnya
    # `'web.pages.admin.crud.'.$this->routeKey().'-form'` atau
    # `view("web.pages.static.{$slug}")`. Yang dicocokkan awalannya, bukan
    # nama utuhnya -- kalau tidak, seluruh form CRUD dan halaman statis
    # dilaporkan tidak terpakai padahal dirender setiap hari.
    awalan = titik.rsplit('.', 1)[0] + '.'
    if awalan in ALL_TEXT:
        continue

    view_unused.append(f"view tidak pernah dirender: {f}")

check(not view_unused, f"{len(BLADE)} view semuanya terpakai", view_unused)


# ================================================================ 5. MIGRATION
print("\n== MIGRATION ==")

# Tabel yang dibuat migration tapi tidak pernah disentuh kode mana pun.
FRAMEWORK_TABLES = {
    'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
    'jobs', 'job_batches', 'failed_jobs', 'migrations', 'personal_access_tokens',
}

tabel_yatim = []
for f in MIGRATIONS:
    src = read(f)
    for m in re.finditer(r"Schema::create\(\s*'(\w+)'", src):
        tabel = m.group(1)
        if tabel in FRAMEWORK_TABLES:
            continue
        # Dicari sebagai string tabel, atau sebagai nama model bentuk tunggal.
        if f"'{tabel}'" in ALL_PHP_TEXT.replace(src, ''):
            continue
        singular = ''.join(w.capitalize() for w in tabel.rstrip('s').split('_'))
        if singular in class_files:
            continue

        # Tabel pivot dipakai belongsToMany TANPA disebut namanya -- Laravel
        # menurunkannya dari kedua nama model, urut abjad. `role_user`
        # otomatis dipakai User::roles() tanpa satu pun string 'role_user'
        # di kode mana pun.
        bagian = tabel.split('_')
        if len(bagian) == 2:
            a = ''.join(w.capitalize() for w in bagian[0].split('-'))
            b = ''.join(w.capitalize() for w in bagian[1].split('-'))
            if a in class_files and b in class_files:
                continue

        tabel_yatim.append(f"tabel `{tabel}` ({os.path.basename(f)}) tidak disentuh kode")

check(not tabel_yatim, "semua tabel yang dibuat migration dipakai kode", tabel_yatim)

# Migration yang menyunting tabel yang tidak pernah dibuat.
dibuat = set()
for f in MIGRATIONS:
    dibuat |= set(re.findall(r"Schema::create\(\s*'(\w+)'", read(f)))

alter_hantu = []
for f in MIGRATIONS:
    for t in re.findall(r"Schema::table\(\s*'(\w+)'", read(f)):
        if t not in dibuat and t not in FRAMEWORK_TABLES:
            alter_hantu.append(f"{os.path.basename(f)} menyunting tabel `{t}` yang tidak pernah dibuat")

check(not alter_hantu, "tidak ada migration yang menyunting tabel yang tidak ada", alter_hantu)


# ============================================================== 6. KODE KEMBAR
print("\n== KODE KEMBAR ==")

# Method dengan isi identik di berkas berbeda. Yang pendek diabaikan:
# getter dan delegasi satu baris memang wajar sama.
bodies = defaultdict(list)
for f in PHP:
    for m in re.finditer(r'function\s+(\w+)\s*\([^)]*\)[^{]*\{', SRC[f]):
        awal = m.end()
        depth, i = 1, awal
        while i < len(SRC[f]) and depth > 0:
            if SRC[f][i] == '{':
                depth += 1
            elif SRC[f][i] == '}':
                depth -= 1
            i += 1
        # Hanya komentar yang dibuang, string TIDAK.
        #
        # Versi pertama memakai code_only() yang mengosongkan string
        # literal, sehingga dua query yang bedanya cuma nama tabel --
        # 'favorites' dan 'watchlists' -- terlihat identik. Empat dari
        # sebelas "duplikat" yang dilaporkannya adalah kekeliruan itu.
        potong = SRC[f][awal:i - 1]
        potong = re.sub(r'/\*.*?\*/', '', potong, flags=re.S)
        potong = re.sub(r'//[^\n]*', '', potong)
        isi = potong
        isi = re.sub(r'\s+', ' ', isi).strip()
        if len(isi) >= 160:
            bodies[isi].append(f"{os.path.basename(f)}::{m.group(1)}()")

kembar = [f"{' == '.join(v)}" for v in bodies.values() if len(set(v)) > 1]
check(not kembar, "tidak ada method dengan isi identik di berkas berbeda", kembar)


# ================================================================ 7. KEAMANAN
print("\n== KEAMANAN ==")

# request->all() yang langsung masuk ke create/update/fill: mass assignment
# yang isinya ditentukan penyerang, bukan oleh kode.
mass = []
for f in PHP:
    body = code_only(SRC[f])
    for m in re.finditer(r'->(?:create|update|fill|forceFill)\(\s*\$request->all\(\)', body):
        mass.append(f"{f}: ->...($request->all())")
check(not mass, "tidak ada mass assignment langsung dari request->all()", mass)

# DB::raw / whereRaw yang menyisipkan variabel: jalan masuk SQL injection.
raw = []
for f in PHP:
    body = code_only(SRC[f])
    for m in re.finditer(r'(?:DB::raw|whereRaw|orderByRaw|havingRaw)\(\s*[^)]*\$', body):
        raw.append(f"{f}: {m.group(0)[:60]}")
check(not raw, "tidak ada SQL mentah yang menyisipkan variabel", raw)

# Rahasia yang dipatok di kode.
secrets = []
for f in PHP + CONFIGS:
    for m in re.finditer(r"['\"](?:sk_live|pk_live|SG\.|AKIA)[A-Za-z0-9_\-]{8,}['\"]", SRC[f]):
        secrets.append(f"{f}: {m.group(0)[:30]}")
    # Token bot Telegram.
    for m in re.finditer(r"['\"]\d{6,12}:[A-Za-z0-9_\-]{30,}['\"]", SRC[f]):
        secrets.append(f"{f}: token bot dipatok di kode")
check(not secrets, "tidak ada rahasia yang dipatok di kode", secrets)

# Kredensial harus terenkripsi di model yang menyimpannya.
enc = []
for model, kolom in [('app/Models/StorageProvider.php', 'credentials'),
                     ('app/Models/PaymentProvider.php', 'credentials')]:
    if os.path.exists(model) and 'encrypted' not in read(model):
        enc.append(f"{model}: kolom {kolom} tidak di-cast encrypted")
check(not enc, "kredensial provider disimpan terenkripsi", enc)

# Route admin harus berada di dalam grup yang memakai middleware admin.
admin_src = ''.join(SRC[f] for f in ROUTES)
check("'admin'" in admin_src and 'permission:' in admin_src,
      "route admin memakai middleware admin dan permission")

# Webhook harus dikecualikan dari CSRF DAN diverifikasi.
boot = read('bootstrap/app.php')
check('validateCsrfTokens' in boot, "ada daftar pengecualian CSRF yang eksplisit")

wh = [f for f in PHP if 'Webhook' in f and 'Middleware' in f]
check(wh, "ada middleware verifikasi webhook")
for f in wh:
    check('hash_equals' in SRC[f],
          f"{os.path.basename(f)} membandingkan rahasia dengan hash_equals")


# ============================================================== 8. KONSISTENSI
print("\n== KONSISTENSI ==")

# Setiap interface repository ter-bind.
prov = read('app/Providers/AppServiceProvider.php')
ifaces = [os.path.basename(f)[:-4] for f in glob.glob('app/Repositories/Contracts/*.php')]
unbound = [i for i in ifaces if f"{i}::class" not in prov]
check(not unbound, f"{len(ifaces)} interface repository ter-bind", unbound)

# Setiap interface service punya implementasi yang ter-bind.
svc_ifaces = [norm(f) for f in glob.glob('app/Services/**/Contracts/*.php', recursive=True)]
svc_unbound = []
for f in svc_ifaces:
    nama = os.path.basename(f)[:-4]
    if f"{nama}::class" in prov:
        continue

    # Interface yang tidak pernah di-type-hint tidak perlu di-bind: yang
    # memakainya menyebut kelas konkretnya lewat registry.
    # PaymentGatewayInterface begitu -- PaymentGatewayManager memanggil
    # app(PaymentDriver::gateway()), jadi container tidak pernah diminta
    # menyelesaikan interface-nya.
    dipakai = any(
        re.search(rf'(?:protected|private|public|\(|,)\s*{nama}\s+\$', s)
        for g, s in SRC.items() if norm(g) != norm(f)
    )
    if dipakai:
        svc_unbound.append(f"{nama} di-type-hint tapi tidak di-bind")

check(not svc_unbound, f"{len(svc_ifaces)} interface service: yang di-type-hint sudah ter-bind", svc_unbound)

# Setiap job punya jalur yang mengantrekannya.
job_yatim = []
for f in glob.glob('app/Jobs/*.php'):
    nama = os.path.basename(f)[:-4]
    lain = ''.join(s for g, s in SRC.items() if norm(g) != norm(f))
    if nama not in lain:
        job_yatim.append(f"{nama} tidak pernah di-dispatch")
check(not job_yatim, "semua job antrean punya yang mengantrekannya", job_yatim)

# Setiap command terdaftar di scheduler atau didokumentasikan.
console = read('routes/console.php')
docs = ''.join(read(f) for f in glob.glob('*.md')) + ''.join(
    read(f) for f in glob.glob('docs/**/*.md', recursive=True))
cmd_yatim = []
for f in glob.glob('app/Console/Commands/*.php'):
    m = re.search(r"protected \$signature = '([\w:-]+)", read(f))
    if not m:
        continue
    sig = m.group(1)
    if sig not in console and sig not in docs:
        cmd_yatim.append(f"`{sig}` tidak dijadwalkan dan tidak didokumentasikan")
check(not cmd_yatim, "semua perintah artisan dijadwalkan atau didokumentasikan", cmd_yatim)


# =============================================================== 9. PRODUCTION
print("\n== KESIAPAN PRODUCTION ==")

for berkas, label in [
    ('DEPLOY.md', 'panduan deploy'),
    ('deploy.sh', 'skrip deploy'),
    ('.env.example', 'contoh environment'),
]:
    check(os.path.exists(berkas), f"ada {label}: {berkas}")

check(os.path.exists('docs'), "ada folder docs/")

for doc in ['docs/INSTALASI.md', 'docs/KONFIGURASI.md', 'docs/ADMIN.md',
            'docs/PENGGUNA.md', 'docs/API.md', 'docs/STORAGE.md',
            'docs/TELEGRAM.md', 'docs/PEMBAYARAN.md', 'docs/ANTREAN.md',
            'docs/CADANGAN.md', 'docs/MASALAH.md', 'docs/PEMELIHARAAN.md',
            'docs/CHANGELOG.md', 'docs/CHECKLIST-PRODUCTION.md']:
    check(os.path.exists(doc), f"ada dokumen: {doc}")

check(os.path.exists('app/Console/Commands/EnvCheck.php'),
      "ada perintah validasi environment")

health = [f for f in PHP if 'Health' in f and 'Controller' in f]
check(health or 'health:' in read('bootstrap/app.php'),
      "ada endpoint health check")

check('SecurityHeaders' in boot, "security header dipasang untuk seluruh permintaan web")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nFINAL AUDIT PHASE 12: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
