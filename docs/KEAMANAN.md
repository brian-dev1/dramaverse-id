# Keamanan

Dokumen ini menjelaskan lapisan pertahanan situs, apa yang ditutup masing-masing
lapisan, dan urutan penerapannya di server.

> **Mau langsung menerapkan?** Lihat
> [`PENERAPAN-KEAMANAN.md`](PENERAPAN-KEAMANAN.md) — langkah demi langkah,
> lengkap dengan penanda di mana setiap perintah diketik (lokal atau VPS) dan
> cara memulihkan bila ada yang rusak. Dokumen ini menjelaskan *kenapa*-nya.

---

## Ringkasan temuan audit

Audit dilakukan atas seluruh kode di `app/`, `routes/`, dan `config/`.

### SQL injection — praktis sudah aman

Ini perlu dikatakan lebih dulu karena mengubah prioritas: **tidak ditemukan
satu pun celah SQL injection.**

Seluruh query lewat Eloquent, yang selalu memakai parameter terikat. Dua puluh
tiga pemakaian `selectRaw` / `orderByRaw` diperiksa satu per satu — semuanya
mengirim nilai lewat array binding, bukan menyambungnya ke dalam string.
Pengurutan dari `?sort=` divalidasi dengan daftar putih di keempat tempat yang
menerimanya (`InvoiceController`, `TelegramSyncController`,
`TelegramRetentionController`, `FileManagerService`).

Yang tersisa hanya dua penghalusan, dan keduanya sudah diterapkan:

- **Wildcard `LIKE` tidak diloloskan.** Bukan injeksi — nilainya tetap terikat
  dengan aman — tapi `%` dan `_` punya arti khusus *di dalam* pola `LIKE`, dan
  parameter terikat tidak menyentuh arti itu. Akibatnya: mencari `%` saja
  menghasilkan pola yang cocok dengan seluruh tabel. Satu karakter, pemindaian
  penuh.
- **Panjang kata kunci tidak dibatasi.** Pola `LIKE` sepanjang ribuan karakter
  dibandingkan terhadap setiap baris, dan biayanya tumbuh mengikuti panjangnya.

### DDoS lapis-7 — di sinilah lubang sebenarnya

Seluruh halaman publik berjalan **tanpa satu pun batas laju**: beranda,
`/search`, `/trending`, `/drama/{slug}`, `/episode/{id}`. Semuanya bisa dibuka
tanpa akun, tanpa token, dan masing-masing menyentuh database lalu merender
view.

Ini vektor serangan termurah yang ada. Tidak butuh celah, tidak butuh
kecerdikan — cukup mengulang `GET /trending`. php-fpm punya jumlah worker yang
tetap, biasanya belasan; begitu habis, permintaan berikutnya mengantre lalu
gagal. Yang mati bukan hanya halaman yang diserang: **pembayaran, panel admin,
dan webhook Telegram ikut berhenti** karena mengantre di kolam worker yang sama.

Ditambah tiga hal yang memperbesar biaya per permintaan:
`?page=500000` yang memaksa `OFFSET` belasan juta baris, kata kunci pencarian
tanpa batas panjang, dan tidak adanya `limit_req` di nginx.

### Konfigurasi produksi — jauh lebih baik daripada dugaan awal

Audit awal dokumen ini dibuat dari `.env` di komputer pengembang, dan dari
sana temuannya terlihat gawat: `APP_DEBUG=true`, `DB_USERNAME=root`,
`SESSION_LIFETIME` tujuh hari. Ketiganya **tidak berlaku di server.**

`.env` lokal dan `.env` produksi adalah dua berkas terpisah yang tidak pernah
saling menimpa — `.env` ada di `.gitignore`, jadi ia tidak ikut `git pull`.
Pemeriksaan langsung di server menunjukkan:

| Setelan | Server | Catatan |
|---|---|---|
| `APP_ENV` | `production` | benar |
| `APP_DEBUG` | `false` | benar — ini yang paling penting |
| `SESSION_SECURE_COOKIE` | `true` | benar |
| `SESSION_LIFETIME` | `120` | dua jam, lebih ketat daripada anjuran umum |
| `DB_USERNAME` | `dramaverse_user` | bukan root |

Pelajarannya layak dicatat: **jangan pernah menyimpulkan keadaan produksi dari
berkas di komputer pengembang.** Satu perintah `grep` di server akan
menghemat satu bab kekhawatiran.

