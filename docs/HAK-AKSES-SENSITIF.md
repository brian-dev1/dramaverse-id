# Pembatasan Data Sensitif untuk Akun Admin

Angka pendapatan, pengaturan metode bayar, dan paket langganan hanya boleh
dibuka Super Admin. Admin biasa tetap melihat menunya di sidebar — bergembok,
tidak bisa diklik — supaya tahu fitur itu ada dan tahu harus meminta ke siapa.

Dokumen ini berisi perintah yang perlu dijalankan setelah perubahan ini masuk.
Penanda lokasinya sama dengan `DEPLOY.md`:

| Penanda | Artinya |
|---|---|
| 💻 **LOKAL** | Terminal VS Code di `C:\ProjectDrama\dramaverse-id` (`Ctrl + ~`) |
| 🌐 **VPS** | Terminal VPS lewat SSH |

---

## BAGIAN 1 — Lokal (VS Code)

> 💻 Semua perintah di bagian ini dijalankan di `C:\ProjectDrama\dramaverse-id`.

### 1.1 Daftarkan izin baru ke database

Tiga izin baru (`finance.view`, `payment.manage`) belum ada di database sampai
seeder dijalankan. Selama itu, Moderator masih memegang `membership.manage`
lama dan menu Membership/Langganan **belum** terkunci.

```bash
php artisan db:seed --class=RoleSeeder
```

Seeder ini aman diulang. Ia memperbarui izin peran bawaan dan tidak menyentuh
data lain.

Kalau ada peran buatan sendiri yang masih memegang izin sensitif, seeder akan
menampilkan peringatan berisi nama perannya. Peran kustom sengaja tidak diubah
otomatis — cabut sendiri lewat **Admin → Peran & Izin**.

### 1.2 Bersihkan cache dan bangun ulang aset

```bash
php artisan optimize:clear
npm run build
```

`optimize:clear` wajib: gate izin dan definisi route dibaca dari cache, dan
cache lama masih memakai pemetaan izin yang lama. `npm run build` diperlukan
karena menu bergembok membawa CSS dan JavaScript baru.

### 1.3 Uji sebelum dikirim

```bash
php artisan serve
```

Buka `http://127.0.0.1:8000/admin` lalu periksa dengan dua akun:

| Diuji | Super Admin | Admin biasa (mis. Moderator) |
|---|---|---|
| Kartu "Pendapatan aktif" di Dashboard | tampil | tidak ada |
| Analytics → tab **Keuangan** | tampil | tab tidak ada |
| Analytics → tab Bisnis, baris Pendapatan | tampil | tidak ada |
| Laporan → pilihan jenis laporan | 7 jenis | 4 jenis, tanpa pendapatan/tagihan/membership |
| Sidebar: Membership, Langganan, Metode Bayar, Tagihan | bisa diklik | bergembok, muncul pemberitahuan |
| Ketik URL `/admin/payment/provider` langsung | terbuka | 403 |

Belum punya akun untuk menguji? Buat lewat **Admin → Akun Admin**, beri peran
Moderator, lalu masuk dengan akun itu di jendela penyamaran (incognito) supaya
sesi Super Admin tidak ikut logout.

### 1.4 Kirim ke GitHub

```bash
git add -A
git commit -m "Batasi data keuangan dan pengaturan pembayaran ke Super Admin"
git push origin main
```

---

## BAGIAN 2 — VPS

> 🌐 Jalankan setelah kode sudah ter-push ke GitHub.

### 2.1 Masuk dan deploy

```bash
ssh root@IP_VPS
cd /var/www/dramaverse-id      # sesuaikan dengan folder di server Anda
bash deploy.sh
```

`deploy.sh` sudah menangani pull, composer, `npm run build`,
`optimize:clear`, migrate, dan membangun ulang cache.

### 2.2 Jalankan RoleSeeder — WAJIB, tidak dilakukan deploy.sh

`deploy.sh` sengaja tidak menjalankan seeder apa pun. Jadi langkah ini harus
diketik sendiri, tepat setelah deploy selesai:

```bash
php artisan db:seed --class=RoleSeeder --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`--force` diperlukan karena di produksi Laravel meminta konfirmasi interaktif.

**Kalau langkah ini dilewati:** izin `finance.view` dan `payment.manage` tidak
ada di database, sehingga halaman Tagihan, Metode Bayar, ACC Manual, Log
Pembayaran, Penarikan Video, dan Affiliate akan **403 untuk semua orang**
kecuali Super Admin dan Root Owner — termasuk untuk peran yang seharusnya
masih boleh membukanya.

### 2.3 Periksa hasilnya di server

```bash
php artisan tinker
```

Di dalam tinker:

```php
// Izin baru sudah ada?
App\Models\Permission::whereIn('slug', ['finance.view', 'payment.manage'])->pluck('slug');
// Harusnya: ["finance.view", "payment.manage"]

// Moderator sudah tidak memegang membership.manage?
App\Models\Role::where('slug', 'moderator')->first()->permissions->pluck('slug');
// Harusnya TIDAK ada "membership.manage" di daftarnya

// Uji satu akun admin, ganti ID-nya
$u = App\Models\User::find(2);
[$u->name, $u->hasPermission('finance.view'), $u->hasPermission('drama.manage')];
// Admin biasa: [nama, false, ...] — false pada finance.view

exit
```

### 2.4 Restart worker (bila perlu)

```bash
supervisorctl restart dramaverse-worker:*
```

---

## Kalau ada yang salah

| Gejala | Sebab | Perbaikan |
|---|---|---|
| Semua orang 403 di halaman Tagihan/Metode Bayar | RoleSeeder belum dijalankan | `php artisan db:seed --class=RoleSeeder --force` |
| Menu Membership masih bisa diklik Moderator | Seeder belum jalan, atau cache route lama | seeder lalu `php artisan optimize:clear` |
| Menu bergembok tapi tidak bergaya / klik tidak memunculkan pesan | aset belum dibangun ulang | `npm run build` (lokal) atau ulangi `bash deploy.sh` (VPS) |
| Angka pendapatan masih terlihat admin biasa | cache view lama | `php artisan view:clear` lalu `php artisan view:cache` |
| Admin tanpa peran masih melihat semuanya | tidak akan terjadi lagi untuk izin sensitif, tapi izin lain memang masih terbuka | beri peran yang sesuai lewat **Admin → Akun Admin** |

## Memberi akses keuangan ke orang tertentu

Tidak harus menjadikannya Super Admin. Buat peran baru di **Admin → Peran &
Izin**, lalu centang izin yang diperlukan saja:

- `finance.view` — membaca angka: Tagihan, Log Pembayaran, pendapatan di
  Dashboard/Analytics/Laporan. Tidak bisa mengubah apa pun.
- `payment.manage` — Metode Bayar, ACC Manual, verifikasi transaksi,
  Affiliate, Penarikan Video. Berisi kredensial provider pembayaran.
- `membership.manage` — paket dan langganan: menambah, mengubah harga,
  memperpanjang, membatalkan.

Ketiganya terpisah dengan sengaja. Seseorang bisa diberi `finance.view` untuk
menyusun laporan bulanan tanpa sekaligus memegang kunci ke pengaturan provider
pembayaran.
