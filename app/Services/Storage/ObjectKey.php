<?php

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Support\Str;

/**
 * Pembangun dan pembersih object key.
 *
 * Bagian paling rawan di seluruh Storage Engine, dan alasannya bukan
 * kerumitan melainkan akibatnya. Object key ikut disusun dari nilai yang
 * datang dari luar; satu `../` yang lolos berarti berkas bisa ditulis atau
 * dihapus di luar direktori yang dimaksud. Pada provider lokal itu berarti
 * menulis ke mana saja yang bisa dijangkau proses PHP.
 *
 * Karena itu kelas ini tidak pernah mempercayai apa pun: nama berkas dari
 * peramban dibuang seluruhnya dan diganti nama acak, dan setiap segmen
 * direktori diperiksa satu per satu.
 *
 * Tidak menyentuh disk, tidak menyentuh database, tidak menyentuh container —
 * sehingga bisa diuji sendirian.
 */
class ObjectKey
{
    /**
     * Karakter yang diizinkan pada segmen direktori.
     *
     * Sengaja sesempit ini. Spasi, tanda kutip, dan karakter unicode memang
     * bisa disimpan oleh sebagian provider, tetapi menghasilkan URL yang
     * harus di-encode dan sulit ditelusuri di log — dan pada beberapa gateway
     * S3 justru ditolak dengan galat tanda tangan yang menyesatkan.
     */
    /*
     * Awalan garis bawah diizinkan: proyek ini sudah memakai `_healthcheck`
     * untuk berkas uji koneksi, dan konvensi "garis bawah berarti direktori
     * sistem" berguna saat menelusuri isi bucket. Awalan titik TIDAK
     * diizinkan — di situlah `..` berada, dan berkas berawalan titik
     * tersembunyi dari sebagian alat.
     */
    private const SEGMENT_PATTERN = '/^[a-z0-9_][a-z0-9_-]*$/i';

    /** Panjang maksimum satu object key. Batas S3 adalah 1024 byte. */
    private const MAX_KEY_LENGTH = 900;

    /**
     * Bersihkan sebuah path direktori.
     *
     * @throws StorageEngineException bila ada segmen yang tidak sah.
     */
    public static function directory(string $directory): string
    {
        $directory = str_replace('\\', '/', trim($directory));

        // Path absolut tidak pernah sah sebagai object key.
        if (str_starts_with($directory, '/')) {
            throw StorageEngineException::invalidDirectory(
                $directory,
                'tidak boleh dimulai dengan garis miring'
            );
        }

        if (preg_match('/^[A-Za-z]:/', $directory) === 1) {
            throw StorageEngineException::invalidDirectory(
                $directory,
                'tidak boleh berupa path Windows absolut'
            );
        }

        // Byte nol memotong nama berkas di sebagian panggilan sistem, sehingga
        // "aman.txt\0.php" bisa tersimpan sebagai berkas .php.
        if (str_contains($directory, "\0")) {
            throw StorageEngineException::invalidDirectory(
                $directory,
                'memuat byte nol'
            );
        }

        $segments = [];

        foreach (explode('/', $directory) as $segment) {
            $segment = trim($segment);

            // Segmen kosong berarti "//" atau garis miring di ujung: dilewati,
            // bukan ditolak, karena itu kekeliruan penulisan yang tidak berbahaya.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw StorageEngineException::invalidDirectory(
                    $directory,
                    'memuat ".." yang menunjuk keluar dari direktori tujuan'
                );
            }

            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw StorageEngineException::invalidDirectory(
                    $directory,
                    "segmen \"{$segment}\" memuat karakter yang tidak diizinkan"
                );
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Nama berkas baru: acak, dengan ekstensi yang sudah dibersihkan.
     *
     * Nama asli dari peramban TIDAK dipakai sebagai nama tersimpan, dan itu
     * disengaja. Nama asli bisa memuat path, karakter yang tidak sah, atau
     * ekstensi ganda seperti "poster.php.jpg"; menyimpannya hanya memindahkan
     * masalah ke lain hari. Nama aslinya tetap dicatat di StoredFile untuk
     * ditampilkan ke pengguna.
     */
    public static function randomName(?string $extension = null): string
    {
        $name = (string) Str::ulid();

        $extension = self::extension($extension);

        return $extension === null ? $name : $name.'.'.$extension;
    }

