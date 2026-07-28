# DramaVerse ID — Panduan Menjalankan & Deploy

## Di mana perintahnya diketik?

Setiap blok perintah di dokumen ini punya penanda lokasi:

| Penanda | Artinya | Cara membukanya |
|---|---|---|
| 💻 **LOKAL** | Terminal di komputer Anda, di dalam folder `C:\ProjectDrama\dramaverse-id` | Buka folder itu di File Explorer → klik kanan area kosong → **Open in Terminal**. Atau di VS Code: `Ctrl + ~` |
| 🌐 **VPS** | Terminal VPS lewat SSH — bukan komputer Anda | Di terminal lokal ketik `ssh root@IP_VPS`, setelah masuk semua perintah berjalan di VPS |
| 🗄️ **MySQL** | Di dalam prompt MySQL (tandanya `mysql>`) | Masuk lewat `mysql -u root -p`, keluar dengan `EXIT;` |
| 📝 **EDITOR** | Mengedit isi berkas, bukan mengetik perintah | Lokal: VS Code / Notepad. VPS: `nano namaberkas` — simpan `Ctrl+O` `Enter`, keluar `Ctrl+X` |

**PowerShell, CMD, atau terminal VS Code — ketiganya sama saja**, asalkan
posisinya sudah di dalam folder project. Cek posisi Anda dengan:

```
pwd
```

Kalau belum di folder yang benar:

```
cd C:\ProjectDrama\dramaverse-id
```

Tiga bagian: **jalankan di lokal** → **push ke GitHub** → **deploy ke VPS**.
Kerjakan berurutan. Jangan lompat ke VPS sebelum lokal jalan.

---

# BAGIAN 1 — Jalankan di Komputer Anda

> 💻 **Semua perintah di bagian ini dijalankan di LOKAL**, di dalam folder
> `C:\ProjectDrama\dramaverse-id` (PowerShell, CMD, atau terminal VS Code).

## 1.1 Pastikan syaratnya terpasang

Buka terminal (PowerShell / CMD) di `C:\ProjectDrama\dramaverse-id`:

```bash
php -v          # butuh 8.2 atau lebih baru
composer -V
node -v         # butuh 18 atau lebih baru
npm -v
mysql --version
```

Kalau ada yang belum ada:

