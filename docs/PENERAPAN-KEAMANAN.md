# Penerapan Keamanan — Langkah demi Langkah

Panduan urut untuk menerapkan seluruh lapisan keamanan. Penjelasan *kenapa*
setiap lapis ada tidak diulang di sini — itu ada di
[`KEAMANAN.md`](KEAMANAN.md). Dokumen ini hanya **apa yang diketik, di mana.**

## Di mana perintahnya diketik?

Penanda yang sama dengan `DEPLOY.md`:

| Penanda | Artinya | Cara membukanya |
|---|---|---|
| 💻 **LOKAL** | Komputer Anda, di dalam `C:\ProjectDrama\dramaverse-id` | VS Code → `` Ctrl + ` `` . PowerShell / CMD / terminal VS Code sama saja |
| 🌐 **VPS** | Terminal VPS lewat SSH | Dari terminal lokal: `ssh root@IP_VPS` |
| 📝 **EDITOR** | Menyunting isi berkas | VPS: `nano namaberkas` → simpan `Ctrl+O` `Enter` → keluar `Ctrl+X` |
| 🗄️ **MySQL** | Di dalam prompt MySQL (`mysql>`) | `mysql -u root -p`, keluar `EXIT;` |
| 🌍 **BROWSER** | Dashboard di peramban | — |

**Cara mengenali Anda sedang di mana:** perhatikan awal baris prompt.
`PS C:\ProjectDrama\dramaverse-id>` berarti lokal. `root@namaserver:~#` berarti
VPS. Kalau ragu, ketik `pwd` — hasil `/root` atau `/var/www/...` berarti VPS,
hasil `C:\...` berarti lokal.

## Urutan dan perkiraan waktu

| Tahap | Isi | Waktu | Risiko |
|---|---|---|---|
| 0 | Pengintaian — tidak mengubah apa pun | 5 mnt | nihil |
| 1 | Perbaiki `.env` produksi | 10 mnt | rendah |
| 2 | Commit & push | 2 mnt | nihil |
| 3 | Deploy kode | 5 mnt | rendah |
| 4 | Verifikasi situs hidup | 5 mnt | nihil |
| 5 | nginx | 15 mnt | **sedang** |
| 6 | Firewall | 10 mnt | **sedang** |
| 7 | fail2ban | 10 mnt | rendah |
| 8 | MySQL hak minimum | 20 mnt | **sedang** |
| 9 | Cloudflare | 30 mnt | rendah |

Tahap 0–4 menutup sebagian besar risiko dan hampir tidak mungkin merusak apa
pun. **Kalau waktu terbatas, kerjakan itu dulu dan berhenti** — sisanya bisa
lain hari. Jangan memaksakan tahap 5–8 saat lelah atau terburu-buru; ketiganya
bisa memutus akses dan pemulihannya butuh kepala dingin.

---

# TAHAP 0 — Pengintaian

Tidak ada yang diubah. Tujuannya mengumpulkan dua fakta yang menentukan
langkah berikutnya.

### 0.1 Masuk ke VPS

💻 **LOKAL**

```powershell
ssh root@IP_VPS_ANDA
```

Setelah prompt berubah jadi `root@...:~#`, semua perintah berikutnya berjalan
di VPS sampai Anda mengetik `exit`.

### 0.2 Apakah `APP_DEBUG` menyala di server?

🌐 **VPS**

```bash
grep -E '^(APP_ENV|APP_DEBUG|DB_USERNAME)=' /var/www/dramaverse/.env
```

Catat hasilnya. Kalau muncul `APP_DEBUG=true`, ini masalah paling mendesak di
seluruh dokumen — diperbaiki di tahap 1.

### 0.3 Apakah ada proxy di depan nginx?

Ini menentukan apakah tahap 5 wajib dikerjakan **sebelum** tahap 3.

🌐 **VPS**

```bash
awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -20
```

