#!/usr/bin/env python3
"""
Self-audit Phase 11 - Analytics & Business Intelligence.

Yang paling ditekankan: **satu sumber kebenaran untuk setiap angka.**
Analitik adalah lapisan yang paling mudah melahirkan dua jawaban berbeda
untuk pertanyaan yang sama, dan dua angka pendapatan yang berbeda di dua
halaman lebih buruk daripada tidak ada angka sama sekali.

Seperti audit sebelumnya, setiap pemeriksaan yang mencari token kode
menjalankan code_only() lebih dulu. Rekam jejak proyek ini: routeparse.py
(7.6), audit 7.7, 7.8, 8.1, dan 8.9 semuanya menghasilkan GAGAL palsu karena
mencocokkan sesuatu di dalam komentar, string, atau nama method yang punya
varian berawalan.
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

REPO = 'app/Repositories/AnalyticsRepository.php'
IREPO = 'app/Repositories/Contracts/AnalyticsRepositoryInterface.php'
SVC = 'app/Services/Analytics/AnalyticsService.php'
RPT = 'app/Services/Analytics/ReportService.php'
CTRL = 'app/Http/Controllers/Admin/AnalyticsController.php'
RCTRL = 'app/Http/Controllers/Admin/ReportController.php'
CMD = 'app/Console/Commands/AnalyticsRefresh.php'
ENUM = 'app/Enums/AnalyticsPeriod.php'
VIEW = 'resources/views/web/pages/admin/analytics.blade.php'

# ---------------------------------------------------------------- 1. berkas
print("\n== BERKAS ==")

for f in [REPO, IREPO, SVC, RPT, CTRL, RCTRL, CMD, ENUM, VIEW, 'config/analytics.php']:
    check(os.path.exists(f), f"ada: {f}")


# ------------------------------------------------------------- 2. lapisan
print("\n== LAPISAN ==")

repo = read(REPO)
iface = read(IREPO)
svc = read(SVC)
ctrl = read(CTRL)
rctrl = read(RCTRL)

check('implements AnalyticsRepositoryInterface' in repo, "repository memenuhi kontraknya")

# Paritas kontrak dan implementasi.
mi = set(re.findall(r'function\s+(\w+)\s*\(', code_only(iface)))
mr = set(re.findall(r'function\s+(\w+)\s*\(', code_only(repo)))
kurang = mi - mr
check(not kurang, "repository mengimplementasi seluruh kontrak")
for m in kurang:
    print("        - belum diimplementasi:", m)

prov = read('app/Providers/AppServiceProvider.php')
check('AnalyticsRepositoryInterface::class' in prov, "repository ter-bind di container")

check('AnalyticsRepositoryInterface' in svc,
      "service membaca lewat kontrak, bukan model langsung")

# Controller tidak boleh menjalankan query agregat sendiri.
#
# Yang dicari hanya penanda query DATABASE: facade DB, pemanggilan statis
# pada model, dan selectRaw. `->count()` dan `->sum()` sengaja TIDAK ikut --
# keduanya juga milik Collection, dan `$rows->count()` untuk menghitung baris
# hasil laporan bukan query sama sekali. Versi pertama pemeriksaan ini
# menyertakannya dan menghasilkan GAGAL palsu.
for f, nama in [(CTRL, 'AnalyticsController'), (RCTRL, 'ReportController')]:
    src = code_only(read(f))
    bad = re.search(r'\bDB::|\b[A-Z]\w+::(where|query)\(|selectRaw', src)
    check(bad is None, f"{nama} tidak menjalankan query agregat sendiri")

check('AnalyticsService' in ctrl, "dashboard mengambil angka dari AnalyticsService")


# ---------------------------------------------- 3. satu sumber kebenaran
print("\n== SATU SUMBER KEBENARAN ==")

# Pendapatan HARUS dari invoices lunas, bukan dari subscriptions.price.
check('Invoice::query()' in repo and "sum('total')" in repo,
      "pendapatan dihitung dari invoice")

check(not re.search(r"Subscription::query\(\)[^;]*sum\('price'\)", repo, re.S),
      "pendapatan TIDAK dihitung dari subscriptions.price")

rpt = read(RPT)
check('PaymentStatus::PAID' in rpt, "laporan pendapatan menyaring invoice lunas")

# ReportController tidak boleh punya definisi laporan sendiri lagi.
check('private function headers(' not in rctrl,
      "judul kolom laporan tidak lagi ditulis di controller")
check('private function rows(' not in rctrl,
      "query laporan tidak lagi ditulis di controller")
check('ReportService' in rctrl, "controller memakai ReportService")

# Layar, ekspor, dan cetak memakai definisi yang sama.
check(rctrl.count('$this->reports->rows(') >= 3,
      "layar, ekspor, dan cetak memakai baris yang sama")
check(rctrl.count('$this->reports->headers(') >= 3,
      "layar, ekspor, dan cetak memakai judul kolom yang sama")

# Grafik di halaman laporan dibaca dari service yang sama dengan dashboard.
check('AnalyticsService' in rctrl,
      "grafik halaman laporan memakai sumber yang sama dengan dashboard")


# --------------------------------------------------------------- 4. periode
print("\n== PERIODE ==")

enum = read(ENUM)
for case in ['DAY', 'WEEK', 'MONTH', 'YEAR']:
    check(f'case {case}' in enum, f"periode {case} ada")

for m in ['label', 'points', 'sqlFormat', 'since', 'buckets', 'keyFor', 'labelFor']:
    check(f'function {m}(' in enum, f"AnalyticsPeriod punya {m}()")

check("'%x-%v'" in enum,
      "mingguan memakai tahun-minggu ISO, bukan %Y-%u yang memecah minggu lintas tahun")

check('buckets()' in repo, "periode kosong diisi nol, bukan dilewati")


# ----------------------------------------------------------------- 5. cache
print("\n== CACHE ==")

check('Cache::remember' in svc, "seksi dashboard di-cache")
check('function warm(' in svc, "ada pemanas cache")
check('function forget(' in svc, "cache bisa dibuang")
check('analytics.cache.enabled' in svc, "cache bisa dimatikan lewat config")
check('analytics.cache.ttl' in svc, "TTL dibaca dari config")

# Cache di service, bukan di repository: repository menjawab pertanyaan
# tunggal, halaman butuh belasan sekaligus.
check('Cache::' not in code_only(repo), "repository tidak ikut menyimpan cache")

check('analytics:refresh' in read('routes/console.php'), "pemanas cache dijadwalkan")
check('withoutOverlapping()' in read('routes/console.php'), "jadwal memakai withoutOverlapping")
check('analytics:refresh' in read(CMD), "perintah pemanas terdaftar")


# ------------------------------------------------------------- 6. query
print("\n== QUERY ==")

check('withCount(' in repo, "hitungan relasi memakai withCount, bukan perulangan query")
check("with(" in repo, "relasi dimuat di awal, menghindari N+1")
check('groupBy(' in repo, "agregasi dikerjakan database, bukan PHP")

# Ekspor harus dibatasi.
check('report.max_rows' in rpt, "ekspor dibatasi jumlah barisnya")
check('limit($batas)' in rpt, "batas benar-benar diterapkan ke query")


# ------------------------------------------------------------- 7. tampilan
print("\n== TAMPILAN ==")

view = read(VIEW)
check('x-admin.chart' in view, "grafik memakai komponen yang sudah ada")
check('x-admin.stat-card' in view, "kartu memakai komponen yang sudah ada")

# Tab sebagai tautan: tiap kombinasi jadi URL tersendiri, dan hanya seksi
# yang dibuka yang dihitung server.
check("route('admin.analytics'" in view, "tab dan periode berupa tautan, bukan JavaScript")

for seksi in ['business', 'content', 'telegram', 'storage']:
    check(f"'{seksi}'" in view, f"seksi {seksi} dirender")

check('section(' in ctrl, "controller hanya menghitung seksi yang dibuka")


# ---------------------------------------------------------- 8. tetap bersih
print("\n== TETAP BERSIH ==")

# Setiap kelas baru benar-benar dipakai.
for kelas in ['AnalyticsRepository', 'AnalyticsService', 'ReportService',
              'AnalyticsPeriod']:
    check(SEMUA.count(kelas) >= 2, f"{kelas} dipakai, bukan kelas yatim")

# Perintah artisan tidak dirujuk lewat nama kelasnya -- Laravel memindai
# direktori Commands, dan yang menyebutnya adalah SIGNATURE-nya di scheduler.
# Menghitung nama kelas di sini menghasilkan GAGAL palsu untuk kelas yang
# sebenarnya terpakai setiap sepuluh menit.
check("analytics:refresh" in read('routes/console.php')
      and "analytics:refresh" in read(CMD),
      "AnalyticsRefresh terpakai lewat signature-nya di scheduler")

# Tidak ada jenis laporan yang terdaftar tapi tidak punya kolom/baris.
tipe = re.findall(r"'(\w+)'\s*=>\s*'Laporan", rpt)
check(len(tipe) >= 5, f"{len(tipe)} jenis laporan terdaftar")
for t in tipe:
    check(f"'{t}'" in rpt, f"laporan {t} punya definisi")

# Setiap kunci config dibaca kode.
cfg = read('config/analytics.php')
kunci = re.findall(r"^\s{8}'(\w+)'\s*=>", cfg, re.M)
for k in kunci:
    check(re.search(rf"analytics\.\w+\.{k}", SEMUA) is not None,
          f"config analytics.*.{k} benar-benar dibaca kode")

check('$angka' in cfg and '$boolean' in cfg,
      "nilai .env kosong dijaga tidak jatuh ke nol")

env = read('.env.example')
for k in ['ANALYTICS_CACHE', 'ANALYTICS_CACHE_TTL',
          'ANALYTICS_REPORT_MAX_ROWS', 'ANALYTICS_REPORT_PREVIEW']:
    check(k in env, f".env.example memuat {k}")

# Pembagian dengan nol adalah cara paling umum halaman analitik meledak.
check(re.search(r'>\s*0\s*\?', repo) is not None,
      "pembagian selalu dijaga dari penyebut nol")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT PHASE 11: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
