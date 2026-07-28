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

defined = set()
# web.php: route di dalam prefix admin. mendapat awalan "admin."
admin_block = routes[routes.find("Route::prefix('admin')"):] if "Route::prefix('admin')" in routes else ''
public_block = routes.replace(admin_block, '')

for m in re.finditer(r"->name\('([^']+)'\)", public_block):
    defined.add(m.group(1))
for m in re.finditer(r"->name\('([^']+)'\)", admin_block):
    n = m.group(1)
    if not n.endswith('.'):
        defined.add('admin.' + n)
defined.discard('admin.')

print("== ROUTE TERDEFINISI (%d) ==" % len(defined))
for r in sorted(defined): print("   ", r)

# ---------- 2. route() yang dipakai di Blade ----------
used = {}
for p in glob.glob('resources/views/**/*.blade.php', recursive=True):
    for m in re.finditer(r"route\(\s*'([^']+)'", open(p, encoding='utf-8').read()):
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
        while i < len(mig) and depth:
            if mig[i] == '{{': depth += 1
            elif mig[i] == '}}': depth -= 1
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
         'WatchHistory':'watch_histories','Subscription':'subscriptions','MembershipPlan':'membership_plans'}

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
    for m in re.finditer(r"(?<![>$])\broute\(\s*'([a-zA-Z0-9_.\-]+)'", src):
        name = m.group(1)
        if name not in defined:
            php_dead.append(f"{name} <- {f}")
check(not php_dead, "semua route() di PHP menunjuk route yang terdefinisi")
for d in php_dead: print("        -", d)


print("\n" + "="*60)
if fail:
    print(f"HASIL: {len(fail)} masalah ditemukan")
    sys.exit(1)
print("HASIL: semua pemeriksaan lolos")