> Berkasnya `access.log`, bukan `dracinverse-access.log`. Konfigurasi nginx
> yang sekarang memakai log bawaan; konfigurasi baru di tahap 5 memindahkannya
> ke berkas khusus situs ini, dan setelah itu barulah nama yang panjang
> berlaku.

Baca hasilnya:

**Kolom kiri kecil-kecil (1–50) dan IP-nya banyak dan beragam**
→ Nginx menerima langsung dari internet. `$request->ip()` sudah benar.
Lanjutkan urut 1 → 2 → 3 → 4 → 5.

**Beberapa IP dengan hitungan sangat besar, dan IP-nya berulang terus**
→ Ada proxy/CDN di depan. Semua pengunjung terbaca sebagai IP yang sama.
**Kerjakan tahap 5 lebih dulu, baru tahap 3** — kalau tidak, `throttle:publik`
akan memblokir pengguna asli secara massal. Cocokkan IP-nya di
<https://www.cloudflare.com/ips/> untuk memastikan itu Cloudflare.

> Kalau berkas lognya belum ada atau kosong (situs baru), anggap saja **tidak
> ada proxy** dan lanjutkan urut biasa.

### 0.4 Cadangkan dulu

🌐 **VPS**

```bash
mkdir -p /root/cadangan-keamanan
cp /var/www/dramaverse/.env                      /root/cadangan-keamanan/env.lama
cp /etc/nginx/sites-available/dramaverse         /root/cadangan-keamanan/nginx.lama
mysqldump -u root -p dramaverse | gzip > /root/cadangan-keamanan/db-sebelum.sql.gz
ls -lh /root/cadangan-keamanan/
```

Tiga berkas ini adalah jalan pulang untuk tahap 1, 5, dan 8. Jangan lewati.

---

# TAHAP 1 — Perbaiki `.env` produksi

Nilai per menit tertinggi di seluruh dokumen. Tidak butuh kode baru.

### 1.1 Buka `.env`

🌐 **VPS**

```bash
cd /var/www/dramaverse
nano .env
```

### 1.2 Ubah nilainya

📝 **EDITOR**

Sebagian besar setelan di server ini **sudah benar**. Yang perlu diubah hanya
dua baris, ditambah satu yang baru:

```dotenv
LOG_LEVEL=warning
SESSION_ENCRYPT=true
LOG_KEAMANAN_DAYS=30
```

Simpan: `Ctrl+O` → `Enter` → `Ctrl+X`.

#### Yang SUDAH benar — jangan diubah

| Setelan | Nilai sekarang | Kenapa dibiarkan |
|---|---|---|
| `APP_ENV` | `production` | sudah benar |
| `APP_DEBUG` | `false` | sudah benar — ini yang paling penting |
| `SESSION_SECURE_COOKIE` | `true` | sudah benar |
| `SESSION_LIFETIME` | `120` | dua jam, lebih ketat daripada anjuran umum. Bagus |
| `DB_USERNAME` | `dramaverse_user` | bukan root. Lihat tahap 8 |

> ### ⚠️ `SESSION_SAME_SITE` — jangan disentuh sama sekali
>
> Nilainya `none`, dan **itu memang harus begitu.** Mini App Telegram berjalan
> di dalam iframe milik domain lain (`web.telegram.org`). Cookie dengan
> `SameSite=Lax` — apalagi `Strict` — tidak ikut terkirim dari konteks
> semacam itu, jadi sesi tidak pernah terbaca dan login otomatis gagal 419
> tanpa satu pun pesan galat yang menjelaskan kenapa.
>
> `none` aman di sini justru karena `SESSION_SECURE_COOKIE=true` sudah
> terpasang: peramban modern menolak `SameSite=None` yang tidak disertai
> `Secure`. Keduanya bekerja sebagai pasangan — mengubah salah satunya
> merusak yang lain.

### 1.3 Terapkan

🌐 **VPS**

```bash
cd /var/www/dramaverse
php artisan config:clear
php artisan config:cache
systemctl reload php8.3-fpm
```

