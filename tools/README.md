# Alat Pemeriksa

Dijalankan dari akar project.

```bash
python3 tools/verify-consistency.py      # route, view, komponen, model, PSR-4, CSS
python3 tools/check-php-structure.py $(find app database routes -name '*.php')
```

`verify-consistency.py` memeriksa 10 hal:

1. Nama route yang dipanggil Blade benar-benar terdefinisi
2. Controller + method yang dirujuk route ada
3. View yang dirender controller ada
4. Komponen `<x-...>` yang dipanggil ada
5. Layout yang di-`@extends` ada
6. `$fillable` model cocok dengan kolom migration
7. Foreign key menunjuk tabel yang sudah dibuat lebih dulu
8. Interface repository sudah di-bind
9. Namespace cocok dengan lokasi berkas (PSR-4)
10. `@import` di `app.css` menunjuk berkas yang ada

**Catatan:** ini pemeriksaan statis, bukan pengganti `php artisan route:list`
dan `php -l`. Jalankan keduanya di mesin yang punya PHP.

`check-php-structure.py` melaporkan positif palsu pada berkas config bawaan
Laravel yang memuat `'http://...'` — tanda `//` di dalam string terbaca
sebagai awal komentar.
