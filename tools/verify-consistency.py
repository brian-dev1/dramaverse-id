import re, os, glob, sys

fail = []
def check(ok, msg):
    print(("  OK   " if ok else "  GAGAL") + " " + msg)
    if not ok: fail.append(msg)

# ---------- 1. Kumpulkan route yang terdefinisi ----------
routes = open('routes/web.php', encoding='utf-8').read()
api    = open('routes/api.php', encoding='utf-8').read()

def collect(src):
    names, groups = set(), []
    # prefix name() pada group
    for m in re.finditer(r"->name\('([^']+)'\)->group", src):
        groups.append(m.group(1))
    gprefix = groups[0] if groups else ''
    for m in re.finditer(r"->name\('([^']+)'\)", src):
        n = m.group(1)
        if n.endswith('.'):
            continue
        names.add(n)
    return names, gprefix

# Parser nama route: menangani prefix bertingkat seperti
# Route::name('admin.')->group(... Route::name('user.')->group(... ->name('ban')))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from routeparse import extract as extract_route_names

defined = extract_route_names(routes) | extract_route_names(api)

# Route CRUD dibangun dari perulangan array $cruds, bukan ditulis satu per satu.
crud_keys = re.findall(r"'(\w+)'\s*=>\s*Admin\\\w+Controller::class", routes)
crud_actions = ['index', 'create', 'store', 'edit', 'update', 'destroy', 'restore', 'bulk']
for key in crud_keys:
    for act in crud_actions:
        defined.add(f'admin.{key}.{act}')
        defined.discard(f'admin.{act}')

print("== ROUTE TERDEFINISI (%d) ==" % len(defined))
for r in sorted(defined): print("   ", r)

# ---------- 2. route() yang dipakai di Blade ----------
used = {}
for p in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src_v = open(p, encoding='utf-8').read()
    # route('admin.'.$key.'.index') disusun saat runtime -- tidak bisa
    # diperiksa statis, dan sudah dijamin oleh Route::has() di view.
    for m in re.finditer(r"route\(\s*'([^']+)'\s*(\.)?", src_v):
        if m.group(2):
            continue
        used.setdefault(m.group(1), set()).add(p)

print("\n== CEK ROUTE MATI DI BLADE ==")
dead = {r: v for r, v in used.items() if r not in defined}
if dead:
    for r, files in sorted(dead.items()):
        print(f"  GAGAL {r} <- {', '.join(sorted(files))}")
        fail.append(f"route mati: {r}")
else:
    check(True, f"{len(used)} nama route dipakai, semuanya terdefinisi")

# ---------- 3. Tiap route punya controller & method ----------
print("\n== CEK CONTROLLER ADA ==")
missing_ctrl = []
allsrc = routes + api

def ctrl_path(ns, cls):
    return f"app/Http/Controllers/{ns}/{cls}.php"

def has_method(path, want):
    if not os.path.exists(path):
        return None
    src = open(path, encoding='utf-8').read()
    if re.search(rf"function\s+{want}\s*\(", src):
        return True
    parent = re.search(r"extends\s+(\w+)", src)
    if parent:
        base = os.path.dirname(path)
        ppath = os.path.join(base, parent.group(1) + '.php')
        if os.path.exists(ppath) and re.search(rf"function\s+{want}\s*\(", open(ppath, encoding='utf-8').read()):
            return True
    return False

# (a) Route::controller(Ns\\Cls::class)->group(function () { ... })  — hitung kurung agar tidak rakus
for m in re.finditer(r"Route::controller\((\w+)\\(\w+)::class\)[^{]*\{", allsrc):
    ns, cls = m.group(1), m.group(2)
    start = m.end()
    depth, i = 1, start
    while i < len(allsrc) and depth:
        if allsrc[i] == '{': depth += 1
        elif allsrc[i] == '}': depth -= 1
        i += 1
    body = allsrc[start:i-1]

    path = ctrl_path(ns, cls)
    if not os.path.exists(path):
        missing_ctrl.append(path); continue
    for mm in re.finditer(r"Route::\w+\(\s*'[^']*'\s*,\s*'(\w+)'\s*\)", body):
        if has_method(path, mm.group(1)) is not True:
            missing_ctrl.append(f"{path}::{mm.group(1)}()")