> `config:cache` wajib, tapi ingat efek sampingnya: **setelah ini, perubahan
> `.env` berikutnya tidak berpengaruh sampai `config:clear` dijalankan lagi.**
> Ini penyebab paling umum dari "sudah saya ubah tapi tidak berubah".

### 1.4 Periksa

🌐 **VPS**

```bash
php artisan tinker --execute="echo config('app.debug') ? 'BAHAYA: debug masih menyala' : 'aman: debug mati';"
```

Harus menjawab `aman: debug mati`.

Lalu buka situs di peramban. **Pastikan masih bisa login** — `SESSION_ENCRYPT`
membuat seluruh sesi lama tidak terbaca, jadi semua orang (termasuk Anda)
otomatis logout sekali. Itu normal dan hanya terjadi sekali.

### Kalau situs rusak

🌐 **VPS**

```bash
cp /root/cadangan-keamanan/env.lama /var/www/dramaverse/.env
cd /var/www/dramaverse && php artisan config:clear && php artisan config:cache
systemctl reload php8.3-fpm
```

---

# TAHAP 2 — Commit & push

Kembali ke komputer Anda. Kalau masih di dalam SSH, ketik `exit` dulu.

### 2.1 Periksa apa yang akan dikirim

💻 **LOKAL**

```powershell
cd C:\ProjectDrama\dramaverse-id
git status
```

Yang harus muncul:

```
modified:   app/Listeners/LogAuthenticationEvents.php
modified:   app/Providers/AppServiceProvider.php
modified:   app/Repositories/DramaRepository.php
modified:   app/Repositories/Web/WebSearchRepository.php
modified:   bootstrap/app.php
modified:   config/logging.php
modified:   routes/web.php
Untracked files:
        app/Http/Middleware/BlockProbeRequests.php
        deploy/
        docs/KEAMANAN.md
        docs/PENERAPAN-KEAMANAN.md
```

**`.env` tidak boleh muncul di daftar ini.** Kalau muncul, berhenti — berarti
`.gitignore` bermasalah dan kredensial akan ikut terkirim ke GitHub.

### 2.2 Kirim

💻 **LOKAL**

```powershell
git add -A
git commit -m "Keamanan berlapis: throttle publik, penyaring pemindai, hardening input pencarian"
git push origin main
```

---

# TAHAP 3 — Deploy kode

> Kalau tahap 0.3 menunjukkan **ada proxy di depan**, kerjakan **TAHAP 5 lebih
> dulu**, lalu kembali ke sini.

### 3.1 Jalankan deploy

💻 **LOKAL**

```powershell
ssh root@IP_VPS_ANDA
```

🌐 **VPS**

```bash
cd /var/www/dramaverse
bash deploy.sh
```

Skrip ini menyalakan mode pemeliharaan, menarik kode, memasang dependensi,
membangun aset, menjalankan migrasi, dan membangun ulang cache. **Tidak ada
migrasi baru** dari perubahan ini, tapi skripnya tetap menjalankan `migrate`
seperti biasa.

Tunggu sampai muncul `==> Selesai`. Kalau berhenti dengan `DEPLOY GAGAL pada
langkah: ...`, situs sudah dinyalakan kembali tapi masih menjalankan kode
lama — baca pesannya, perbaiki, jalankan ulang.

### 3.2 Pastikan log keamanan bisa ditulis

🌐 **VPS**

```bash
cd /var/www/dramaverse
chown -R www-data:www-data storage/logs
chmod -R 775 storage/logs
```

Berkas `keamanan-YYYY-MM-DD.log` baru akan dibuat di sana, dan fail2ban di
tahap 7 membacanya.

---

# TAHAP 4 — Verifikasi

🌐 **VPS**

### 4.1 Situs hidup dan normal

```bash
curl -s -o /dev/null -w "beranda: %{http_code}\n"  https://dracinverse.cloud/
curl -s -o /dev/null -w "search : %{http_code}\n"  https://dracinverse.cloud/search
```

Keduanya harus `200`.

### 4.2 Penyaring pemindai bekerja

