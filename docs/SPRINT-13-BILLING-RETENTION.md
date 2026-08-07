# Sprint 13 — Tagihan lengkap, VIP berakhir otomatis, penarikan video

## Yang berubah

**1. Format waktu di seluruh tagihan**

`app/Support/Waktu.php` jadi satu-satunya tempat memformat waktu. Sebelumnya ada
tiga format berbeda dan jam sering hilang — "Bayar sebelum 7 Agustus" tidak
menjawab "jam berapa".

Sekarang: `Jumat, 07 Agustus 2026 pukul 21.30 WIB (2 hari 4 jam lagi)`

Database tetap UTC. Zona tampilan dari `APP_DISPLAY_TIMEZONE` (default
`Asia/Jakarta`). **Jangan** ubah `config('app.timezone')` — seluruh `expired_at`
yang sudah tersimpan akan bergeser 7 jam maknanya.

Macro Carbon tersedia di blade: `->lengkap()`, `->ringkas()`, `->presisi()`,
`->relatif()`, `->lengkapRelatif()`.

**2. VIP berakhir → otomatis**

`membership:auto sweep` tiap 10 menit menjalankan tiga hal berurutan:

| Urutan | Aksi | Efek |
|---|---|---|
| 1 | `expire` | status jadi EXPIRED, `users.is_premium` false → **fitur VIP terkunci** |
| 2 | `notify` | pesan Telegram "paket sudah berakhir" + tombol perpanjang, **sekali saja** |
| 3 | `purge`  | tarik video premium yang masih bisa ditarik |

Urutannya tidak boleh ditukar. Terbalik = ada jendela waktu di mana user sudah
dapat pesan "akses dicabut" tapi masih bisa buka episode VIP.

**3. Penarikan video — batas yang harus dipahami**

Bot Telegram hanya bisa menghapus pesannya sendiri, **dan hanya bila usianya
< 48 jam**. Itu batas Telegram, tidak bisa diakali dengan cara apa pun.

Karena itu yang menentukan keberhasilan bukan tombol tarik, tapi
`TELEGRAM_VIDEO_TTL_HOURS`. Dengan nilai 24, hampir semua video premium sudah
hilang sendiri sebelum sempat jadi terlalu tua.

Video yang lewat 48 jam ditandai `too_old` di panel admin — apa adanya, bukan
di-retry diam-diam.

Panel: **Admin → Penarikan Video** (`/admin/telegram/retention`)

---

## File baru

```
app/Support/Waktu.php
app/Models/TelegramDelivery.php
app/Services/Telegram/TelegramRetentionService.php
app/Services/Membership/MembershipExpiryNotifier.php
app/Console/Commands/MembershipAutomation.php
app/Http/Controllers/Admin/TelegramRetentionController.php
resources/views/web/pages/admin/telegram-retention.blade.php
database/migrations/2026_08_07_210000_create_telegram_deliveries_table.php
database/migrations/2026_08_07_210100_add_expiry_notice_to_subscriptions_table.php
```

## File disunting

```
app/Providers/AppServiceProvider.php      (locale id + macro Carbon)
app/Models/Subscription.php               (expiry_notified_at)
app/Services/Telegram/TelegramDeliveryService.php  (catat message_id)
app/Telegram/Handlers/PremiumHandler.php  (format waktu)
app/Telegram/Handlers/ProfileHandler.php  (format waktu)
config/app.php                            (display_timezone)
config/telegram.php                       (retention)
routes/console.php                        (jadwal membership:auto)
routes/web.php                            (rute panel)
resources/views/web/layouts/admin.blade.php
resources/views/web/pages/invoice.blade.php
resources/views/web/pages/membership.blade.php
resources/views/web/pages/admin/invoice.blade.php
resources/views/web/pages/admin/invoice-detail.blade.php
```

---

## ENV baru

Tambahkan ke `.env` di VPS:

```env
APP_DISPLAY_TIMEZONE=Asia/Jakarta

TELEGRAM_PURGE_ON_EXPIRE=true
TELEGRAM_VIDEO_TTL_HOURS=24
TELEGRAM_PURGE_BATCH=200
```

