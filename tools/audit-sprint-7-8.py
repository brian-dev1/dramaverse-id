"""
Self-audit Sprint 7.8-7.9.

Memeriksa klaim sprint ini terhadap kode yang benar-benar ada, bukan terhadap
apa yang ditulis di dokumen. Komentar dibuang lebih dulu supaya kalimat di
dalam komentar tidak dihitung sebagai kode.
"""
import re, os, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

hasil = []
def cek(ok, label, detail=''):
    hasil.append((bool(ok), label, detail))

def baca(p):
    return open(p, encoding='utf-8').read()

def tanpa_komentar(src):
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    src = re.sub(r'(?m)^\s*//.*$', '', src)
    src = re.sub(r'(?m)\s//[^\n\'"]*$', '', src)
    return src

BARU_CONTROLLER = [
    'app/Http/Controllers/Admin/StorageMonitorController.php',
    'app/Http/Controllers/Admin/FileManagerController.php',
    'app/Http/Controllers/Admin/BatchUploadController.php',
]
BARU_SERVICE = [
    'app/Services/Storage/StorageMonitorService.php',
    'app/Services/Storage/FileManagerService.php',
    'app/Services/Storage/StorageChoiceService.php',
]
BARU_JOB = ['app/Jobs/ProcessDramaAssetUpload.php']

SEMUA_BARU = BARU_CONTROLLER + BARU_SERVICE + BARU_JOB + [
    'app/Http/Requests/Admin/StoreBatchUploadRequest.php',
    'app/Enums/StoredFileSource.php',
]

# --- 1. Berkas yang diklaim dibuat memang ada ---
for p in SEMUA_BARU:
    cek(os.path.exists(p), f'berkas ada: {p}')

for p in ['resources/views/web/pages/admin/storage-monitor.blade.php',
          'resources/views/web/pages/admin/file-manager.blade.php',
          'resources/views/web/pages/admin/batch-upload.blade.php',
          'database/migrations/2026_07_31_120000_add_batch_targets_to_upload_jobs_table.php']:
    cek(os.path.exists(p), f'berkas ada: {p}')

# --- 2. Nol akses storage langsung di controller, service, dan job baru ---
TERLARANG = [
    (r'\bStorage::', 'Storage::'),
    (r'->storeAs\(', '->storeAs()'),
    (r'->store\(\s*[\'"]', "->store('...')"),
    (r'->disk\(', '->disk()'),
    (r'\bfile_put_contents\(', 'file_put_contents()'),
    (r'\bmove_uploaded_file\(', 'move_uploaded_file()'),
]
for p in BARU_CONTROLLER + BARU_SERVICE + BARU_JOB:
    src = tanpa_komentar(baca(p))
    for pola, nama in TERLARANG:
        cek(not re.search(pola, src), f'{os.path.basename(p)}: nol {nama}')

# --- 3. Operasi berkas lewat engine ---
fm = tanpa_komentar(baca('app/Services/Storage/FileManagerService.php'))
for m in ['rename', 'move', 'delete', 'readStream', 'temporaryUrl', 'url']:
    cek(f'$this->storage->{m}(' in fm, f'FileManagerService memakai engine->{m}()')

cek('StorageEngineInterface $storage' in fm,
    'FileManagerService menerima StorageEngineInterface, bukan implementasi konkret')

# --- 4. readStream benar-benar ada di kontrak DAN implementasinya ---
iface = baca('app/Services/Storage/Contracts/StorageEngineInterface.php')
impl  = baca('app/Services/Storage/StorageEngine.php')
cek('public function readStream(' in iface, 'readStream() ada di StorageEngineInterface')
cek('public function readStream(' in impl,  'readStream() ada di StorageEngine')

# Tidak ada method LAMA yang hilang dari implementasi
lama = re.findall(r'public function (\w+)\(', iface)
kurang = [m for m in lama if f'public function {m}(' not in impl]
cek(not kurang, 'semua method kontrak diimplementasikan', ','.join(kurang))

# --- 5. Tidak ada logika unggah ganda ---
uq = tanpa_komentar(baca('app/Services/UploadQueueService.php'))
cek(uq.count('protected function stage(') == 1, 'hanya satu method stage() di UploadQueueService')
cek(uq.count('protected function createJob(') == 1, 'hanya satu createJob() di UploadQueueService')
cek(uq.count("UploadJob::create(") == 1, 'baris upload_jobs dibuat di satu tempat saja')
cek(uq.count('protected function finishSuccess(') == 1, 'satu jalur penyelesaian sukses')