Yang benar-benar perlu diubah tinggal tiga baris, dan semuanya sudah
diterapkan: `LOG_LEVEL` dari `debug` ke `warning`, `SESSION_ENCRYPT` ke
`true`, dan `LOG_KEAMANAN_DAYS=30` yang baru.

> **`SESSION_SAME_SITE=none` — jangan diubah.** Panduan keamanan umum
> menganjurkan `lax`, dan untuk situs biasa itu benar. Situs ini tidak biasa:
> Mini App Telegram berjalan di dalam iframe milik `web.telegram.org`, dan
> `lax` menghentikan cookie sesi terkirim dari konteks lintas-situs seperti
> itu — login otomatis gagal 419 tanpa pesan galat. `none` aman di sini
> justru karena `SESSION_SECURE_COOKIE=true` ada; peramban menolak
> `SameSite=None` yang tidak disertai `Secure`. Keduanya pasangan.

### Kernel tertinggal 105 revisi

Ditemukan tanpa dicari, dari pesan `apt` saat memasang ufw: kernel yang
berjalan `6.8.0-31`, sedangkan `6.8.0-136` sudah terpasang di disk dan hanya
menunggu reboot.

Menarik karena arahnya berlawanan dengan seluruh pekerjaan di dokumen ini.
Lapisan yang kita pasang menahan orang di luar; kernel menentukan seberapa
jauh mereka bisa melangkah kalau satu lapis tembus. Selisih 105 revisi berisi
banyak perbaikan *local privilege escalation* — persis jalur dari "berhasil
masuk sebagai www-data" menuju "menguasai server". Sudah diperbaiki dengan
reboot.

### Yang sudah baik sebelum audit

Perlu disebut supaya tidak diubah tanpa sengaja: pembatas laju untuk login
admin, checkout, callback pembayaran, broadcast, dan permintaan drama sudah
terpasang rapi; pengecualian CSRF terbatas dan masing-masing punya penjaga
pengganti; header keamanan sudah dipasang di aplikasi, bukan hanya di nginx;
`.env` benar-benar tidak terlacak git; kredensial storage tidak ikut di-flash
saat validasi gagal.

---

## Lapisan pertahanan

Disusun dari terluar ke terdalam. Yang penting bukan jumlahnya, tapi bahwa
setiap lapis menangkap hal yang **tidak bisa** ditangkap lapis di dalamnya —
dan setiap lapis tetap berguna sendirian bila lapis lain gagal atau hilang.

| # | Lapis | Menahan | Berkas |
|---|-------|---------|--------|
| 0 | Cloudflare (opsional) | Banjir volumetrik sebelum menyentuh VPS | — |
| 1 | Firewall & kernel | Port tertutup, SYN flood, brute force SSH | `deploy/firewall.sh` |
| 2 | fail2ban | Pemblokiran IP yang berulang di lapis paket | `deploy/fail2ban/` |
| 3 | nginx | Batas laju & koneksi, penolakan pemindai sebelum PHP menyala | `deploy/nginx-limits.conf` + `deploy/nginx-dramaverse.conf` |
| 4 | Laravel | Batas laju per route, penolak pemindai, batas input | kode aplikasi |
| 5 | Database | Hak minimum — membatasi kerusakan bila lapis 4 tembus | `deploy/mysql-hak-minimum.sql` |

### Kenapa lapis 3 dan 4 sengaja tumpang tindih

Nginx dan Laravel memblokir jalur pemindai yang sama persis. Itu disengaja,
dan alasannya praktis:

Konfigurasi nginx hidup di `/etc/nginx` — di luar repositori, di luar git.
Ia bisa tertinggal saat pindah VPS, tertimpa panel hosting, atau hilang
bersama server yang dibangun ulang. Middleware `BlockProbeRequests` ikut ke
mana pun kodenya dibawa.

Kalau nginx bekerja, middleware itu tidak pernah kebagian permintaan dan tidak
ada yang terbuang. Kalau nginx tidak ada, ia yang menjaga.

### Yang tidak bisa dilakukan lapis mana pun di dalam VPS

Bila saluran masuk VPS 1 Gbps dibanjiri 5 Gbps, paketnya sudah tersumbat di
jaringan Rumahweb sebelum kernel VPS sempat memutuskan apa pun. Aturan
sebanyak apa pun di dalam server tidak bisa menolak paket yang tidak pernah
sampai.

Untuk itu penyaringnya harus di luar. **Cloudflare paket gratis sudah cukup**
dan ini nilai terbesar per usaha di seluruh dokumen ini — arahkan nameserver
domain ke Cloudflare, nyalakan proxy (awan oranye), lalu:

- Aktifkan **Bot Fight Mode**
- Setel **Security Level** ke *Medium*
- Buat **Rate Limiting Rule**: 100 permintaan/menit per IP ke `/search*`
- Setel SSL/TLS ke **Full (strict)**
- Aktifkan **Always Use HTTPS**

Setelah Cloudflare aktif, blok `set_real_ip_from` di konfigurasi nginx **wajib**
diaktifkan. Alasannya ada di bagian berikut, dan ini bukan detail kecil.

---

## Jebakan: IP asli pengunjung

Ini bagian yang paling mudah salah, dan salahnya tidak menimbulkan galat apa
pun — hanya membuat seluruh pembatas laju berhenti bekerja diam-diam.

**Tanpa proxy di depan.** Nginx meneruskan ke php-fpm lewat FastCGI, dan
`REMOTE_ADDR` yang diterima PHP sudah berisi IP asli pengunjung.
`$request->ip()` benar, semua pembatas laju bekerja. Tidak ada yang perlu
diubah.

**Dengan Cloudflare, tanpa `real_ip` di nginx.** Setiap pengunjung terlihat
datang dari segelintir IP milik Cloudflare. Seluruh pengunjung berbagi satu
jatah 120 permintaan per menit, sementara penyerang mendapat jatah yang sama
besarnya. Pembatas laju berubah dari pelindung menjadi senjata: penyerang
menghabiskan jatah bersama, dan **pengguna asli yang kena 429.**

Perbaikannya: aktifkan blok `set_real_ip_from` di `deploy/nginx-limits.conf`.

> **Jangan** menambahkan `trustProxies` di `bootstrap/app.php` untuk mengatasi
> ini. Dalam susunan FastCGI, nginx tidak mengirim header `X-Forwarded-For`
> sama sekali — jadi agar Laravel mau membacanya, proxy harus dipercaya dengan
> `'*'`. Begitu itu dilakukan, siapa pun bisa mengirim
> `X-Forwarded-For: 1.2.3.4` buatan sendiri dan **lolos dari seluruh batas
> per-IP sekaligus**. Perbaikan di nginx tidak punya masalah ini karena nginx
> menimpa `$remote_addr` hanya untuk pengirim yang alamatnya memang milik
> Cloudflare.

---

## Urutan penerapan

Diurutkan dari yang paling penting. Kalau waktu terbatas, kerjakan 1–3 lebih
dulu; ketiganya menutup sebagian besar risiko dan hanya butuh beberapa menit.

### 1. Periksa `.env` di server — sekarang

```bash
ssh root@server
grep -E '^APP_(ENV|DEBUG)' /var/www/dramaverse/.env
```

Bila menunjukkan `APP_DEBUG=true`, perbaiki sebelum melanjutkan apa pun.
Panduan lengkap di [`deploy/env-produksi.md`](../deploy/env-produksi.md).

```bash
cd /var/www/dramaverse
nano .env          # APP_ENV=production, APP_DEBUG=false, LOG_LEVEL=warning,
                   # SESSION_SECURE_COOKIE=true, SESSION_ENCRYPT=true,
                   # SESSION_LIFETIME=1440
php artisan config:clear && php artisan config:cache
systemctl reload php8.3-fpm
```

### 2. Terapkan kode aplikasi

Tidak ada migrasi, tidak ada perubahan skema.

```bash
cd /var/www/dramaverse
git pull
php artisan config:cache
php artisan route:cache
systemctl reload php8.3-fpm
```

Pastikan direktori log bisa ditulis — berkas `keamanan-*.log` baru:

```bash
chown -R www-data:www-data storage/logs
```

### 3. Cloudflare

Kalau domain belum diarahkan ke Cloudflare, lakukan sekarang. Lihat bagian
sebelumnya untuk setelan yang perlu dinyalakan.

### 4. nginx

Dua berkas, dan **urutannya wajib** — konfigurasi situs merujuk zona yang
dideklarasikan di berkas pertama:

```bash
# 1. Zona pembatas laju (konteks http)
cp deploy/nginx-limits.conf /etc/nginx/conf.d/dracinverse-limits.conf

# 2. Konfigurasi situs
cp deploy/nginx-dramaverse.conf /etc/nginx/sites-available/dramaverse
ln -sf /etc/nginx/sites-available/dramaverse /etc/nginx/sites-enabled/dramaverse
rm -f /etc/nginx/sites-enabled/default

nginx -t          # wajib "syntax is ok" sebelum reload
systemctl reload nginx
```

