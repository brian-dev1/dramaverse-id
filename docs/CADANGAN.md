# Cadangan & Pemulihan

## Yang dicadangkan

`backup:run` mengambil dump basis data dengan `mysqldump --single-transaction`
— tidak mengunci tabel InnoDB — lalu **memverifikasi** hasilnya dan
**memangkas** yang lama.

Ketiganya satu perintah dengan sengaja: cadangan yang tidak diverifikasi tidak
bisa dipercaya, dan yang tidak dipangkas akan memenuhi disk sampai
aplikasinya sendiri berhenti bekerja.

**Berkas media TIDAK ikut.** Video dan gambar ada di storage provider, yang
punya ketahanannya sendiri. Menyalinnya ke disk VPS setiap malam berarti
memindahkan ratusan gigabyte untuk mendapat salinan yang lebih rapuh.

## Jadwal

Harian pukul 02:30, jam paling sepi. Diatur di `routes/console.php`.

Kegagalannya mengirim peringatan **kritis** yang melewati penahan biasa:
cadangan yang gagal diam-diam adalah cadangan yang dikira ada sampai hari ia
dibutuhkan.

## Manual

```bash
php artisan backup:run
```

Pantau di `/admin/monitoring`.

## Memulihkan

```bash
cd /var/www/dramaverse
php artisan down                      # jangan ada yang menulis saat restore

gunzip -c storage/app/backups/<berkas>.sql.gz | \
  mysql -u <user> -p <database>

php artisan migrate --force           # bila cadangannya lebih lama dari kode
php artisan config:cache
php artisan up
supervisorctl restart all
```

**Uji pemulihannya sebelum Anda membutuhkannya.** Lakukan ke basis data
terpisah:

```bash
mysql -u root -p -e "CREATE DATABASE dramaverse_uji"
gunzip -c storage/app/backups/<berkas>.sql.gz | mysql -u root -p dramaverse_uji
mysql -u root -p dramaverse_uji -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM invoices;"
mysql -u root -p -e "DROP DATABASE dramaverse_uji"
```

Cadangan yang belum pernah dipulihkan adalah cadangan yang belum diketahui
bisa dipulihkan.

## `APP_KEY`

**Cadangkan terpisah, di tempat yang berbeda dari dump.**

Kredensial storage provider dan payment provider terenkripsi dengan kunci itu.
Basis data yang dipulihkan tanpa `APP_KEY` yang sama berarti seluruh
kredensial tidak terbaca dan harus dimasukkan ulang satu per satu.

Menyimpan keduanya di satu tempat juga salah: siapa pun yang mendapat dump
sekaligus mendapat kuncinya.