jobs = glob.glob('app/Jobs/Process*Upload.php')
cek(len(jobs) == 2, f'dua job unggah, bukan lebih ({len(jobs)})', ','.join(map(os.path.basename, jobs)))
for j in jobs:
    s = tanpa_komentar(baca(j))
    cek('Storage::' not in s, f'{os.path.basename(j)}: nol Storage::')
    cek('markProcessing(' in s, f'{os.path.basename(j)}: mengambil pekerjaan lewat markProcessing()')

pda = tanpa_komentar(baca('app/Jobs/ProcessDramaAssetUpload.php'))
cek('$assets->upload(' in pda, 'ProcessDramaAssetUpload memanggil DramaAssetService::upload()')
pev = tanpa_komentar(baca('app/Jobs/ProcessEpisodeVideoUpload.php'))
cek('$videos->upload(' in pev, 'ProcessEpisodeVideoUpload masih memanggil EpisodeVideoService::upload()')

# providerOptions/autoTarget tidak lagi ganda
evc = tanpa_komentar(baca('app/Http/Controllers/Admin/EpisodeVideoController.php'))
cek('StorageProvider::query()' not in evc,
    'EpisodeVideoController tidak lagi menyusun daftar provider sendiri')
cek('choices->manualOptions()' in evc, 'EpisodeVideoController memakai StorageChoiceService')

# --- 6. Nol upload langsung dari controller ---
for p in BARU_CONTROLLER:
    src = tanpa_komentar(baca(p))
    cek('EpisodeVideoService' not in src and 'DramaAssetService' not in src,
        f'{os.path.basename(p)}: tidak memanggil service unggah langsung')

buc = tanpa_komentar(baca('app/Http/Controllers/Admin/BatchUploadController.php'))
cek('queue->queueEpisodeVideo(' in buc and 'queue->queueDramaAsset(' in buc,
    'BatchUploadController mengantrekan lewat UploadQueueService')

# --- 7. Migration vs fillable ---
mig = baca('database/migrations/2026_07_31_120000_add_batch_targets_to_upload_jobs_table.php')
model = baca('app/Models/UploadJob.php')
for kolom in ['batch_uuid', 'drama_id', 'asset_type', 'drama_asset_id']:
    cek(f"'{kolom}'" in mig, f'migration menambah kolom {kolom}')
    cek(f"'{kolom}'" in model, f'UploadJob fillable memuat {kolom}')

cek('dropConstrainedForeignId' in mig, 'down() melepas foreign key sebelum kolom dibuang')

# --- 8. Route: nama, controller, method ---
routes = baca('routes/web.php')
harus = {
    'storage-monitor.': ['index', 'refresh', 'test'],
    'files.': ['index', 'show', 'download', 'rename', 'move', 'destroy'],
    'batch.': ['form', 'store', 'status'],
}
peta = {
    'storage-monitor.': 'app/Http/Controllers/Admin/StorageMonitorController.php',
    'files.': 'app/Http/Controllers/Admin/FileManagerController.php',
    'batch.': 'app/Http/Controllers/Admin/BatchUploadController.php',
}
for grup, methods in harus.items():
    cek(f"->name('{grup}')" in routes, f'grup route {grup} terdaftar')
    ctrl = baca(peta[grup])
    for m in methods:
        cek(f"'{m}')->name(" in routes or f", '{m}'" in routes or f"'{m}'" in routes,
            f'route {grup}{m} disebut')
        cek(f'public function {m}(' in ctrl,
            f'{os.path.basename(peta[grup])}::{m}() ada')

# Semua route baru punya middleware permission.
#
# Potongan grup diambil dengan MENCOCOKKAN KURUNG, bukan dengan mengambil
# sekian ribu karakter setelah namanya. Versi pertama audit ini memakai
# jendela tetap 3000 karakter dan melaporkan "9 middleware untuk 17 route"
# pada grup `files.` yang sebenarnya berisi 6 route dengan 6 middleware --
# jendelanya menembus ke grup berikutnya. Kegagalan palsu dari alat audit
# sendiri, persis yang diperingatkan STATUS.md.
def potong_grup(src, nama):
    i = src.find(f"->name('{nama}')")
    if i < 0:
        return ''
    j = src.find('->group(function () {', i)
    if j < 0:
        return ''
    mulai = src.index('{', j)
    depth, k = 0, mulai
    while k < len(src):
        if src[k] == '{':
            depth += 1
        elif src[k] == '}':
            depth -= 1
            if depth == 0:
                break
        k += 1
    return src[mulai:k]