```bash
curl -s -o /dev/null -w "wp-admin: %{http_code}\n" https://dracinverse.cloud/wp-admin/
curl -s -o /dev/null -w "sqlmap  : %{http_code}\n" -A "sqlmap/1.7" https://dracinverse.cloud/
```

Keduanya harus `404`. (Setelah tahap 5, keduanya berubah jadi `000` — nginx
menutup koneksi tanpa menjawab. Itu juga benar.)

### 4.3 Log keamanan terisi

```bash
cat /var/www/dramaverse/storage/logs/keamanan-$(date +%F).log
```

Harus memuat dua baris `Permintaan pemindai ditolak` dari uji barusan. Kalau
berkasnya tidak ada, ulangi 3.2.

### 4.4 Batas laju bekerja

```bash
for i in $(seq 1 150); do
  curl -s -o /dev/null -w "%{http_code} " https://dracinverse.cloud/trending
done; echo
```

Sekitar 120 permintaan pertama `200`, sisanya `429`. **Kalau `429` muncul
sangat awal (di bawah 20), hentikan dan periksa tahap 0.3** — kemungkinan ada
proxy dan seluruh pengunjung sedang berbagi satu jatah.

### 4.5 Buka situs sungguhan

🌍 **BROWSER** — buka <https://dracinverse.cloud>, coba: cari drama, buka detail
drama, putar episode, login lewat Telegram, buka panel admin. Pencarian dan
paginasi paling mungkin terdampak perubahan ini.

---

# TAHAP 5 — nginx

**Risiko sedang.** Konfigurasi salah membuat nginx menolak start, dan situs
mati sampai diperbaiki. Cadangannya sudah ada dari tahap 0.4.

### 5.1 Cek sertifikat SSL sudah ada

🌐 **VPS**

```bash
ls -l /etc/letsencrypt/live/dracinverse.cloud/fullchain.pem
```

Kalau **tidak ada**, jangan lanjut — pasang dulu:

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d dracinverse.cloud -d www.dracinverse.cloud
```

### 5.2 Pasang BAGIAN A — zona pembatas

Harus lebih dulu: konfigurasi situs merujuk zona yang dideklarasikan di sini.

🌐 **VPS**

```bash
cp /var/www/dramaverse/deploy/nginx-limits.conf /etc/nginx/conf.d/dracinverse-limits.conf
nginx -t
```

Harus `syntax is ok` dan `test is successful`.

### 5.3 Kalau ada Cloudflare — aktifkan `real_ip`

**Lewati langkah ini kalau tahap 0.3 menunjukkan tidak ada proxy.**

🌐 **VPS**

```bash
nano /etc/nginx/conf.d/dracinverse-limits.conf
```

📝 **EDITOR** — hapus tanda `#` di depan seluruh baris `set_real_ip_from ...`
dan baris `real_ip_header CF-Connecting-IP;`. Simpan.

```bash
nginx -t && systemctl reload nginx
```

> Mengaktifkan ini **tanpa** proxy di depan justru berbahaya: siapa pun bisa
> mengirim header `CF-Connecting-IP` palsu dan lolos dari semua batas per-IP.
> Aktifkan hanya kalau tahap 0.3 memang menunjukkan ada proxy.

### 5.4 Pasang BAGIAN B — konfigurasi situs

🌐 **VPS**

```bash
cp /var/www/dramaverse/deploy/nginx-dramaverse.conf /etc/nginx/sites-available/dramaverse
ln -sf /etc/nginx/sites-available/dramaverse /etc/nginx/sites-enabled/dramaverse
rm -f /etc/nginx/sites-enabled/default
nginx -t
```

**Kalau `nginx -t` gagal, JANGAN reload.** Nginx yang sedang berjalan masih
memakai konfigurasi lama dan situs tetap hidup. Baca pesan galatnya, perbaiki,
uji lagi.

Kalau `fastcgi_pass` mengeluh soket tidak ada, cek versi PHP Anda:

```bash
ls /var/run/php/
```

