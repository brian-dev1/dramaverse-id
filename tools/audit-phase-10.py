#!/usr/bin/env python3
"""
Self-audit Phase 10 - Payment & Membership.

Yang paling ditekankan: **Business Logic Membership tidak boleh tahu provider
mana yang dipakai.** Menambah Stripe harus cukup dengan satu kelas driver dan
satu case enum -- tanpa menyentuh service membership, controller, atau view.

Pemeriksaan kedua yang sama pentingnya: setiap perubahan status pembayaran
lewat SATU jalur. Callback provider, verifikasi terjadwal, dan tombol
verifikasi manual admin ketiganya harus melalui PaymentCallbackService, karena
di situlah idempotensi, pencocokan nominal, dan penjagaan perpindahan status
ditulis -- sekali.

Seperti audit sebelumnya, setiap pemeriksaan yang mencari token kode
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

IFACE = 'app/Services/Payments/Contracts/PaymentGatewayInterface.php'
MANAGER = 'app/Services/Payments/PaymentGatewayManager.php'
CALLBACK = 'app/Services/Payments/PaymentCallbackService.php'
CHECKOUT = 'app/Services/Payments/CheckoutService.php'
INVOICE_SVC = 'app/Services/Payments/InvoiceService.php'
MEMBER = 'app/Services/Membership/MembershipService.php'
DRIVERS = sorted(norm(f) for f in glob.glob('app/Services/Payments/Drivers/*.php'))
CB_CTRL = 'app/Http/Controllers/PaymentCallbackController.php'
INV_CTRL = 'app/Http/Controllers/Admin/InvoiceController.php'
PROV_CTRL = 'app/Http/Controllers/Admin/PaymentProviderController.php'
ACCESS = 'app/Repositories/EpisodeAccessRepository.php'

# ------------------------------------------------------------------ 1. berkas
print("\n== BERKAS ==")

for f in [IFACE, MANAGER, CALLBACK, CHECKOUT, INVOICE_SVC, MEMBER, CB_CTRL,
          INV_CTRL, PROV_CTRL,
          'app/Enums/PaymentDriver.php', 'app/Enums/PaymentStatus.php',
          'app/Enums/SubscriptionStatus.php', 'app/Enums/RefundStatus.php',
          'app/Models/PaymentProvider.php', 'app/Models/Invoice.php',
          'app/Models/PaymentTransaction.php',
          'app/Services/Payments/Exceptions/PaymentException.php',
          'app/Jobs/VerifyPaymentTransaction.php',
          'app/Console/Commands/PaymentAutomation.php',
          'config/payment.php',
          'database/seeders/PaymentProviderSeeder.php']:
    check(os.path.exists(f), f"ada: {f}")

for f in ['app/Services/PaymentService.php', 'app/Services/MembershipService.php',
          'app/Enums/PaymentGateway.php']:
    check(not os.path.exists(f), f"kelas lama dihapus: {f}")

for nama in ['payment_providers', 'invoices', 'payment_transactions',
             'billing_to_subscriptions', 'premium_columns_to_users']:
    check(len(glob.glob(f'database/migrations/*{nama}*.php')) == 1,
          f"migration {nama} ada")


# --------------------------------------------------- 2. provider tidak dipatok
print("\n== PROVIDER TIDAK DIPATOK ==")

iface = read(IFACE)

for m in ['charge', 'verify', 'parseCallback', 'cancel', 'refund']:
    check(f'function {m}(' in iface, f"kontrak punya {m}()")

# Setiap method HARUS menerima PaymentProvider -- itu yang memungkinkan satu
# driver dipasang dua kali dengan kredensial berbeda.
check(iface.count('PaymentProvider $provider') >= 5,
      "setiap method kontrak menerima PaymentProvider, bukan membaca config")

# Nama kelas gateway hanya boleh disebut di enum dan manager.
bad = []
for f in APP:
    if f in (MANAGER, 'app/Enums/PaymentDriver.php') or f.startswith('app/Services/Payments/Drivers/'):
        continue
    if re.search(r'\b(Midtrans|Xendit|Tripay|Trakteer|ManualTransfer)Gateway\b', code_only(read(f))):
        bad.append(f)
check(not bad, "nama kelas gateway hanya disebut di PaymentDriver dan PaymentGatewayManager")
for b in bad:
    print("        -", b)

# Business Logic Membership tidak boleh menyebut nama provider mana pun.
member = read(MEMBER)
check(not re.search(r'midtrans|xendit|tripay|trakteer', code_only(member), re.I),
      "MembershipService tidak menyebut satu pun nama provider")
check('PaymentGateway' not in code_only(member),
      "MembershipService tidak menyentuh lapisan gateway sama sekali")

# Driver baru cukup mengubah enum.
enum = read('app/Enums/PaymentDriver.php')
check('function gateway(' in enum, "enum memetakan driver ke kelas gateway")
check('function isImplemented(' in enum, "driver yang belum selesai ditandai")
check('function requiredFields(' in enum,
      "field kredensial diturunkan dari enum, bukan ditulis di controller")
prov_ctrl = read(PROV_CTRL)
check('requiredFields()' in prov_ctrl,
      "form admin membaca field dari enum -- menambah driver tidak menyentuh controller")

for f in DRIVERS:
    if 'AbstractGateway' in f:
        continue
    src = read(f)
    check('extends AbstractGateway' in src,
          f"{os.path.basename(f)} memakai induk yang sama")


# ----------------------------------------------------- 3. satu jalur perubahan
print("\n== SATU JALUR PERUBAHAN STATUS ==")

cb = read(CALLBACK)
check('lockForUpdate()' in cb, "baris transaksi dikunci sebelum diubah")
check('DB::transaction' in cb, "perubahan status dibungkus transaction")
check('canTransitionTo' in cb, "perpindahan status dijaga")
check('callback.duplicate' in cb, "callback ganda dikenali dan dijawab tanpa mengerjakan ulang")
check('amountMismatch' in cb, "nominal dicocokkan sebelum diaktifkan")
check('activateFromInvoice' in cb, "aktivasi diserahkan ke MembershipService")

# Driver BOLEH melaporkan lunas -- itu memang tugasnya membaca jawaban
# provider. Yang tidak boleh: driver menulis keadaan itu ke basis data.
# Versi pertama pemeriksaan ini melarang konstanta PaymentStatus::PAID muncul
# di driver sama sekali, dan menghasilkan GAGAL palsu pada TrakteerGateway
# yang justru bekerja dengan benar.
bad = []
for f in DRIVERS:
    src = code_only(read(f))
    if re.search(r'->(save|update|forceFill|delete)\(', src):
        bad.append(f)
check(not bad, "driver tidak menulis apa pun ke basis data -- hanya melaporkan")
for b in bad:
    print("        -", b)

# Penulisan status lunas ke basis data hanya di dua tempat.
bad = []
for f in APP:
    if f in (CALLBACK, INVOICE_SVC) or f.startswith('app/Services/Payments/Drivers/'):
        continue
    src = code_only(read(f))
    # Hanya PENULISAN yang dicari, yaitu bentuk `'status' => PaymentStatus::PAID`.
    #
    # Versi sebelumnya juga menandai setiap kemunculan `PaymentStatus::PAID->value`
    # apa pun konteksnya, sehingga `where('status', PaymentStatus::PAID->value)`
    # -- pembacaan biasa -- ikut dituduh menulis. Lapisan analitik Phase 11
    # membaca invoice lunas di beberapa tempat dan langsung memicu GAGAL palsu.
    if re.search(r"'status'\s*=>\s*PaymentStatus::PAID", src):
        bad.append(f)
check(not bad, "hanya PaymentCallbackService dan InvoiceService yang MENULIS status lunas")
for b in bad:
    print("        -", b)

inv_ctrl = read(INV_CTRL)
check('callbacks->apply(' in inv_ctrl,
      "verifikasi manual admin lewat PaymentCallbackService, bukan mengubah status sendiri")
check('activateFromInvoice' not in code_only(inv_ctrl),
      "controller admin tidak mengaktifkan membership sendiri")

job = read('app/Jobs/VerifyPaymentTransaction.php')
check('callbacks->apply(' in job or 'apply(' in job,
      "verifikasi terjadwal lewat jalur yang sama")
check('increment(' in job,
      "percobaan verifikasi dinaikkan supaya transaksi mati tidak ditanyakan selamanya")


# ------------------------------------------------------------- 4. idempotensi
print("\n== IDEMPOTENSI & DUPLIKASI ==")

mig_tx = glob.glob('database/migrations/*payment_transactions*.php')
if mig_tx:
    m = read(mig_tx[0])
    check("unique('reference')" in m or "->unique()" in m,
          "referensi kita unik")
    check("['payment_provider_id', 'external_id']" in m,
          "pasangan provider + id provider unik -- callback ganda tidak melahirkan dua baris")

mig_inv = glob.glob('database/migrations/*create_invoices_table*.php')
if mig_inv:
    check("->unique()" in read(mig_inv[0]), "nomor invoice unik")

check('already_active' in member,
      "aktivasi membership idempoten -- callback ganda tidak melipatgandakan masa aktif")


# -------------------------------------------------------------- 5. keamanan
print("\n== KEAMANAN ==")

check('hash_equals' in read('app/Services/Payments/Drivers/AbstractGateway.php'),
      "tanda tangan dibandingkan dengan waktu tetap, bukan ===")

trakteer = read('app/Services/Payments/Drivers/TrakteerGateway.php')
check('signatureMatches' in trakteer, "driver Trakteer memverifikasi tanda tangan")
check('invalidSignature' in trakteer, "tanda tangan salah DILEMPAR, bukan dikembalikan")

manual = read('app/Services/Payments/Drivers/ManualTransferGateway.php')
check('invalidSignature' in manual,
      "driver manual menolak callback -- tidak ada jalan mengaktifkan tanpa membayar")

check("'throttle:payment-callback'" in read('routes/web.php'),
      "endpoint callback dibatasi lajunya")
check("payment/callback" in read('bootstrap/app.php') or 'payment/callback' in read('routes/web.php'),
      "route callback terdaftar")

prov_model = read('app/Models/PaymentProvider.php')
check("'encrypted:array'" in prov_model, "kredensial gateway terenkripsi di basis data")

check('filled($nilai)' in prov_ctrl,
      "field kredensial kosong tidak menimpa yang tersimpan")

check('max_pending_invoices' in read('app/Http/Controllers/Web/CheckoutController.php'),
      "jumlah tagihan menggantung per pengguna dibatasi")

check('NotFoundHttpException' in read('app/Http/Controllers/Web/CheckoutController.php'),
      "tagihan milik orang lain dijawab 404, bukan 403")

check(not re.search(r"orderBy\(\s*\$request", code_only(inv_ctrl)),
      "nama kolom dari query string tidak langsung masuk orderBy")
check('SORTABLE' in inv_ctrl, "kolom urut memakai daftar tertutup")


# ------------------------------------------------------- 6. urutan checkout
print("\n== URUTAN CHECKOUT ==")

co = read(CHECKOUT)
check(co.index('PaymentTransaction::create') < co.index('->charge('),
      "transaksi tersimpan SEBELUM gateway dipanggil")
check('DB::transaction' in co, "invoice, langganan, dan transaksi jadi bersama-sama")
check(co.index('DB::transaction') < co.index('->charge('),
      "panggilan gateway berada DI LUAR transaction database")
check('SubscriptionStatus::PENDING' in co,
      "langganan dibuat pending supaya terlihat di riwayat pengguna")


# --------------------------------------------------------- 7. membership
print("\n== MEMBERSHIP ==")

for m in ['activateFromInvoice', 'grant', 'cancel', 'cancelPendingFor',
          'expireDue', 'status', 'history', 'plans', 'active']:
    check(f'function {m}(' in member, f"MembershipService punya {m}()")

check('startFrom' in member, "perpanjangan menumpuk pada sisa yang berjalan")
check('syncUserFlags' in member,
      "kolom ringkas di users disamakan setiap kali langganan berubah")
check('repository->' in member, "MembershipService memakai repository")

repo = read('app/Repositories/MembershipRepository.php')
check('subscribe' not in code_only(repo),
      "aturan bisnis subscribe() sudah tidak ada di repository")

access = read(ACCESS)
check('is_vip' in access, "hak menonton memakai kolom is_vip yang memang ada")
check('access_type' not in code_only(access),
      "kolom access_type yang tidak pernah ada sudah tidak dibaca")
check('premium_expired_at' in access, "masa berlaku premium diperiksa")

mig_user = glob.glob('database/migrations/*premium_columns_to_users*.php')
if mig_user:
    mu = read(mig_user[0])
    check("'is_premium'" in mu and "'premium_expired_at'" in mu,
          "kolom premium yang selama ini dibaca kode akhirnya dibuat")


# ------------------------------------------------------------- 8. otomatisasi
print("\n== OTOMATISASI ==")

auto = read('app/Console/Commands/PaymentAutomation.php')
check('function verify(' in auto, "perintah verifikasi tertunda ada")
check('function expire(' in auto, "perintah kedaluwarsa ada")
check('max_attempts' in auto, "batas percobaan verifikasi ditegakkan")
check("'manual'" in auto,
      "provider manual dilewati -- tidak ada yang bisa ditanyakan ke sana")
check('schedulerError' in auto, "kegagalan terjadwal mengirim peringatan")

console = read('routes/console.php')
check('payment:auto verify' in console, "scheduler menjalankan verifikasi")
check('payment:auto expire' in console, "scheduler menjalankan kedaluwarsa")
check(console.count('withoutOverlapping()') >= 5, "setiap jadwal withoutOverlapping")


# ------------------------------------------------------- 9. logging & alert
print("\n== LOGGING & PERINGATAN ==")

for peristiwa, berkas in [
    ('invoice.created', INVOICE_SVC), ('invoice.paid', INVOICE_SVC),
    ('invoice.cancelled', INVOICE_SVC), ('invoice.expired', INVOICE_SVC),
    ('checkout.started', CHECKOUT), ('checkout.failed', CHECKOUT),
    ('callback.received', CALLBACK), ('callback.applied', CALLBACK),
    ('callback.duplicate', CALLBACK), ('callback.illegal_transition', CALLBACK),
    ('callback.amount_mismatch', CALLBACK),
    ('membership.activated', MEMBER), ('membership.expired', MEMBER),
    ('membership.granted', MEMBER), ('membership.cancelled', MEMBER),
]:
    check(peristiwa in read(berkas), f"log peristiwa {peristiwa}")

alert = read('app/Services/Payments/PaymentAlertService.php')
for m in ['amountMismatch', 'invalidSignature', 'unknownReference',
          'gatewayFailed', 'manualActivation']:
    check(f'function {m}(' in alert, f"peringatan {m} ada")
check('AlertService' in alert,
      "penahan peringatan dipakai ulang dari Phase 9, bukan disalin")


# ------------------------------------------------------------ 10. tetap bersih
print("\n== TETAP BERSIH ==")

# Kelas baru benar-benar dipakai.
for kelas in ['PaymentGatewayManager', 'CheckoutService', 'InvoiceService',
              'PaymentCallbackService', 'PaymentAlertService',
              'VerifyPaymentTransaction', 'PaymentDriver', 'RefundStatus',
              'SubscriptionStatus']:
    check(SEMUA.count(kelas) >= 2, f"{kelas} dipakai, bukan kelas yatim")

routes = read('routes/web.php')
for nama in ['payment.callback', 'web.checkout', 'web.invoice.show',
             'admin.invoice.index', 'admin.payment-provider.index',
             'admin.payment-log.index']:
    short = nama.replace('admin.', '').replace('web.', '')
    check(f"'{short}'" in routes or f"'{nama}'" in routes,
          f"route {nama} terdefinisi")

sidebar = read('resources/views/web/layouts/admin.blade.php')
for r in ['admin.invoice.index', 'admin.payment-provider.index', 'admin.payment-log.index']:
    check(r in sidebar, f"{r} punya menu sidebar")

# ActivityLogger menerima Model, bukan int.
bad = []
for f in glob.glob('app/Http/Controllers/**/*.php', recursive=True):
    for m in re.finditer(r"ActivityLogger::class\)->log\([^;]*?\)\s*;", read(f), re.S):
        if re.search(r",\s*\$\w+->id\s*[,)]", m.group(0)):
            bad.append(f"{f}: {m.group(0)[:70]}")
check(not bad, "ActivityLogger tidak pernah dipanggil dengan id")
for b in bad:
    print("        -", b)

# Http:: hanya di driver dan client Telegram.
bad = []
for f in APP:
    if f == 'app/Services/Telegram/TelegramClient.php':
        continue
    if f.startswith('app/Services/Payments/Drivers/'):
        continue
    if 'Http::' in code_only(read(f)):
        bad.append(f)
check(not bad, "Http:: hanya ada di TelegramClient dan driver pembayaran")
for b in bad:
    print("        -", b)


# --------------------------------------------------------------- 11. config
print("\n== KONFIGURASI ==")

cfg = read('config/payment.php')
env = read('.env.example')

for kunci, envkey in [
    ('currency', 'PAYMENT_CURRENCY'),
    ('invoice_ttl', 'PAYMENT_INVOICE_TTL'),
    ('verify.max_attempts', 'PAYMENT_VERIFY_MAX_ATTEMPTS'),
    ('verify.batch', 'PAYMENT_VERIFY_BATCH'),
    ('verify.queue', 'PAYMENT_QUEUE'),
    ('membership_cache_ttl', 'PAYMENT_MEMBERSHIP_CACHE_TTL'),
    ('guard.max_pending_invoices', 'PAYMENT_MAX_PENDING'),
    ('guard.callback_rate', 'PAYMENT_CALLBACK_RATE'),
    ('logging.enabled', 'PAYMENT_LOGGING'),
]:
    check(envkey in env, f".env.example punya {envkey}")
    check(f"payment.{kunci}" in SEMUA, f"config payment.{kunci} benar-benar dibaca kode")

check('$angka' in cfg and '$boolean' in cfg,
      "nilai .env kosong tidak jatuh ke nol")


print()
for p in PASSED:
    print("  OK    ", p)

print(f"\nSELF-AUDIT PHASE 10: {len(PASSED)}/{len(PASSED) + len(FAILED)} lolos")

sys.exit(1 if FAILED else 0)