- **PHP + MySQL** — pasang [Laragon](https://laragon.org/download/) (paling mudah di Windows) atau XAMPP
- **Composer** — https://getcomposer.org/download/
- **Node.js** — https://nodejs.org (pilih LTS)

## 1.2 Siapkan database

> 💻 LOKAL — lewat phpMyAdmin di browser, atau 🗄️ prompt MySQL

Buka phpMyAdmin (Laragon: `http://localhost/phpmyadmin`) lalu buat database
baru bernama `dramaverse`, collation `utf8mb4_unicode_ci`.

Atau lewat terminal:

```bash
mysql -u root -p -e "CREATE DATABASE dramaverse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 1.3 Siapkan berkas .env

> 💻 LOKAL + 📝 mengedit `.env` dengan VS Code / Notepad

```bash
copy .env.example .env
php artisan key:generate
```

Buka `.env`, sesuaikan bagian database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dramaverse
DB_USERNAME=root
DB_PASSWORD=            # Laragon biasanya kosong

ADMIN_SEED_PASSWORD=rahasia-anda
```

## 1.4 Pasang dependensi

```bash
composer install
npm install
```

## 1.5 Jalankan migration + isi data contoh

```bash
php artisan migrate:fresh --seed
```

**Ini langkah paling penting.** Kalau ada error di sini, berhenti dan
laporkan pesan errornya — jangan lanjut.

Yang seharusnya muncul: 27 migration `DONE`, lalu 7 seeder berjalan.

## 1.6 Tautkan storage & bangun aset

```bash
php artisan storage:link
npm run build
```

## 1.7 Verifikasi sebelum menjalankan

```bash
php artisan route:list
```

Harus tampil **50 route** tanpa error. Kalau muncul
`Target class [...] does not exist`, berarti ada masalah autoload —
jalankan `composer dump-autoload` lalu ulangi.

Jalankan juga pemeriksa konsistensi:

```bash
python tools\verify-consistency.py
```

## 1.8 Jalankan

```bash
php artisan serve
```

Buka `http://localhost:8000` — homepage harus terisi 26 drama.

**Masuk admin:** `http://localhost:8000/admin/login`
Email `admin@dramaverse.id`, kata sandi sesuai `ADMIN_SEED_PASSWORD` di `.env`.

## 1.9 Kalau ada error

| Pesan | Penyebab | Solusi |
|---|---|---|
| `SQLSTATE[HY000] [1049] Unknown database` | database belum dibuat | ulangi langkah 1.2 |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL belum jalan | nyalakan MySQL di Laragon/XAMPP |
| `Vite manifest not found` | aset belum di-build | `npm run build` |
| `Target class does not exist` | autoload basi | `composer dump-autoload` |
| `Route [x] not defined` | cache route basi | `php artisan optimize:clear` |
| Halaman putih polos | CSS belum ter-build | `npm run build`, lalu `php artisan view:clear` |

Perintah sapu jagat kalau bingung:

```bash
php artisan optimize:clear
composer dump-autoload
npm run build
```

---

# BAGIAN 2 — Push ke GitHub

> 💻 **Semua perintah di bagian ini dijalankan di LOKAL**, di folder yang sama.

Repo Anda sudah tersambung ke `https://github.com/brian-dev1/dramaverse-id.git`
pada branch `main`.

## 2.1 Lihat dulu apa yang akan dikirim

```bash
git status
```

Perubahannya besar — sekitar **349 berkas**: 229 dihapus, 59 diubah,
61 baru. Ini wajar karena banyak komponen dipindah ke `_staging/` dan
view legacy dihapus.

## 2.2 Amankan dulu dengan branch cadangan

Sangat disarankan, supaya versi lama tidak hilang:

```bash
git branch backup-sebelum-sprint1
git push origin backup-sebelum-sprint1
```

## 2.3 Pastikan berkas rahasia tidak ikut

```bash
git check-ignore -v .env
```

Harus muncul baris yang menunjukkan `.env` diabaikan. **Jangan pernah
commit `.env`** — isinya token bot Telegram dan kata sandi database.

## 2.4 Commit

```bash
git add -A
git commit -m "Sprint 1: perbaiki fondasi database, routing, dan view

- Isi 6 migration inti yang sebelumnya kosong (hanya id + timestamps)
- Ubah relasi genre jadi belongsToMany lewat pivot drama_genre
- Perbaiki urutan migration: dramas sebelumnya jalan sebelum countries
- Daftarkan routes/api.php di bootstrap/app.php
- Tulis ulang routing: 50 route, penamaan konsisten web.* dan admin.*
- Perbaiki 14 referensi route mati di navbar/footer
- Perbaiki PSR-4: EpisodeRepository dan folder Console/Command
- Ganti scopeLatest yang menimpa Builder::latest() bawaan Eloquent
- Samakan palet CSS dengan preview yang disetujui
- Karantina 76 komponen yatim + 94 CSS ke _staging/
- Bind 25 interface repository di AppServiceProvider
- Tambah 7 seeder: 26 drama contoh beserta episode dan taksonomi"
```

## 2.5 Push

```bash
git push origin main
```

Kalau ditolak karena remote lebih baru:

```bash
git pull origin main --rebase
git push origin main
```

---

# BAGIAN 3 — Deploy ke VPS Ubuntu

> 🌐 **Mulai dari langkah 3.1, semua perintah dijalankan di VPS**, bukan di
> komputer Anda. Perintah `ssh` di 3.1 adalah satu-satunya yang diketik di
> terminal lokal.

Asumsi: VPS Ubuntu 22.04/24.04, akses SSH sebagai root atau user sudo,
dan domain sudah diarahkan ke IP VPS.

Ganti `dramaverse.id` dengan domain Anda di semua perintah di bawah.

## 3.1 Masuk ke VPS

```bash
ssh root@IP_VPS_ANDA
```

## 3.2 Pasang semua kebutuhan

```bash
apt update && apt upgrade -y

# Repositori PHP 8.3
apt install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update

apt install -y nginx mysql-server git unzip curl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath \
  php8.3-intl php8.3-redis redis-server

# Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Verifikasi
php -v && composer -V && node -v && nginx -v
```

## 3.3 Amankan MySQL & buat database

> 🌐 VPS, lalu 🗄️ di dalam prompt `mysql>`

```bash
mysql_secure_installation
```

Lalu:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE dramaverse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dramaverse'@'localhost' IDENTIFIED BY 'GANTI_KATA_SANDI_KUAT';
GRANT ALL PRIVILEGES ON dramaverse.* TO 'dramaverse'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3.4 Ambil kode

```bash
mkdir -p /var/www
cd /var/www
git clone https://github.com/brian-dev1/dramaverse-id.git dramaverse
cd dramaverse
```

Kalau repo privat, buat Personal Access Token di GitHub
(*Settings → Developer settings → Tokens*) lalu:

```bash
git clone https://TOKEN@github.com/brian-dev1/dramaverse-id.git dramaverse
```

## 3.5 Pasang dependensi (mode produksi)

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 3.6 Konfigurasi .env produksi

> 🌐 VPS + 📝 mengedit berkas dengan `nano`

```bash
cp .env.example .env
nano .env
```

Isi seperti ini:

```env
APP_NAME="DramaVerse ID"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://dramaverse.id

APP_LOCALE=id
APP_TIMEZONE=Asia/Jakarta

LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dramaverse
DB_USERNAME=dramaverse
DB_PASSWORD=GANTI_KATA_SANDI_KUAT

TELEGRAM_BOT_TOKEN=token_dari_@BotFather
TELEGRAM_BOT_USERNAME=nama_bot_anda
TELEGRAM_WEBHOOK_SECRET=buat_string_acak_panjang

ADMIN_SEED_PASSWORD=kata_sandi_admin_yang_kuat

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
FILESYSTEM_DISK=public
```

`APP_DEBUG=false` itu **wajib** di produksi. Kalau `true`, pesan error
akan membocorkan isi `.env` ke pengunjung.

Lalu:

```bash
php artisan key:generate
```

## 3.7 Migration & data awal

```bash
php artisan migrate --force
php artisan db:seed --force      # lewati kalau tidak mau data contoh
php artisan storage:link
```

`--force` diperlukan karena Laravel menolak migration otomatis di produksi.

## 3.8 Atur kepemilikan berkas

```bash
chown -R www-data:www-data /var/www/dramaverse
chmod -R 755 /var/www/dramaverse
chmod -R 775 /var/www/dramaverse/storage
chmod -R 775 /var/www/dramaverse/bootstrap/cache
```

## 3.9 Konfigurasi Nginx

> 🌐 VPS + 📝 mengedit berkas dengan `nano`

```bash
nano /etc/nginx/sites-available/dramaverse
```

Isi:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name dramaverse.id www.dramaverse.id;

    root /var/www/dramaverse/public;
    index index.php;

    charset utf-8;
    client_max_body_size 512M;

    # Keamanan dasar
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Aset statis di-cache lama
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff2?)$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Tolak akses ke berkas tersembunyi
    location ~ /\.(?!well-known).* { deny all; }

    error_page 404 /index.php;

    access_log /var/log/nginx/dramaverse-access.log;
    error_log  /var/log/nginx/dramaverse-error.log;
}
```

Aktifkan:

```bash
ln -s /etc/nginx/sites-available/dramaverse /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t                 # harus "syntax is ok" dan "test is successful"
systemctl reload nginx
```

## 3.10 Pasang SSL (HTTPS)

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d dramaverse.id -d www.dramaverse.id
```

