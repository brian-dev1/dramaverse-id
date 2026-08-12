# Setelan `.env` produksi

Bagian ini hanya memuat kunci yang **berbeda antara lokal dan produksi**, dan
hanya yang menyangkut keamanan. Sisanya ikut `.env.example`.

Yang penting dipahami lebih dulu: `.env` di komputer pengembang dan `.env` di
server adalah dua berkas terpisah yang tidak pernah saling menimpa — `.env`
ada di `.gitignore`, jadi ia tidak ikut `git pull` saat deploy. Konsekuensinya
dua arah: setelan aman yang ditulis di sini tidak akan sampai ke server dengan
sendirinya, dan harus disunting langsung di sana.

```bash
nano /var/www/dramaverse/.env
```

---

## Wajib — situs tidak boleh hidup tanpa ini

```dotenv
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` di produksi adalah kebocoran terbesar yang bisa terjadi tanpa
satu pun celah kode. Setiap galat — dan galat pasti terjadi — menampilkan
halaman Whoops berisi jejak tumpukan lengkap, potongan kode sumber, dan yang
paling parah: **seluruh isi `.env` ditampilkan di panel Environment**. Kata
sandi database, token bot Telegram, kunci API pembayaran, `APP_KEY`, kredensial
storage. Semuanya, di satu halaman, kepada siapa pun yang berhasil memicu galat.

Memicu galat tidak sulit. `?page=abc` di halaman yang mengharapkan angka sudah
cukup. Ini bukan celah yang perlu dicari — ia menawarkan diri.

`APP_ENV=production` mengikutinya karena beberapa perlindungan Laravel
bergantung padanya: `php artisan migrate` menolak berjalan tanpa `--force`, dan
perintah destruktif meminta konfirmasi.

```dotenv
LOG_LEVEL=warning
```

`debug` menuliskan setiap query dan setiap langkah. Di produksi itu membuat
disk penuh dalam hitungan hari, dan disk penuh mematikan situs sama efektifnya
dengan serangan. Ia juga menuliskan isi query ke log — termasuk data pengguna.

---

## Cookie sesi

```dotenv
SESSION_ENCRYPT=true
```

Satu-satunya yang perlu diubah. Sesi disimpan di tabel database; siapa pun
yang bisa membaca tabel itu — lewat SQL injection, cadangan yang bocor, atau
akses baca ke database — bisa membaca isi seluruh sesi aktif apa adanya.
Terenkripsi, isinya tidak berarti apa-apa tanpa `APP_KEY`, yang tidak ada di
database.

Efek sampingnya: seluruh sesi lama menjadi tidak terbaca, jadi semua orang
logout sekali. Hanya sekali, saat setelan ini dinyalakan.

### Yang sudah benar dan tidak boleh diubah

```dotenv
SESSION_SECURE_COOKIE=true    # jangan diubah
SESSION_SAME_SITE=none        # jangan diubah
SESSION_LIFETIME=120          # jangan diubah
```

`SESSION_SECURE_COOKIE=true` menahan cookie sesi supaya tidak pernah terkirim
lewat http biasa. Situs memang sudah memaksa https, tapi paksaan itu berupa
pengalihan — dan pengalihan terjadi *setelah* peramban mengirim permintaan
pertamanya. Tanpa flag ini, permintaan itu membawa cookie sesi dalam keadaan
terbuka.

> ### `SESSION_SAME_SITE=none` — pasangan yang tidak boleh dipisah
>
> Panduan keamanan umum menganjurkan `lax` atau `strict`, dan untuk situs
> biasa itu benar. Situs ini bukan situs biasa: Mini App Telegram berjalan di
> dalam iframe milik `web.telegram.org`. Cookie dengan `SameSite=Lax` tidak
> ikut terkirim dari konteks lintas-situs semacam itu, jadi sesi tidak pernah
> terbaca dan login otomatis gagal 419 — tanpa satu pun pesan galat yang
> menjelaskan kenapa.
>
> `none` aman di sini **justru karena** `SESSION_SECURE_COOKIE=true` ada:
> peramban modern menolak `SameSite=None` yang tidak disertai `Secure`.
> Keduanya bekerja sebagai pasangan. Mengubah salah satunya merusak yang lain,
> dan kerusakannya tidak terlihat di log mana pun.

`SESSION_LIFETIME=120` — dua jam. Lebih ketat daripada anjuran umum (satu
hari), dan itu bagus: laptop admin yang tertinggal terbuka berhenti memegang
panel setelah dua jam. Pengguna Telegram tidak terganggu karena mereka masuk
ulang otomatis lewat tanda tangan Mini App, bukan lewat sesi yang kedaluwarsa.

---

## Database

```dotenv
DB_USERNAME=dramaverse_app
DB_PASSWORD=<hasil openssl rand -base64 32>
```

Menggantikan `root`. Jalankan `deploy/mysql-hak-minimum.sql` lebih dulu untuk
membuat penggunanya, lalu ubah baris ini, lalu `php artisan config:clear`.

Alasan lengkapnya ada di dalam berkas SQL itu. Ringkasnya: ini tidak mencegah
SQL injection, tapi membatasi satu celah dari "server jatuh" menjadi "satu
basis data terdampak".

---

## Log keamanan

```dotenv
LOG_KEAMANAN_DAYS=30
```

Berapa hari log keamanan disimpan sebelum dirotasi. Lebih lama daripada log
biasa karena serangan sering baru disadari berminggu-minggu setelah percobaan
pertamanya, dan log yang sudah dirotasi tidak bisa dibaca ulang.

---

## Setelah menyunting

```bash
cd /var/www/dramaverse
php artisan config:clear
php artisan config:cache
systemctl reload php8.3-fpm
```

`config:cache` wajib. Tanpa itu Laravel membaca `.env` pada setiap permintaan,
dan itu jauh lebih lambat. Tapi perhatikan urutannya: setelah `config:cache`,
perubahan `.env` berikutnya **tidak berpengaruh sampai cache dibersihkan
ulang**. Ini penyebab paling umum dari "sudah saya ubah tapi tidak berubah".

---

## Periksa hasilnya

```bash
# Harus false. Kalau true, berhenti dan perbaiki sebelum melanjutkan.
php artisan tinker --execute="echo config('app.debug') ? 'BAHAYA: debug menyala' : 'aman';"

# Pastikan .env tidak bisa diambil lewat web. Harus 404, bukan isi berkas.
curl -sI https://dracinverse.cloud/.env | head -1
```