for nama in ['storage-monitor.', 'files.', 'batch.']:
    potongan = potong_grup(routes, nama)
    jml_route = len(re.findall(r"Route::(get|post|put|delete)\(", potongan))
    jml_perm  = len(re.findall(r"middleware\('permission:", potongan))
    cek(jml_route > 0 and jml_route == jml_perm,
        f'{nama}: {jml_perm} middleware permission untuk {jml_route} route')

# --- 9. Logging yang diminta spesifikasi ---
cek("'file.manager.'" in baca('app/Services/Storage/FileManagerService.php'),
    'File Manager menulis ke Laravel Log')
for ev in ['rename', 'move', 'delete']:
    cek(f"'{ev}'" in fm, f'operasi {ev} dicatat')
cek("'upload.queue.'" in tanpa_komentar(baca('app/Services/UploadQueueService.php')),
    'antrean unggah menulis ke Laravel Log')
cek("activity->log(" in tanpa_komentar(baca('app/Http/Controllers/Admin/StorageMonitorController.php')),
    'Test Connection dari monitoring dicatat di activity_logs')
cek("read.success" in impl, 'pembacaan berkas dicatat Storage Engine')

# --- 10. Dead code: import yang tidak dipakai ---
mati = []
for p in SEMUA_BARU:
    src = baca(p)
    body = src.split('*/')[-1] if '*/' in src else src
    for m in re.finditer(r'^use ([\w\\]+);$', src, re.M):
        penuh = m.group(1)
        pendek = penuh.split('\\')[-1]
        sisa = src.replace(m.group(0), '')
        if not re.search(rf'\b{re.escape(pendek)}\b', sisa):
            mati.append(f'{os.path.basename(p)}: use {penuh}')
cek(not mati, 'tidak ada import yang tidak terpakai di berkas baru', '; '.join(mati))

# --- 11. Enum: match lengkap ---
enum = baca('app/Enums/StoredFileSource.php')
cases = re.findall(r'case (\w+) =', enum)
matches = re.findall(r'match \(\$this\) \{(.*?)\};', enum, re.S)
kurang_case = []
for i, blok_m in enumerate(matches):
    for c in cases:
        if f'self::{c}' not in blok_m:
            kurang_case.append(f'match#{i+1} tidak menangani {c}')
cek(not kurang_case, 'semua match() di StoredFileSource menangani seluruh case',
    '; '.join(kurang_case))

# --- 12. View dirender controller, dan tidak ada view yatim ---
for view, ctrl in [
    ('web.pages.admin.storage-monitor', 'app/Http/Controllers/Admin/StorageMonitorController.php'),
    ('web.pages.admin.file-manager',    'app/Http/Controllers/Admin/FileManagerController.php'),
    ('web.pages.admin.batch-upload',    'app/Http/Controllers/Admin/BatchUploadController.php'),
]:
    cek(f"view('{view}'" in baca(ctrl), f'{view} dirender {os.path.basename(ctrl)}')

# --- 13. Menu sidebar menunjuk route yang ada ---
layout = baca('resources/views/web/layouts/admin.blade.php')
for r in ['admin.storage-monitor.index', 'admin.files.index', 'admin.batch.form']:
    cek(f"'{r}'" in layout, f'menu sidebar memuat {r}')

# --- 14. JS: modul baru terdaftar ---
js = baca('resources/js/admin.js')
for f in ['storageMonitor', 'fileManager', 'batchUpload']:
    cek(f'function {f}(' in js, f'modul JS {f}() ada')
    cek(re.search(rf'^\s+{f}\(\);$', js, re.M) is not None, f'modul JS {f}() dipanggil admin()')

# --- 15. Tidak ada URL yang ditulis tangan di JS ---
cek(not re.search(r"['\"]/admin/", js), 'tidak ada path /admin/ yang ditulis tangan di admin.js')

lolos = sum(1 for ok, _, _ in hasil if ok)
print(f'\nSELF-AUDIT SPRINT 7.8-7.9: {lolos}/{len(hasil)} lolos\n')
for ok, label, detail in hasil:
    if not ok:
        print(f'  GAGAL  {label}' + (f'  -> {detail}' if detail else ''))
print()
sys.exit(0 if lolos == len(hasil) else 1)