# (b) [Ns\Cls::class, 'method']
for m in re.finditer(r"\[\s*(\w+)\\(\w+)::class\s*,\s*'(\w+)'\s*\]", allsrc):
    ns, cls, method = m.groups()
    path = ctrl_path(ns, cls)
    if not os.path.exists(path):
        missing_ctrl.append(path); continue
    if has_method(path, method) is not True:
        missing_ctrl.append(f"{path}::{method}()")

# (c) Invokable: Route::get('...', Ns\Cls::class)  (tanpa ->controller / tanpa array)
for m in re.finditer(r"Route::(?:get|post|put|patch|delete)\(\s*'[^']*'\s*,\s*(\w+)\\(\w+)::class\s*\)", allsrc):
    ns, cls = m.groups()
    path = ctrl_path(ns, cls)
    if not os.path.exists(path):
        missing_ctrl.append(path); continue
    if has_method(path, '__invoke') is not True:
        missing_ctrl.append(f"{path}::__invoke()")

check(not missing_ctrl, "semua controller + method yang dirujuk route tersedia")
for m in missing_ctrl: print("        -", m)

# ---------- 4. Tiap view() yang dirender benar-benar ada ----------
print("\n== CEK VIEW ADA ==")
missing_view = []
for p in glob.glob('app/Http/Controllers/**/*.php', recursive=True):
    for m in re.finditer(r"view\(\s*[\"']([a-z0-9_.\-]+)[\"']", open(p, encoding='utf-8').read()):
        v = m.group(1).replace('.', '/')
        if not os.path.exists(f"resources/views/{v}.blade.php"):
            missing_view.append(f"{v} (dari {p})")
# view dinamis pada PageController
for slug in ['about', 'help', 'privacy', 'terms']:
    if not os.path.exists(f"resources/views/web/pages/static/{slug}.blade.php"):
        missing_view.append(f"web/pages/static/{slug}")
check(not missing_view, "semua view yang dirender controller tersedia")
for m in missing_view: print("        -", m)

# ---------- 5. Tiap <x-...> komponen ada ----------
print("\n== CEK KOMPONEN BLADE ==")
missing_comp = []
for p in glob.glob('resources/views/**/*.blade.php', recursive=True):
    for m in re.finditer(r"<x-([a-z0-9.\-]+)", open(p, encoding='utf-8').read()):
        tag = m.group(1).replace('.', '/')
        if not os.path.exists(f"resources/views/components/{tag}.blade.php"):
            missing_comp.append(f"x-{m.group(1)} (dipakai di {p})")
check(not missing_comp, "semua komponen yang dipanggil tersedia")
for m in missing_comp: print("        -", m)

# ---------- 6. Layout yang di-extend ada ----------
print("\n== CEK LAYOUT ==")
missing_layout = []
for p in glob.glob('resources/views/**/*.blade.php', recursive=True):
    for m in re.finditer(r"@extends\(\s*'([a-z0-9_.\-]+)'", open(p, encoding='utf-8').read()):
        v = m.group(1).replace('.', '/')
        if not os.path.exists(f"resources/views/{v}.blade.php"):
            missing_layout.append(f"{m.group(1)} (dari {p})")
check(not missing_layout, "semua layout yang di-extend tersedia")
for m in missing_layout: print("        -", m)

# ---------- 7. $fillable model vs kolom migration ----------
print("\n== CEK MODEL vs MIGRATION ==")
mig = "\n".join(open(f, encoding='utf-8').read() for f in glob.glob('database/migrations/*.php'))

