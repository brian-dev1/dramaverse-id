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
    | Batas
    |--------------------------------------------------------------------------
    |
    | Angka-angka ini belum dipakai di Sprint 7.1 — belum ada satu pun jalur
    | upload yang dibangun. Nilainya disimpan di sini supaya sprint upload
    | nanti tidak menaruh angka ajaib di tengah kode.
    |
    */

    'limits' => [

        // Ukuran potongan multipart upload, dalam megabyte.
        'chunk_mb' => (int) env('STORAGE_CHUNK_MB', 8),

        // Batas waktu satu operasi penyimpanan, dalam detik.
        //
        // BELUM BERPENGARUH. Nilai ini belum disambungkan ke klien S3 —
        // tidak ada kode yang membacanya, sehingga yang berlaku adalah batas
        // waktu bawaan AWS SDK. Disambungkan lewat kunci `http` pada config
        // disk, tapi itu perlu diuji langsung di server terhadap versi SDK
        // yang terpasang; kunci yang salah menggagalkan pembuatan klien dan
        // mematikan SELURUH provider S3 sekaligus.
        'timeout' => (int) env('STORAGE_TIMEOUT', 60),

        // Berapa kali provider berikutnya di rantai dicoba saat gagal.
        'max_fallback_attempts' => (int) env('STORAGE_MAX_FALLBACK', 2),

    ],

];
