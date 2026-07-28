# Karantina Komponen

Berisi komponen Blade dan CSS yang sudah dibuat tetapi belum terpasang ke
halaman mana pun. Disimpan di luar `resources/` agar:

- Blade tidak menemukannya sebagai `<x-...>` (mencegah render tak sengaja)
- Tailwind tidak memindainya (mencegah CSS tak terpakai ikut ter-bundle)

## Jadwal pemasangan

| Folder       | Sprint | Keterangan                          |
|--------------|--------|-------------------------------------|
| `search/`    | 2      | filter lanjutan, sortir, saran      |
| `drama/`     | 3      | cast, galeri, ulasan, rating        |
| `player/`    | 3      | sidebar episode, navigasi pemutar   |
| `profile/`   | 4      | header, menu, perangkat, keamanan   |
| `membership/`| 5      | paket, perbandingan, faktur         |
| `about/`     | 5      | cerita, tim, linimasa               |
| `contact/`   | 5      | formulir, peta, FAQ                 |
| `admin/`     | 6      | sidebar, statistik, tabel, formulir |

## Cara memasang kembali

1. Pindahkan folder ke `resources/views/components/web/`
2. Pindahkan CSS pasangannya ke `resources/css/web/`
3. Tambahkan `@import` di `resources/css/app.css`
4. Perbaiki referensi `route()` di dalamnya agar cocok dengan `routes/web.php`
5. Panggil dari halaman terkait

**Penting:** komponen di sini masih memakai nama route lama.
Periksa terhadap `php artisan route:list` sebelum dipasang.