Ikuti promptnya, pilih opsi redirect HTTP ke HTTPS. Certbot akan
memperbarui sertifikat otomatis.

## 3.11 Optimasi produksi

```bash
cd /var/www/dramaverse
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**Penting:** setiap kali `.env` berubah, jalankan `php artisan config:cache`
ulang — kalau tidak, perubahannya tidak terbaca.

## 3.12 Jalankan worker antrean

```bash
apt install -y supervisor
nano /etc/supervisor/conf.d/dramaverse-worker.conf
```

Isi:

```ini
[program:dramaverse-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/dramaverse/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/dramaverse/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start dramaverse-worker:*
supervisorctl status
```

## 3.13 Pasang scheduler

```bash
crontab -e -u www-data
```

Tambahkan satu baris:

```cron
* * * * * cd /var/www/dramaverse && php artisan schedule:run >> /dev/null 2>&1
```

## 3.14 Sambungkan webhook Telegram

```bash
curl -F "url=https://dramaverse.id/telegram/webhook" \
     -F "secret_token=ISI_SAMA_DENGAN_TELEGRAM_WEBHOOK_SECRET" \
     https://api.telegram.org/botTOKEN_BOT_ANDA/setWebhook
```

Cek statusnya:

```bash
curl https://api.telegram.org/botTOKEN_BOT_ANDA/getWebhookInfo
```

## 3.15 Aktifkan firewall

```bash
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
ufw status
```

---

# BAGIAN 4 — Update Berikutnya

> 🌐 **Dijalankan di VPS** setelah Anda `git push` dari lokal.

Setiap kali ada perubahan kode, di VPS jalankan:

```bash
cd /var/www/dramaverse

php artisan down                    # mode pemeliharaan

git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache
supervisorctl restart dramaverse-worker:*

php artisan up                      # kembali online
```

Agar tidak mengetik ulang, simpan sebagai skrip:

```bash
nano /var/www/dramaverse/deploy.sh
chmod +x /var/www/dramaverse/deploy.sh
```

Isi `deploy.sh`:

```bash
#!/usr/bin/env bash
set -e
cd /var/www/dramaverse

echo "==> Mode pemeliharaan"
php artisan down || true

echo "==> Tarik kode terbaru"
git pull origin main

echo "==> Dependensi"
composer install --no-dev --optimize-autoloader
npm ci
npm run build

echo "==> Migration"
php artisan migrate --force

echo "==> Cache"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Izin berkas"
chown -R www-data:www-data storage bootstrap/cache

echo "==> Restart worker"
supervisorctl restart dramaverse-worker:* || true

php artisan up
echo "==> Selesai"
```

Cukup jalankan `./deploy.sh` setiap kali update.

---

# Daftar Periksa Sebelum Dianggap Live

- [ ] `php artisan route:list` menampilkan 50 route tanpa error
- [ ] Homepage terbuka dan terisi drama
- [ ] `/admin/login` bisa diakses dan login berhasil
- [ ] `APP_DEBUG=false` di `.env` produksi
- [ ] `.env` **tidak** ada di GitHub
- [ ] HTTPS aktif, HTTP dialihkan ke HTTPS
- [ ] `ufw status` menunjukkan firewall aktif
- [ ] `supervisorctl status` menunjukkan worker `RUNNING`
- [ ] Webhook Telegram merespons (`getWebhookInfo` tanpa `last_error_message`)
- [ ] Kata sandi admin sudah diganti dari nilai bawaan

---

# Kalau Bermasalah di VPS

> 🌐 Dijalankan di VPS

**Cek log dulu, jangan menebak:**

```bash
tail -50 /var/www/dramaverse/storage/logs/laravel.log
tail -50 /var/log/nginx/dramaverse-error.log
systemctl status php8.3-fpm
systemctl status nginx
```

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| 502 Bad Gateway | PHP-FPM mati atau path socket salah | `systemctl restart php8.3-fpm`, cocokkan `fastcgi_pass` dengan `ls /var/run/php/` |
| 500 Server Error | izin storage | `chown -R www-data:www-data storage bootstrap/cache` |
| 404 di semua halaman kecuali beranda | `try_files` salah | periksa blok `location /` di Nginx |
| Perubahan `.env` tidak terbaca | config ter-cache | `php artisan config:cache` |
| Halaman tanpa gaya | aset belum di-build | `npm run build` |
| Gambar upload tidak muncul | symlink hilang | `php artisan storage:link` |