    /**
     * Bersihkan sebuah ekstensi. Mengembalikan null bila tidak ada yang sah.
     */
    public static function extension(?string $extension): ?string
    {
        if ($extension === null) {
            return null;
        }

        // Ambil bagian setelah titik terakhir, supaya "tar.gz" maupun
        // ".jpg" sama-sama menghasilkan satu ekstensi.
        $extension = Str::lower(trim($extension, ". \t\n\r\0\x0B"));
        $extension = Str::afterLast($extension, '.');

        if ($extension === '' || preg_match('/^[a-z0-9]{1,12}$/', $extension) !== 1) {
            return null;
        }

        return $extension;
    }

    /**
     * Gabungkan direktori dan nama berkas menjadi satu object key.
     *
     * @throws StorageEngineException
     */
    public static function join(string $directory, string $filename): string
    {
        $directory = self::directory($directory);

        $filename = self::filename($filename);

        $key = $directory === '' ? $filename : $directory.'/'.$filename;

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw StorageEngineException::keyTooLong($key, self::MAX_KEY_LENGTH);
        }

        return $key;
    }

    /**
     * Bersihkan sebuah nama berkas (tanpa direktori).
     *
     * @throws StorageEngineException
     */
    public static function filename(string $filename): string
    {
        $filename = str_replace('\\', '/', trim($filename));

        if (str_contains($filename, "\0")) {
            throw StorageEngineException::invalidFilename($filename, 'memuat byte nol');
        }

        // Buang bagian direktori apa pun: nama berkas tidak boleh memindahkan
        // berkas ke tempat lain.
        $filename = basename($filename);

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw StorageEngineException::invalidFilename($filename, 'kosong atau tidak bermakna');
        }

        $extension = self::extension(pathinfo($filename, PATHINFO_EXTENSION) ?: null);

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        // Slug menjaga nama tetap berupa karakter yang aman di URL sekaligus
        // masih bisa dibaca manusia.
        $stem = Str::slug($stem, '-');

        if ($stem === '') {
            $stem = (string) Str::ulid();
        }

        $stem = Str::limit($stem, 120, '');

        return $extension === null ? $stem : $stem.'.'.$extension;
    }

    /**
     * Direktori dari sebuah object key. String kosong bila di akar.
     */
    public static function directoryOf(string $key): string
    {
        $directory = trim(str_replace('\\', '/', dirname($key)), '/');

        return in_array($directory, ['', '.'], true) ? '' : $directory;
    }

    /**
     * Nama berkas dari sebuah object key.
     */
    public static function basenameOf(string $key): string
    {
        return basename(str_replace('\\', '/', $key));
    }

    /**
     * Periksa object key yang datang dari luar sebelum dipakai pada operasi
     * berkas yang sudah ada (hapus, pindah, salin, ganti nama).
     *
     * Upload membangun key-nya sendiri, tetapi operasi lain menerima key dari
     * pemanggil — dan key itu bisa berasal dari kolom database yang isinya
     * pernah ditulis kode lain.
     *
     * @throws StorageEngineException
     */
    public static function assertSafe(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));

        if ($key === '') {
            throw StorageEngineException::invalidFilename($key, 'object key kosong');
        }

        $directory = self::directoryOf($key);

        // Melewati pembersih yang sama dengan upload: satu jalur aturan,
        // bukan dua yang harus diingat bersamaan.
        return self::join($directory, self::basenameOf($key));
    }
}
