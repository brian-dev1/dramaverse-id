# Panduan Instalasi

## Kebutuhan

| Hal | Versi |
|---|---|
| PHP | 8.3 |
| MySQL | 8.0 |
| Node | 20+ |
| Composer | 2.x |
| Redis | opsional, untuk cache dan antrean |

Ekstensi PHP: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`,
`gd`.

## Lokal (Windows)

```powershell
git clone https://github.com/brian-dev1/dramaverse-id.git
cd dramaverse-id

composer install
npm install

copy .env.example .env
php artisan key:generate
```

Isi `DB_*` di `.env`, buat basis datanya, lalu:

```powershell
php artisan migrate
php artisan db:seed
php artisan storage:link

php artisan serve
npm run dev
```

Akun admin awal dibuat `AdminSeeder` dengan kata sandi dari
`ADMIN_SEED_PASSWORD`. **Ganti segera setelah masuk pertama kali:**

```powershell
php artisan admin:password
```

## Server produksi (Ubuntu 24.04)

```bash
cd /var/www
git clone https://github.com/brian-dev1/dramaverse-id.git dramaverse
cd dramaverse

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

Isi `.env` mengikuti [KONFIGURASI.md](KONFIGURASI.md), lalu:

```bash
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\RoleSeeder' --force
php artisan storage:link

chown -R www-data:www-data storage bootstrap/cache
```

### Batas unggah

Batas bawaan PHP dan Nginx jauh di bawah ukuran video episode.

```
# /etc/php/8.3/fpm/php.ini
upload_max_filesize = 4G
post_max_size = 4G
max_execution_time = 3600
```

```
# /etc/nginx/sites-available/dramaverse
client_max_body_size 4G;
```

Berkas yang melewati `post_max_size` membuat request kosong dan muncul
sebagai 419 tanpa penjelasan, bukan sebagai pesan validasi.

### Cron dan worker

Keduanya WAJIB. Lihat [ANTREAN.md](ANTREAN.md).

### Verifikasi

```bash
php artisan env:check --production
```

Keluar dengan kode 1 bila ada temuan FATAL.