Bila Cloudflare dipakai, hapus komentar pada blok `set_real_ip_from` di
`dracinverse-limits.conf` lebih dulu.

### 5. Firewall

```bash
nano deploy/firewall.sh    # ubah PORT_SSH bila SSH tidak di port 22
bash deploy/firewall.sh
```

> Buka satu sesi SSH kedua dan biarkan terbuka sebelum menjalankan ini. Bila
> ada yang salah, sesi kedua itu satu-satunya jalan memperbaiki tanpa konsol
> VPS.

### 6. fail2ban

```bash
apt install -y fail2ban
cp deploy/fail2ban/jail.local        /etc/fail2ban/jail.local
cp deploy/fail2ban/filter.d/*.conf   /etc/fail2ban/filter.d/

nano /etc/fail2ban/jail.local   # tambahkan IP Anda ke ignoreip

systemctl enable --now fail2ban
fail2ban-client status
```

### 7. MySQL hak minimum

Terakhir karena paling berisiko memutus situs, dan manfaatnya baru terasa
kalau lapis lain sudah tembus.

```bash
nano deploy/mysql-hak-minimum.sql        # ganti kata sandi penampung
mysql -u root -p < deploy/mysql-hak-minimum.sql
nano /var/www/dramaverse/.env            # DB_USERNAME=dramaverse_app
php artisan config:clear && php artisan config:cache
```

Buka situs dan pastikan normal **sebelum** mengunci akun root.

---

## Memeriksa hasilnya

```bash
# Batas laju bekerja — permintaan terakhir harus 429
for i in $(seq 1 200); do
  curl -s -o /dev/null -w "%{http_code} " https://dracinverse.cloud/trending
done; echo

# Pemindai ditolak — harus 000 (koneksi ditutup) atau 404
curl -s -o /dev/null -w "%{http_code}\n" https://dracinverse.cloud/wp-admin/
curl -s -o /dev/null -w "%{http_code}\n" -A "sqlmap/1.7" https://dracinverse.cloud/

# .env tidak bisa diambil
curl -sI https://dracinverse.cloud/.env | head -1

# Debug mati
curl -s https://dracinverse.cloud/tidak-ada-halaman-ini | grep -c "Whoops\|Stack trace"   # harus 0

# fail2ban aktif dan membaca log
fail2ban-client status nginx-banjir
fail2ban-client status laravel-pemindai

# Layanan pendukung tidak terbuka ke internet
ss -tlnp | grep -E '3306|6379'    # harus 127.0.0.1, bukan 0.0.0.0
```

---

## Pemeliharaan

**Mingguan** — lihat siapa yang diblokir dan kenapa:

```bash
fail2ban-client status
tail -100 /var/www/dramaverse/storage/logs/keamanan-$(date +%F).log
```

**Bulanan** — perbarui dependensi:

```bash
composer audit                 # cek paket dengan kerentanan diketahui
composer update --no-dev
apt update && apt upgrade
```

### Penambalan otomatis

`/etc/apt/apt.conf.d/51-reboot-otomatis` menyalakan reboot otomatis pukul
04:00 setelah pembaruan keamanan yang membutuhkannya.

Ini menutup akar masalah, bukan gejalanya. `unattended-upgrades` sudah aktif
sejak awal dan memang memasang pembaruan keamanan termasuk kernel — tapi ia
tidak pernah reboot. Paket kernel menumpuk di disk sementara yang berjalan
tetap yang lama, dan tidak ada peringatan apa pun karena dari sisi `apt`
semuanya memang sudah terpasang. Begitulah kernel di server ini sempat
tertinggal 105 revisi.

Reboot dilewati bila ada yang sedang login lewat SSH
(`Automatic-Reboot-WithUsers "false"`), supaya server tidak mati di tengah
pekerjaan. Konsekuensinya: kalau sesi SSH sering ditinggal terbuka semalaman,
reboot-nya tidak pernah jalan. Periksa `/var/run/reboot-required` sesekali.

**Bila ada yang salah blokir** — pengguna melapor tidak bisa membuka situs:

```bash
fail2ban-client set nginx-banjir unbanip 1.2.3.4
```

Kalau ini sering terjadi, ambangnya terlalu ketat — naikkan `maxretry` di
`jail.local`, jangan matikan jail-nya.

---

## Pelajaran dari penerapan

Tiga kesalahan terjadi saat lapisan ini dipasang di server sungguhan. Ketiganya
dicatat karena bentuknya akan terulang pada siapa pun yang mengeraskan
konfigurasi ini nanti.