Sesuaikan `php8.3-fpm.sock` di konfigurasi dengan yang benar-benar ada.

### 5.5 Terapkan

🌐 **VPS**

```bash
systemctl reload nginx
systemctl status nginx --no-pager
```

### 5.6 Periksa

🌐 **VPS**

```bash
# Situs normal
curl -s -o /dev/null -w "beranda: %{http_code}\n" https://dracinverse.cloud/

# Pemindai ditutup tanpa jawaban — 000 itu benar
curl -s -o /dev/null -w "wp-admin: %{http_code}\n" https://dracinverse.cloud/wp-admin/

# .env tidak bisa diambil
curl -s -o /dev/null -w "env     : %{http_code}\n" https://dracinverse.cloud/.env

# http dialihkan ke https
curl -s -o /dev/null -w "http    : %{http_code}\n" http://dracinverse.cloud/
```

Harapan: beranda `200`, wp-admin `000`, env `000`, http `301`.

🌍 **BROWSER** — buka situs, putar satu episode, buka panel admin, coba unggah
berkas kecil di admin. Yang paling mungkin terdampak: unggahan besar
(`client_max_body_size` sekarang 2M di seluruh situs, 512M hanya di jalur
`/admin/unggah`).

### Kalau situs mati

🌐 **VPS**

```bash
cp /root/cadangan-keamanan/nginx.lama /etc/nginx/sites-available/dramaverse
rm -f /etc/nginx/conf.d/dracinverse-limits.conf
nginx -t && systemctl reload nginx
```

---

# TAHAP 6 — Firewall

**Risiko sedang: bisa mengunci Anda keluar dari SSH.**

### 6.1 Buka sesi SSH kedua

💻 **LOKAL** — buka jendela terminal **baru** (jangan tutup yang lama):

```powershell
ssh root@IP_VPS_ANDA
```

Biarkan terbuka sampai tahap 6 selesai. Kalau firewall salah pasang, sesi
yang sudah terhubung ini tetap hidup dan jadi satu-satunya jalan memperbaiki
tanpa konsol VPS.

### 6.2 Cek port SSH Anda

🌐 **VPS**

```bash
grep -E '^#?Port ' /etc/ssh/sshd_config
ss -tlnp | grep sshd
```

Kalau bukan `22`, catat angkanya.

### 6.3 Sesuaikan skripnya

🌐 **VPS**

```bash
nano /var/www/dramaverse/deploy/firewall.sh
```

📝 **EDITOR** — ubah `PORT_SSH=22` sesuai hasil 6.2. Simpan.

### 6.4 Jalankan

🌐 **VPS**

```bash
bash /var/www/dramaverse/deploy/firewall.sh
```

### 6.5 Periksa — dari sesi SSH KEDUA

🌐 **VPS** (jendela kedua)

```bash
ufw status verbose
sysctl net.ipv4.tcp_syncookies          # harus = 1
ss -tlnp | grep -E '3306|6379'          # harus 127.0.0.1, bukan 0.0.0.0
```

Lalu **buka sesi SSH ketiga dari lokal** untuk membuktikan SSH baru masih bisa
masuk:

💻 **LOKAL**

```powershell
ssh root@IP_VPS_ANDA
```

Kalau berhasil, firewall aman. Baru tutup sesi-sesi cadangan.

### Kalau terkunci

Masuk lewat **konsol VPS di panel Rumahweb** (VNC / serial console), lalu:

```bash
ufw disable
```

---

# TAHAP 7 — fail2ban

### 7.1 Pasang

🌐 **VPS**

```bash
apt update && apt install -y fail2ban
cp /var/www/dramaverse/deploy/fail2ban/jail.local      /etc/fail2ban/jail.local
cp /var/www/dramaverse/deploy/fail2ban/filter.d/*.conf /etc/fail2ban/filter.d/
```

### 7.2 Masukkan IP Anda ke daftar aman

**Jangan lewati.** Ini yang mencegah Anda memblokir diri sendiri saat menguji.