def cols_of(table):
    cols = set()
    for m in re.finditer(rf"Schema::(?:create|table)\(\s*'{table}'\s*,\s*function[^{{]*\{{", mig):
        start = m.end()
        depth, i = 1, start
        # Perbandingan di sini sebelumnya '{{' dan '}}' -- dua karakter,
        # sedangkan mig[i] selalu satu karakter, sehingga tidak pernah cocok.
        # Akibatnya depth tidak pernah turun, blok membentang sampai akhir
        # berkas, dan pemeriksaan $fillable hanya menanyakan "apakah nama
        # kolom ini muncul di migration mana pun" -- bukan "di tabel ini".
        while i < len(mig) and depth:
            if mig[i] == '{': depth += 1
            elif mig[i] == '}': depth -= 1
            i += 1
        blk = mig[start:i]
        cols |= set(re.findall(r"\$table->\w+\(\s*'([a-z_]+)'", blk))
        cols |= set(re.findall(r"foreignId\('([a-z_]+)'\)", blk))
        if 'timestamps()' in blk: cols |= {'created_at','updated_at'}
        if 'softDeletes()' in blk: cols |= {'deleted_at'}
        if 'rememberToken()' in blk: cols |= {'remember_token'}
    return cols

pairs = {'Drama':'dramas','Episode':'episodes','Genre':'genres','Country':'countries',
         'User':'users','Favorite':'favorites','Watchlist':'watchlists','Banner':'banners',
         'WatchHistory':'watch_histories','Subscription':'subscriptions','MembershipPlan':'membership_plans',
         'Media':'media','Review':'reviews','Setting':'settings','Notification':'notifications',
         'ActivityLog':'activity_logs','Role':'roles','Permission':'permissions',
         'StorageProvider':'storage_providers'}

bad = []
for model, table in pairs.items():
    path = f'app/Models/{model}.php'
    if not os.path.exists(path): continue
    src = open(path, encoding='utf-8').read()
    fm = re.search(r"\$fillable\s*=\s*\[(.*?)\];", src, re.S)
    if not fm: continue
    fill = set(re.findall(r"'([a-z_]+)'", fm.group(1)))
    cols = cols_of(table)
    extra = fill - cols
    if extra:
        bad.append(f"{model}: {sorted(extra)} tidak ada di tabel `{table}`")
check(not bad, "semua $fillable cocok dengan kolom migration")
for b in bad: print("        -", b)

# ---------- 8. FK menunjuk tabel yang sudah dibuat lebih dulu ----------
print("\n== CEK URUTAN FOREIGN KEY ==")
files = sorted(glob.glob('database/migrations/*.php'))
created = []
order_bad = []
for f in files:
    src = open(f, encoding='utf-8').read()
    for t in re.findall(r"Schema::create\('([a-z_]+)'", src):
        created.append(t)
    for t in re.findall(r"constrained\('([a-z_]+)'\)", src):
        if t not in created:
            order_bad.append(f"{os.path.basename(f)} -> `{t}` belum dibuat")
check(not order_bad, "semua foreign key menunjuk tabel yang sudah ada")
for b in order_bad: print("        -", b)

# ---------- 9. Binding repository ----------
print("\n== CEK BINDING REPOSITORY ==")
prov = open('app/Providers/AppServiceProvider.php', encoding='utf-8').read()
ifaces = [os.path.basename(f)[:-4] for f in glob.glob('app/Repositories/Contracts/*.php')]
unbound = [i for i in ifaces if f"{i}::class" not in prov]
check(not unbound, f"{len(ifaces)-len(unbound)}/{len(ifaces)} interface ter-bind")
for u in unbound: print("        - belum di-bind:", u)

# ---------- 10. PSR-4 ----------
print("\n== CEK PSR-4 ==")
psr = []
for f in glob.glob('app/**/*.php', recursive=True):
    src = open(f, encoding='utf-8').read()
    m = re.search(r'^namespace\s+([^;]+);', src, re.M)
    if not m: continue
    expect = 'App\\' + os.path.dirname(f)[4:].replace('/', '\\')
    expect = expect.rstrip('\\')
    if m.group(1) != expect:
        psr.append(f"{f}: namespace {m.group(1)}, seharusnya {expect}")
check(not psr, "namespace semua kelas cocok dengan lokasi berkasnya")
for p in psr: print("        -", p)

