<?php

use App\Enums\StorageDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider default (paksa)
    |--------------------------------------------------------------------------
    |
    | Biarkan kosong pada keadaan normal. Provider default diambil dari kolom
    | is_default di tabel storage_providers supaya bisa diganti dari panel
    | admin tanpa deploy.
    |
    | Isi dengan slug provider hanya sebagai jalan keluar darurat: kalau
    | provider default di database bermasalah dan panel admin tidak bisa
    | diakses, nilai di sini menang atas isi database.
    |
    */

    'default' => env('STORAGE_DEFAULT_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Berkas uji Test Connection
    |--------------------------------------------------------------------------
    |
    | Test Connection menulis satu berkas kecil, membacanya kembali, lalu
    | menghapusnya. Direktori di bawah ini akan berisi berkas tersebut untuk
    | beberapa saat. Namanya diberi awalan garis bawah agar mudah dikenali
    | sebagai berkas sistem kalau ada yang tertinggal.
    |
    */

    'probe' => [

        'directory' => env('STORAGE_PROBE_DIR', '_healthcheck'),

        'filename' => 'connection-test',

    ],

    /*
    |--------------------------------------------------------------------------
    | Driver yang diizinkan
    |--------------------------------------------------------------------------
    |
    | Pembatas untuk validasi form admin. Berguna kalau server sengaja hanya
    | mengizinkan sebagian provider — misalnya melarang `local` di produksi
    | karena berkas video tidak boleh menumpuk di disk VPS.
    |
    | Nilai kosong berarti SEMUA driver boleh. Ini harus ditangani eksplisit:
    | `STORAGE_ALLOWED_DRIVERS=` menghasilkan string kosong, bukan nilai yang
    | belum diset, sehingga default argumen env() tidak berlaku. Tanpa
    | penjagaan di bawah, baris kosong di .env justru melarang semua driver.
    |
    */

    'allowed_drivers' => (function (): array {

        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STORAGE_ALLOWED_DRIVERS', ''))
        )));

        return $configured !== [] ? $configured : StorageDriver::values();

    })(),

    /*
    |--------------------------------------------------------------------------
    | Visibility bawaan
    |--------------------------------------------------------------------------
    |
    | `private` dipilih sebagai bawaan dengan sengaja. Bucket video yang
    | terbuka untuk umum berarti siapa pun yang menebak URL bisa mengunduh
    | seluruh katalog tanpa membayar.
    |
    */

    'default_visibility' => env('STORAGE_DEFAULT_VISIBILITY', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Storage Engine
    |--------------------------------------------------------------------------
    */

    'engine' => [

        /*
        | Menolak provider yang belum pernah lulus Test Connection.
        |
        | Bawaannya MATI, dan itu keputusan yang disengaja. Menyalakannya
        | terdengar lebih aman, tapi akibatnya pemasangan baru langsung
        | terkunci: seeder memasang provider lokal sebagai aktif dan default,
        | sementara kolom hasil test-nya masih kosong sampai seseorang
        | menjalankan Test Connection. Seluruh unggahan akan gagal dengan
        | pesan yang benar tetapi pada saat yang paling membingungkan.
        |
        | Engine tetap tidak pernah menguji koneksi sebelum tiap operasi,
        | menyala maupun mati. Dua kali menghubungi penyimpanan untuk satu
        | unggahan menggandakan waktu tunggu tanpa menjamin apa pun —
        | provider bisa rusak di antara pemeriksaan dan operasinya. Yang
        | menentukan adalah operasinya sendiri, dan kegagalannya dicatat.
        |
        | Nyalakan di produksi yang sudah mapan, setelah semua provider
        | terbukti lulus.
        */
        'require_verified_connection' => (bool) env('STORAGE_REQUIRE_VERIFIED', false),

        /*
        | Pencatatan operasi berkas: upload dan delete, berhasil maupun gagal.
        |
        | Sebaiknya jangan dimatikan. Ketika ada berkas yang tidak bisa
        | ditemukan, log inilah satu-satunya tempat yang bisa menjawab
        | pertanyaan "berkas ini pernah sampai ke provider mana".
        */
        'logging' => (bool) env('STORAGE_ENGINE_LOGGING', true),

        /*
        | Channel log. Kosong berarti memakai channel bawaan aplikasi.
        |
        | Isi dengan nama channel di config/logging.php bila operasi berkas
        | ingin dipisahkan dari log aplikasi lainnya.
        */
        'log_channel' => env('STORAGE_ENGINE_LOG_CHANNEL'),

        /*
        | Ekstensi yang SELALU ditolak, apa pun koleksinya.
        |
        | Ini bukan pengulangan dari pembatasan per koleksi. Koleksi membatasi
        | apa yang WAJAR (gambar untuk poster, video untuk episode), sedangkan
        | daftar ini menolak apa yang BERBAHAYA — dan berlaku juga pada
        | `uploadTo()` serta koleksi ASSET yang sengaja tidak dibatasi.
        |
        | Sebabnya konkret. Provider lokal menyimpan di storage/app/public,
        | yang tersaji ke publik lewat symlink public/storage. Banyak
        | konfigurasi Nginx meneruskan apa pun berakhiran .php ke PHP-FPM,
        | termasuk yang ada di bawah /storage. Berkas bernama "shell.jpg.php"
        | melewati pemeriksaan gambar yang naif (namanya memuat .jpg) tetapi
        | ekstensi sebenarnya .php — dan begitu tersimpan, ia bisa dieksekusi.
        |
        | Daftar ini memuat ekstensi yang dieksekusi server, bukan yang
        | dieksekusi peramban. Yang berbahaya di peramban (mis. .html, .svg)
        | ditangani lewat visibility dan Content-Type, bukan di sini.
        */
        'blocked_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
            'phtml', 'phar', 'pht',
            'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
            'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'msi',
            'jsp', 'jspx', 'asp', 'aspx', 'cfm',
            'htaccess', 'htpasswd', 'ini', 'env',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Penanda nilai contoh
    |--------------------------------------------------------------------------
    |
    | Provider yang masih memuat nilai contoh ditolak sebelum satu pun
    | permintaan jaringan dikirim.
    |
    | Alasannya dari kejadian sungguhan: endpoint `ACCOUNT_ID.r2.cloudflare
    | storage.com` yang belum diganti menghasilkan galat "SSL routines::
    | sslv3 alert handshake failure". Pesan itu membuat orang mengejar
    | sertifikat dan firewall selama berjam-jam, padahal host-nya memang
    | tidak pernah ada. Lebih baik ditolak lebih awal dengan pesan yang
    | menyebut field mana yang belum diisi.
    |
    | Pencocokan tidak peka huruf besar-kecil dan bersifat "mengandung".
    | Kalau nilai sungguhan Anda kebetulan tertangkap, buang penandanya
    | dari daftar ini.
    |
    */

    'placeholder_tokens' => [
        'ACCOUNT_ID',
        'YOUR_',
        'YOUR-',
        'ISI_',
        'GANTI_',
        'CHANGEME',
        'CHANGE_ME',
        'TODO',
        'nama-bucket-anda',
        'domain-anda',
        'xxxxx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Antrean unggah (Sprint 7.7)
    |--------------------------------------------------------------------------
    |
    | Pengiriman berkas ke storage provider dipindahkan ke background. Yang
    | masih berjalan di dalam request hanya penerimaan berkas dari peramban —
    | itu memang tidak bisa dipindahkan, karena byte-nya datang lewat request
    | itu sendiri.
    |
    */

    'queue' => [

        /*
        | Koneksi antrean.
        |
        | Bawaannya `uploads` — koneksi yang ditambahkan di config/queue.php,
        | isinya sama dengan `database` kecuali `retry_after` yang jauh lebih
        | panjang. Alasan lengkapnya ada di sana; ringkasnya: dengan
        | `retry_after` bawaan 90 detik, unggahan yang memakan sepuluh menit
        | akan dianggap hilang dan diambil ulang berkali-kali selagi masih
        | berjalan.
        |
        | Dikosongkan berarti mengikuti QUEUE_CONNECTION aplikasi. Boleh, tapi
        | pastikan `retry_after` koneksi itu lebih besar dari
        | UPLOAD_QUEUE_TIMEOUT.
        |
        | Jangan diisi `sync` di produksi: driver sync menjalankan job di dalam
        | request yang sama, sehingga seluruh sprint ini kehilangan gunanya
        | tanpa satu pun pesan galat. Berguna justru saat menguji di lokal.
        */
        'connection' => env('UPLOAD_QUEUE_CONNECTION', 'uploads') ?: null,

        /*
        | Nama antrean.
        |
        | Dipisahkan dari `default` dengan sengaja. Unggahan video berukuran
        | gigabyte bisa menahan satu worker selama puluhan menit; kalau
        | broadcast Telegram dan notifikasi ikut mengantre di belakangnya,
        | keduanya baru terkirim setelah videonya selesai.
        |
        | KONSEKUENSINYA: worker WAJIB mendengarkan antrean ini. Perintahnya
        | harus memuat `--queue=uploads,default` — worker yang hanya
        | mendengarkan `default` akan membiarkan setiap unggahan menggantung
        | di status Pending selamanya, tanpa galat di mana pun.
        */
        'name' => env('UPLOAD_QUEUE', 'uploads'),

        /*
        | Batas waktu satu pekerjaan, dalam detik.
        |
        | Harus lebih besar dari waktu terlama yang masuk akal untuk mengirim
        | satu video ke provider. Satu jam dipilih sebagai bawaan: berkas 4 GB
        | pada 10 Mbps memerlukan sekitar 55 menit.
        |
        | Nilai ini juga harus LEBIH KECIL daripada `retry_after` koneksi
        | antrean di config/queue.php, kalau tidak antrean akan menganggap
        | pekerjaannya hilang dan menjalankannya untuk kedua kali sementara
        | yang pertama masih berjalan — dua unggahan berkas yang sama, ke
        | bucket yang sama, pada saat yang sama.
        */
        'timeout' => (int) env('UPLOAD_QUEUE_TIMEOUT', 3600),

        /*
        | Berapa kali satu pekerjaan dicoba otomatis sebelum ditandai gagal.
        |
        | Bawaannya SATU, dan itu disengaja. Spesifikasi sprint meminta tombol
        | Retry — pengulangan yang diputuskan admin. Mencoba ulang sendiri
        | sampai tiga kali berarti berkas 4 GB dikirim tiga kali ke provider
        | berbayar untuk kegagalan yang sebabnya mungkin kredensial salah, dan
        | itu tidak akan berubah pada percobaan kedua maupun ketiga.
        */
        'tries' => max(1, (int) env('UPLOAD_QUEUE_TRIES', 1)),

        /*
        | Folder berkas staging, relatif terhadap storage_path().
        |
        | Berkas sementara PHP dihapus begitu request berakhir, jadi berkasnya
        | harus dipindahkan ke tempat yang bertahan sampai worker mengambilnya.
        |
        | HARUS berada di bawah storage/ dan TIDAK boleh di bawah
        | storage/app/public — folder itu tersaji ke publik lewat symlink
        | public/storage, dan video berbayar yang sedang menunggu antrean akan
        | bisa diunduh siapa pun yang menebak namanya.
        */
        'staging_dir' => trim((string) env('UPLOAD_QUEUE_STAGING', 'app/upload-queue'), '/'),

        /*
        | Umur maksimum berkas staging milik pekerjaan yang sudah selesai,
        | dalam hari. Dipakai `php artisan upload:prune`.
        */
        'keep_days' => max(1, (int) env('UPLOAD_QUEUE_KEEP_DAYS', 7)),

    ],

    /*
    |--------------------------------------------------------------------------
    | Batas — DIBUANG DI PHASE 12
    |--------------------------------------------------------------------------
    |
    | Blok `limits` berisi `chunk_mb`, `timeout`, dan `max_fallback_attempts`.
    | Ketiganya ditulis di Sprint 7.1 "supaya sprint upload nanti tidak menaruh
    | angka ajaib di tengah kode". Sprint upload datang di 7.5, lalu 7.7, lalu
    | 7.9 — dan tidak satu pun membacanya. Audit Phase 12 menemukan seluruh
    | blok tidak pernah disentuh kode mana pun.
    |
    | `timeout` bahkan sudah tercatat sebagai bug diketahui sejak 7.3: ia
    | tampak mengatur batas waktu S3 padahal tidak berpengaruh apa-apa.
    | Konfigurasi yang berbohong lebih berbahaya daripada konfigurasi yang
    | tidak ada — orang menaikkan angkanya, keadaan tidak berubah, dan mereka
    | mencari penyebabnya di tempat yang salah.
    |
    | Dibuang, bukan disambungkan. Menyambungkan `timeout` ke klien S3 lewat
    | kunci `http` harus diuji langsung terhadap versi SDK yang terpasang;
    | kunci yang salah menggagalkan pembuatan klien dan mematikan SELURUH
    | provider S3 sekaligus. Itu bukan perubahan yang boleh dikirim tanpa
    | pernah dijalankan.
    |
    | `STORAGE_CHUNK_MB`, `STORAGE_TIMEOUT`, dan `STORAGE_MAX_FALLBACK` ikut
    | dibuang dari `.env.example`.
    |
    */

];