💻 **LOKAL** — cari tahu IP publik Anda:

```powershell
curl https://ifconfig.me
```

🌐 **VPS**

```bash
nano /etc/fail2ban/jail.local
```

📝 **EDITOR** — tambahkan IP itu di baris `ignoreip`:

```ini
ignoreip = 127.0.0.1/8 ::1 IP_PUBLIK_ANDA
```

> Kalau internet rumah Anda memakai IP dinamis (umum di Indonesia), IP ini
> berubah sewaktu-waktu. Itu tidak apa-apa — kalau suatu hari terblokir,
> lepaskan lewat konsol VPS dengan perintah `unbanip` di bagian pemeliharaan.

### 7.3 Uji filter sebelum dinyalakan

🌐 **VPS**

```bash
fail2ban-regex /var/www/dramaverse/storage/logs/keamanan-$(date +%F).log \
               /etc/fail2ban/filter.d/laravel-pemindai.conf
```

Harus melaporkan `Matched` lebih dari 0 — mencocokkan baris uji dari tahap 4.2.

### 7.4 Nyalakan

🌐 **VPS**

```bash
systemctl enable fail2ban
systemctl restart fail2ban
sleep 8
fail2ban-client status
```

> **`restart`, bukan `enable --now`.** `apt install fail2ban` sudah menyalakan
> servisnya seketika, dengan konfigurasi bawaan yang hanya memuat jail `sshd`
> — dan itu terjadi sebelum `jail.local` sempat disalin. `--now` hanya
> menyalakan servis yang sedang mati, jadi pada servis yang sudah hidup ia
> tidak melakukan apa pun sama sekali.
>
> Gejalanya membingungkan karena semuanya tampak benar:
> `fail2ban-client -t` menjawab `OK`, berkas filter ada di tempatnya, dan
> jail `sshd` bahkan bekerja dan memblokir IP. Yang salah hanya satu — empat
> jail lainnya tidak pernah dibaca, dan `fail2ban-client status` menjawab
> `UnknownJailException` untuk keempatnya.

Harus muncul lima jail: `sshd`, `nginx-banjir`, `laravel-pemindai`,
`nginx-probe`, `laravel-login`.

Kalau ada jail yang gagal start, biasanya karena berkas log-nya belum ada:

```bash
tail -30 /var/log/fail2ban.log
```

### 7.5 Periksa

🌐 **VPS**

```bash
fail2ban-client status nginx-banjir
fail2ban-client status laravel-pemindai
```

---

# TAHAP 8 — MySQL hak minimum

> ### Prioritas turun — bagian terburuknya sudah tertutup
>
> Server ini memakai `dramaverse_user`, bukan `root`, dan haknya:
>
> ```
> GRANT USAGE ON *.*              -- tidak ada hak global sama sekali
> GRANT ALL PRIVILEGES ON dramaverse.*
> ```
>
> `USAGE ON *.*` artinya **tidak punya `FILE`, `SUPER`, maupun `PROCESS`.**
> Itu justru bagian yang paling berbahaya, dan sudah tertutup. Tanpa `FILE`,
> SQL injection tidak bisa menulis cangkang PHP ke direktori web lewat
> `SELECT ... INTO OUTFILE`, dan tidak bisa membaca `.env` atau kunci SSH
> lewat `LOAD_FILE()`. Jalan dari "database tembus" menuju "server jatuh"
> sudah putus.
>
> Yang tersisa: `ALL PRIVILEGES` pada basis data `dramaverse` masih mencakup
> `DROP` dan `ALTER`. Satu celah injeksi masih bisa menghapus tabel — buruk,
> tapi bisa dipulihkan dari cadangan, dan tidak menyentuh apa pun di luar
> basis data itu.
>
> **Kesimpulan: tahap ini opsional.** Perbaikan nyata tapi kecil, dan risikonya
> lebih besar daripada tahap lain. Kalau dilewati, tidak apa-apa. Kalau
> dikerjakan, lakukan saat tenang, bukan di akhir sesi panjang.

