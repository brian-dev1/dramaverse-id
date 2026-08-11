<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Alamat kembali untuk form panel admin.
 *
 * Masalah yang diselesaikan: setiap simpan di panel admin selalu mendarat di
 * halaman pertama daftar. Admin yang sedang mengisi episode drama ke-30 —
 * halaman 2 daftar drama, dengan kata kunci pencarian tertentu — kehilangan
 * seluruh posisinya begitu menekan Simpan, dan harus menyusuri ulang dari
 * halaman 1 untuk drama berikutnya. Untuk pekerjaan berulang seperti mengisi
 * episode satu per satu, ongkos itu menumpuk cepat.
 *
 * Maka daftar menitipkan alamatnya sendiri (`?kembali=`) saat mengirim admin
 * ke form, dan form mengembalikannya lewat input tersembunyi supaya redirect
 * setelah simpan mendarat persis di tempat admin tadi berdiri — halaman,
 * pencarian, filter, dan urutannya utuh.
 *
 * Nilainya datang dari URL, jadi tidak boleh dipercaya begitu saja: hanya
 * alamat internal di dalam prefix /admin yang diterima. Selain itu diabaikan,
 * sehingga tautan yang disusupi host asing tidak bisa memakai redirect kita
 * sebagai batu loncatan.
 */
final class AdminReturnUrl
{
    /** Nama parameter, dipakai query string maupun input tersembunyi. */
    public const KEY = 'kembali';

    /** Alamat kembali dari request saat ini, atau null bila tidak ada/tidak aman. */
    public static function current(?Request $request = null): ?string
    {
        $request ??= request();

        return self::sanitize((string) $request->input(self::KEY, ''), $request);
    }

    /**
     * Nilai yang dititipkan sebuah daftar ke form: alamat lengkapnya sendiri.
     *
     * Dipakai di view daftar, mis. route(..., [...$params, ...AdminReturnUrl::param()]).
     */
    public static function param(?Request $request = null): array
    {
        $request ??= request();

        return [self::KEY => $request->fullUrl()];
    }

    /** Membersihkan kandidat alamat; mengembalikan path+query relatif yang aman. */
    public static function sanitize(string $target, ?Request $request = null): ?string
    {
        $target = trim($target);

        if ($target === '') {
            return null;
        }

        $request ??= request();

        $parts = parse_url($target);

        if ($parts === false) {
            return null;
        }

        // Host lain ditolak. Bentuk "//contoh.com/admin" juga tertangkap di
        // sini karena parse_url tetap membacanya sebagai host.
        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (! str_starts_with($path, '/admin')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