# ---------- 11. @import CSS ----------
print("\n== CEK IMPORT CSS ==")
appcss = open('resources/css/app.css', encoding='utf-8').read()
bad_css = []
for m in re.finditer(r'@import\s+"\./([^"]+)"', appcss):
    if not os.path.exists('resources/css/' + m.group(1)):
        bad_css.append(m.group(1))
check(not bad_css, "semua @import di app.css menunjuk berkas yang ada")
for b in bad_css: print("        -", b)

# ---------- 12. Kolom tanggal tanpa nullable/default ----------
print("\n== CEK KOLOM TANGGAL ==")
# MySQL menolak kolom TIMESTAMP NOT NULL tanpa default (error 1067) ketika
# sql_mode memuat NO_ZERO_DATE. Kolom pertama diberi CURRENT_TIMESTAMP secara
# implisit -- termasuk ON UPDATE, yang diam-diam mereset nilainya setiap baris
# diperbarui. Keduanya harus dihindari.
date_bad = []
for f in sorted(glob.glob('database/migrations/*.php')):
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"\$table->(timestamp|dateTime|date|year)\(\s*'([a-z_]+)'\s*\)((?:\s*->\w+\([^)]*\))*)\s*;", src):
        typ, col, chain = m.groups()
        if not any(k in chain for k in ('nullable', 'default', 'useCurrent')):
            date_bad.append(f"{os.path.basename(f)} -> {typ}('{col}') perlu ->nullable() atau ->default()")
check(not date_bad, "semua kolom tanggal punya nullable/default eksplisit")
for b in date_bad: print("        -", b)


# ---------- 13. route() di dalam PHP ----------
print("\n== CEK ROUTE MATI DI PHP ==")
# Pemeriksaan sebelumnya hanya menelusuri Blade. Controller, middleware, dan
# seeder juga memanggil route() -- dan route mati di sana sama fatalnya.
# Pola (?<![>$]) mengecualikan $request->route('x') yang mengambil parameter,
# bukan menghasilkan URL.
php_dead = []
for f in glob.glob('app/**/*.php', recursive=True) + glob.glob('database/**/*.php', recursive=True):
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r"(?<![>$])\broute\(\s*'([a-zA-Z0-9_.\-]+)'\s*(\.)?", src):
        name = m.group(1)
        if m.group(2):
            continue
        if name not in defined:
            php_dead.append(f"{name} <- {f}")
check(not php_dead, "semua route() di PHP menunjuk route yang terdefinisi")
for d in php_dead: print("        -", d)


# ---------- 14. Form: CSRF, method, dan route tujuan ----------
print("\n== CEK FORM ==")
form_bad = []
for f in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src = open(f, encoding='utf-8').read()
    # Ambil tiap <form ...> ... </form>
    for m in re.finditer(r"<form\b([^>]*)>(.*?)</form>", src, re.S | re.I):
        attrs, body = m.group(1), m.group(2)
        method = re.search(r"method=[\"']([a-zA-Z]+)[\"']", attrs)
        verb = (method.group(1) if method else 'GET').upper()

        if verb == 'GET':
            continue  # form GET tidak butuh CSRF

        if '@csrf' not in body:
            form_bad.append(f"{f}: form {verb} tanpa @csrf")

        # PUT/PATCH/DELETE dikirim sebagai POST + @method
        action = re.search(r"action=[\"']\{\{\s*route\(\s*'([^']+)'\s*(\.)?", attrs)
        if action and not action.group(2) and action.group(1) not in defined:
            form_bad.append(f"{f}: action -> route mati {action.group(1)}")

check(not form_bad, "semua form punya @csrf dan action yang valid")
for b in form_bad: print("        -", b)

# ---------- 15. href yang menunjuk ke mana-mana ----------
print("\n== CEK HREF ==")
href_bad = []
for f in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r'href="([^"]*)"', src):
        h = m.group(1).strip()
        if h in ('#', ''):
            href_bad.append(f"{f}: href kosong atau '#'")
        elif h.startswith('{{') and 'route(' not in h and 'url(' not in h and 'asset(' not in h:
            pass  # variabel dinamis, sudah tercakup pemeriksaan lain