**Risiko sedang.** Kredensial salah membuat situs mati total dengan
`SQLSTATE[HY000] [1045] Access denied`.

### 8.1 Buat tiga kata sandi acak

🌐 **VPS**

```bash
echo "app     : $(openssl rand -base64 24)"
echo "migrasi : $(openssl rand -base64 24)"
echo "cadangan: $(openssl rand -base64 24)"
```

Salin ketiganya ke tempat aman sekarang — tidak bisa dimunculkan ulang.

### 8.2 Sunting berkas SQL

🌐 **VPS**

```bash
nano /var/www/dramaverse/deploy/mysql-hak-minimum.sql
```

📝 **EDITOR** — ganti **empat** penampung dengan kata sandi dari 8.1:

- `SET @sandi_app = 'GANTI_...'` → kata sandi app
- `'dramaverse_app'@'localhost' IDENTIFIED BY 'GANTI_...'` → kata sandi app *(sama)*
- `'dramaverse_migrasi'@'localhost' IDENTIFIED BY 'GANTI_..._LAIN'` → kata sandi migrasi
- `'dramaverse_cadangan'@'localhost' IDENTIFIED BY 'GANTI_..._LAIN_LAGI'` → kata sandi cadangan

Pastikan juga nama basis datanya cocok. Cek dengan:

```bash
grep '^DB_DATABASE=' /var/www/dramaverse/.env
```

Kalau bukan `dramaverse`, ganti semua `` `dramaverse`.* `` di berkas SQL.

### 8.3 Jalankan

🌐 **VPS**

```bash
mysql -u root -p < /var/www/dramaverse/deploy/mysql-hak-minimum.sql
```

### 8.4 Uji kredensial baru SEBELUM dipakai

🌐 **VPS**

```bash
mysql -u dramaverse_app -p -e "SELECT COUNT(*) FROM dramaverse.dramas;"
```

Harus menampilkan angka. Kalau `Access denied`, ulangi 8.2 — jangan lanjut.

### 8.5 Ubah `.env`

🌐 **VPS**

```bash
nano /var/www/dramaverse/.env
```

📝 **EDITOR**

```dotenv
DB_USERNAME=dramaverse_app
DB_PASSWORD=kata_sandi_app_dari_8.1
```

🌐 **VPS**

```bash
cd /var/www/dramaverse
php artisan config:clear
php artisan config:cache
systemctl reload php8.3-fpm
supervisorctl restart dramaverse-worker:*
```

### 8.6 Periksa

🌍 **BROWSER** — buka situs. Cari drama, buka detail, login, buka panel admin,
**coba simpan satu perubahan di admin** (untuk menguji hak `UPDATE`).

🌐 **VPS**

```bash
tail -20 /var/www/dramaverse/storage/logs/laravel.log
```

Tidak boleh ada `SQLSTATE` atau `Access denied`.

### 8.7 Catatan untuk deploy berikutnya

Pengguna `dramaverse_app` **tidak punya hak menjalankan migrasi**. Mulai
sekarang `deploy.sh` akan gagal di langkah `migrate` kalau ada migrasi baru.

Untuk deploy yang memuat migrasi, jalankan migrasinya terpisah:

🌐 **VPS**

```bash
cd /var/www/dramaverse
DB_USERNAME=dramaverse_migrasi DB_PASSWORD='sandi_migrasi' php artisan migrate --force
```

### Kalau situs mati

🌐 **VPS**

```bash
cp /root/cadangan-keamanan/env.lama /var/www/dramaverse/.env
cd /var/www/dramaverse && php artisan config:clear && php artisan config:cache
systemctl reload php8.3-fpm
```

### 8.8 Kunci root — hanya setelah situs terbukti normal

🗄️ **MySQL** (`mysql -u root -p`)

```sql
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
EXIT;
```

---

# TAHAP 9 — Cloudflare

Lapis paling efektif melawan DDoS volumetrik, dan satu-satunya yang bekerja di
luar VPS. Paket gratis sudah cukup.