### 1. Penolakan User-Agent kosong membunuh bot

Aturan `if ($http_user_agent = "") { return 444; }` terdengar masuk akal:
peramban selalu mengirim User-Agent. Tapi situs ini dijalankan mesin — server
Telegram tidak mengirimnya, dan banyak gateway pembayaran juga tidak. Selama
tujuh menit setiap webhook dijawab 444 dan bot mati total.

Bentuknya sama persis dengan kesalahan yang sudah pernah terjadi di proyek ini
dan tercatat di `bootstrap/app.php`: callback pembayaran yang terlewat dari
pengecualian CSRF, dijawab 419 sebelum satu baris kode pun jalan, tanpa satu
pun galat aplikasi karena kodenya memang tidak pernah dijalankan.

**Polanya:** penjagaan yang benar secara umum, dipasang di jalur yang justru
dilewati mesin sah. Rusaknya tidak terlihat di log aplikasi karena
permintaannya berhenti sebelum aplikasi menyala.

### 2. `backend = systemd` membuat empat jail buta

Backend itu membaca journald dan mengabaikan `logpath` sepenuhnya. Empat jail
berbasis berkas akan berjalan tanpa pernah membaca apa pun — sementara
`fail2ban-client status` tetap menampilkannya sebagai aktif, dengan
"0 total failed" selamanya. Angka yang mustahil dibedakan dari "tidak ada
serangan hari ini".

**Polanya:** perlindungan yang mati diam-diam lebih buruk daripada tidak ada,
karena ia menghentikan orang dari mencari lagi.

### 3. `systemctl enable --now` tidak memuat konfigurasi baru

`apt install fail2ban` langsung menyalakan servisnya dengan konfigurasi bawaan
— sebelum `jail.local` sempat disalin. `--now` hanya menyalakan servis yang
mati, jadi pada servis yang sudah hidup ia tidak melakukan apa pun. Pakai
`systemctl restart`.

### Yang benar dilakukan, dan patut diulang

Setiap perubahan diverifikasi langsung dari data server, bukan diasumsikan.
Ketiga kesalahan di atas ditemukan dalam hitungan menit karena setiap tahap
diikuti pemeriksaan yang membuktikan — bukan sekadar "tidak ada galat".

Yang paling menentukan: `$remote_addr` diperiksa dari access log **sebelum**
`throttle:publik` diaktifkan. Kalau ternyata ada proxy di depan, pembatas laju
akan menghukum pengguna asli secara massal, dan gejalanya tidak akan terlihat
seperti kesalahan konfigurasi.

---

## Yang masih terbuka

Disebut supaya tidak terlupakan, bukan karena mendesak:

- **Cloudflare belum dipasang.** Ini yang terbesar. Seluruh lapis yang sudah
  aktif berada di dalam VPS, dan tidak satu pun bisa menolak paket yang
  menyumbat saluran masuk sebelum sampai. Untuk DDoS volumetrik sungguhan,
  penyaringnya harus di luar. Paket gratis sudah cukup — lihat tahap 9 di
  `PENERAPAN-KEAMANAN.md`.
- **35 pembaruan paket menunggu, 15 di antaranya pembaruan keamanan.**
  `apt list --upgradable` untuk melihatnya. Kernel sudah diperbarui lewat
  reboot; sisanya paket ruang pengguna.
- **Hak `dramaverse_user` masih `ALL PRIVILEGES` pada satu basis data.**
  Bagian terburuknya sudah tertutup — `GRANT USAGE ON *.*` berarti tidak ada
  `FILE`, jadi SQL injection tidak bisa menulis cangkang PHP ke direktori web.
  Yang tersisa `DROP`/`ALTER`. Perbaikan nyata tapi kecil; lihat tahap 8.
- **Belum ada 2FA untuk panel admin.** Kata sandi tetap satu-satunya penjaga;
  rate limit dan fail2ban memperlambat penebakan, tapi tidak menutupnya.
- **`ngrok.exe`, `*.zip`, dan `setup-local.bat` ada di akar proyek.** Zip sudah
  tercakup `.gitignore`, tapi pastikan `deploy.sh` tidak menyalin akar proyek
  apa adanya ke server.
- **Belum ada pemantauan otomatis.** Serangan saat ini baru ketahuan kalau ada
  yang membaca log. `AlertService` yang sudah ada bisa disambungkan ke jail
  fail2ban lewat `action` untuk mengirim notifikasi Telegram saat ada IP
  diblokir.