check(not href_bad, "tidak ada href kosong atau buntu (#)")
for b in href_bad: print("        -", b)


# ---------- 16. Emoji, simbol teks, dan SVG inline ----------
print("\n== CEK IKON ==")
# Emoji dan karakter simbol dirender berbeda di tiap sistem operasi. Emoji
# bendera bahkan tidak dirender sama sekali di Windows -- yang tampil justru
# dua huruf polos, terlihat seperti kesalahan. Semua ikon harus lewat
# <x-web.home.icon> agar tampil sama di mana pun.
ICON_COMPONENT = 'resources/views/components/web/home/icon.blade.php'
symbol_bad = []
emoji_re = re.compile('[\U0001F300-\U0001FAFF\u2600-\u27BF\u2190-\u21FF\u2713\u2605\u00B7]')

for f in glob.glob('resources/views/**/*.blade.php', recursive=True):
    if f.replace('\\', '/') == ICON_COMPONENT:
        continue
    src = open(f, encoding='utf-8').read()

    if emoji_re.search(src):
        symbol_bad.append(f"{f}: memuat emoji/simbol teks")

    for ent in ('&#9733;', '&rarr;', '&larr;', '&middot;', '&check;'):
        if ent in src:
            symbol_bad.append(f"{f}: memuat entitas {ent}")

    if '<svg' in src:
        symbol_bad.append(f"{f}: SVG inline, seharusnya <x-web.home.icon>")

# CSS tidak boleh memakai content:"karakter simbol"
for f in glob.glob('resources/css/**/*.css', recursive=True):
    for m in re.finditer(r'content:\s*"([^"]+)"', open(f, encoding='utf-8').read()):
        if emoji_re.search(m.group(1)) or '\\27' in m.group(1) or '\\26' in m.group(1):
            symbol_bad.append(f"{f}: content simbol {m.group(1)!r}")

check(not symbol_bad, "tidak ada emoji, simbol teks, atau SVG inline")
for b in symbol_bad: print("        -", b)


# ---------- 17. match() enum tanpa default harus menangani semua case ----------
print("\n== CEK KELENGKAPAN MATCH ENUM ==")
# PHP melempar UnhandledMatchError saat dieksekusi kalau sebuah match tanpa
# arm `default` menerima nilai yang tidak tercantum. Menambah satu case ke
# enum tanpa memperbarui setiap match yang memakainya karena itu menghasilkan
# kesalahan yang tidak terlihat sampai jalur kode itu benar-benar dijalankan --
# dan untuk storage provider, itu bisa berarti baru terlihat di produksi.
match_bad = []

def method_bodies(src):
    """Pasangan (nama metode, isi badan) dengan pencocokan kurung."""
    for m in re.finditer(r"function\s+(\w+)\s*\([^)]*\)[^{;]*\{", src):
        start = m.end()
        depth, i = 1, start
        while i < len(src) and depth:
            if src[i] == '{': depth += 1
            elif src[i] == '}': depth -= 1
            i += 1
        yield m.group(1), src[start:i-1]

for f in sorted(glob.glob('app/Enums/*.php')):
    src = open(f, encoding='utf-8').read()
    cases = re.findall(r"^\s*case\s+(\w+)\s*=", src, re.M)
    if not cases:
        continue
    enum_name = os.path.basename(f)[:-4]

    for method, body in method_bodies(src):
        if 'match' not in body:
            continue
        if re.search(r"\bdefault\s*=>", body):
            continue
        # Arm bisa menggabungkan beberapa case: `self::B2, self::WASABI =>`
        handled = set(re.findall(r"self::(\w+)", body))
        missing = [c for c in cases if c not in handled]
        if missing:
            match_bad.append(
                f"{enum_name}::{method}() tidak menangani: {', '.join(missing)}"
            )

check(not match_bad, "semua match() tanpa default menangani seluruh case enum")
for b in match_bad: print("        -", b)


print("\n" + "="*60)
if fail:
    print(f"HASIL: {len(fail)} masalah ditemukan")
    sys.exit(1)
print("HASIL: semua pemeriksaan lolos")