`TELEGRAM_VIDEO_TTL_HOURS=0` mematikan masa hidup video. Boleh, tapi berarti
sebagian besar video akan lewat 48 jam sebelum VIP-nya berakhir dan tidak bisa
ditarik lagi.

---

## Perintah

### A. Di VS Code PowerShell (lokal — cek dulu sebelum push)

```powershell
cd C:\ProjectDrama\dramaverse-id

# 1. Cek sintaks semua file yang berubah
php -l app\Support\Waktu.php
php -l app\Models\TelegramDelivery.php
php -l app\Services\Telegram\TelegramRetentionService.php
php -l app\Services\Membership\MembershipExpiryNotifier.php
php -l app\Console\Commands\MembershipAutomation.php
php -l app\Http\Controllers\Admin\TelegramRetentionController.php

# 2. Pastikan blade tidak ada yang rusak
php artisan view:clear
php artisan view:cache

# 3. Pastikan perintah barunya terdaftar
php artisan list membership

# 4. Push
git add -A
git commit -m "feat(billing): waktu lengkap di tagihan, auto-expire VIP, penarikan video premium"
git push origin main
```

### B. Di VPS (produksi)

```bash
cd /var/www/dramaverse-id     # sesuaikan path

# 1. Backup DB DULU — migrasi ini menambah kolom & tabel
php artisan backup:run

# 2. Tarik kode
git pull origin main

# 3. Tambahkan ENV baru
nano .env
#   APP_DISPLAY_TIMEZONE=Asia/Jakarta
#   TELEGRAM_PURGE_ON_EXPIRE=true
#   TELEGRAM_VIDEO_TTL_HOURS=24
#   TELEGRAM_PURGE_BATCH=200

# 4. Dependensi + migrasi
composer install --no-dev --optimize-autoloader
php artisan migrate --force

# 5. Bangun ulang cache
php artisan config:clear && php artisan config:cache
php artisan route:clear  && php artisan route:cache
php artisan view:clear   && php artisan view:cache
npm ci && npm run build

# 6. Restart worker (WAJIB — worker lama masih pakai kode lama di memori)
php artisan queue:restart
sudo systemctl restart php8.3-fpm    # sesuaikan versi
```

### C. Uji manual di VPS

```bash
# Kering dulu: lihat berapa yang akan kena, tanpa kirim apa pun
php artisan tinker --execute="echo App\Models\Subscription::where('status','active')->where('expired_at','<',now())->count();"

# Jalankan satu per satu, lihat outputnya
php artisan membership:auto expire
php artisan membership:auto notify
php artisan membership:auto purge

# Kalau sudah yakin, gabungan
php artisan membership:auto sweep
```

### D. Pastikan cron memang jalan

Ini yang paling sering terlewat. Tanpa baris ini **tidak ada satu pun
otomatisasi yang berjalan**, dan gejalanya diam total.

```bash
crontab -e
```

Harus ada tepat satu baris:

```
* * * * * cd /var/www/dramaverse-id && php artisan schedule:run >> /dev/null 2>&1
```

Verifikasi:

```bash
php artisan schedule:list | grep membership
```

---

## Catatan penting

- **Notifikasi dibatasi 24 jam ke belakang.** Saat pertama kali dijalankan di
  sistem yang sudah hidup, langganan yang habis lebih dari sehari lalu ditandai
  "sudah diberi tahu" tanpa dikirimi pesan. Ini disengaja — tanpa batas itu,
  perintah pertama akan mengirim ribuan pesan "paket Anda berakhir" untuk
  langganan yang habis berbulan-bulan lalu, dan itu tidak bisa ditarik kembali.

- **Video yang sudah terkirim sebelum sprint ini tidak tercatat.** Telegram
  tidak punya cara menanyakan pesan lama. Penarikan hanya berlaku untuk video
  yang dikirim setelah deploy ini.

- **Kalau scheduler mati > 2 hari**, video yang menumpuk tidak bisa ditarik lagi
  saat scheduler hidup kembali. Heartbeat di `routes/console.php` ada justru
  untuk membuat scheduler yang mati terlihat di dashboard monitoring.