### 9.1 Daftarkan domain

🌍 **BROWSER** — <https://dash.cloudflare.com> → **Add a site** → masukkan
`dracinverse.cloud` → pilih paket **Free**.

Cloudflare memindai DNS yang ada dan memberi **dua alamat nameserver**.

### 9.2 Ubah nameserver di Rumahweb

🌍 **BROWSER** — masuk ke panel Rumahweb → kelola domain `dracinverse.cloud` →
ganti nameserver dengan dua alamat dari Cloudflare.

Propagasinya 5 menit sampai 24 jam. Situs tetap jalan selama menunggu.

### 9.3 Setelan setelah aktif

🌍 **BROWSER** — di dashboard Cloudflare:

| Menu | Setelan |
|---|---|
| DNS | Record `dracinverse.cloud` dan `www` → awan **oranye** (Proxied) |
| SSL/TLS → Overview | **Full (strict)** |
| SSL/TLS → Edge Certificates | **Always Use HTTPS** → on |
| Security → Bots | **Bot Fight Mode** → on |
| Security → Settings | Security Level → **Medium** |
| Security → WAF → Rate limiting rules | Lihat 9.4 |

### 9.4 Aturan pembatas laju

🌍 **BROWSER** — **Security → WAF → Rate limiting rules → Create rule**:

- Nama: `Batasi pencarian`
- Field `URI Path` → operator `starts with` → nilai `/search`
- Rate: `100` requests per `1 minute`
- Action: `Block`, durasi `10 seconds`

### 9.5 WAJIB — aktifkan `real_ip` di nginx

Setelah Cloudflare aktif, seluruh lalu lintas datang dari IP Cloudflare. Tanpa
langkah ini, semua pengunjung berbagi satu jatah batas laju dan **pengguna
asli yang akan kena 429.**

Kembali ke **langkah 5.3** dan kerjakan sekarang.

### 9.6 Periksa

🌐 **VPS**

```bash
# Tunggu beberapa menit lalu lintas, lalu:
awk '{print $1}' /var/log/nginx/dracinverse-access.log | tail -100 | sort -u | head
```

Harus menampilkan **banyak IP berbeda** (IP asli pengunjung). Kalau yang muncul
hanya IP Cloudflare, langkah 5.3 belum berhasil — ulangi.

---

# Setelah semuanya selesai

### Pemeriksaan menyeluruh

🌐 **VPS**

```bash
echo "--- Debug mati? ---"
cd /var/www/dramaverse && php artisan tinker --execute="echo config('app.debug') ? 'BAHAYA' : 'aman';"

echo "--- Firewall aktif? ---"
ufw status | head -5

echo "--- fail2ban jalan? ---"
fail2ban-client status

echo "--- Layanan tidak terbuka ke internet? ---"
ss -tlnp | grep -E '3306|6379'

echo "--- Pemindai ditolak? ---"
curl -s -o /dev/null -w "wp-admin: %{http_code}\n" https://dracinverse.cloud/wp-admin/
curl -s -o /dev/null -w ".env    : %{http_code}\n" https://dracinverse.cloud/.env

echo "--- Situs hidup? ---"
curl -s -o /dev/null -w "beranda : %{http_code}\n" https://dracinverse.cloud/
```

### Pemeliharaan rutin

**Mingguan** 🌐 **VPS**

```bash
fail2ban-client status
tail -50 /var/www/dramaverse/storage/logs/keamanan-$(date +%F).log
```

**Bulanan** 🌐 **VPS**

```bash
cd /var/www/dramaverse && composer audit
apt update && apt upgrade
```

**Kalau ada pengguna yang salah terblokir** 🌐 **VPS**

```bash
fail2ban-client status nginx-banjir          # lihat daftar IP terblokir
fail2ban-client set nginx-banjir unbanip 1.2.3.4
```

Kalau ini sering terjadi, ambangnya terlalu ketat — naikkan `maxretry` di
`/etc/fail2ban/jail.local`, jangan matikan jail-nya.
