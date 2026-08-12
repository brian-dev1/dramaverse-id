<?php

namespace App\Repositories\Web;

use App\Models\Drama;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WebSearchRepository
{
    private const PER_PAGE = 24;

    /**
     * Panjang maksimum kata kunci.
     *
     * Judul drama terpanjang di katalog tidak sampai seratus karakter, jadi
     * batas ini tidak pernah memotong pencarian yang sungguh-sungguh. Yang
     * dipotong adalah kiriman ribuan karakter: `LIKE` dengan pola sepanjang
     * itu dibandingkan terhadap setiap baris tabel, dan biayanya tumbuh
     * mengikuti panjang polanya. Satu permintaan bisa menahan koneksi
     * database berdetik-detik, dan beberapa lusin sekaligus menghabiskan
     * seluruh kolam koneksi tanpa perlu lalu lintas besar sama sekali.
     */
    private const PANJANG_KATA_KUNCI_MAKS = 100;

    /**
     * Kedalaman halaman maksimum.
     *
     * `?page=500000` diterjemahkan menjadi `OFFSET 11999976`, dan MySQL
     * membacanya dengan cara satu-satunya yang ia punya: membaca lalu membuang
     * dua belas juta baris sebelum mengambil dua puluh empat yang diminta.
     * Halaman itu tidak berisi apa-apa — katalognya jauh lebih pendek — tapi
     * harganya tetap dibayar penuh setiap kali diminta.
     *
     * Ini vektor lapis-7 yang murah sekali: satu URL, tanpa login, dan setiap
     * permintaan memaksa pemindaian penuh. Membatasi kedalaman menutupnya
     * tanpa memengaruhi siapa pun — tidak ada pengunjung yang menjelajah
     * sampai halaman lima ratus.
     */
    private const HALAMAN_MAKS = 200;

    /**
     * Pencarian drama dengan filter genre, negara, VIP, dan status.
     *
     * Menerima parameter `q` (dipakai UI) maupun `keyword` (kompatibilitas lama).
     */
    public function search(Request $request): LengthAwarePaginator
    {
        $keyword = $this->kataKunciDari($request);

        $query = Drama::query()
            ->select([
                'id', 'title', 'slug', 'poster', 'gradient', 'country_id',
                'total_episode', 'status', 'views', 'is_vip', 'published_at',
            ])
            ->with([
                'country:id,name,slug,flag_emoji',
                'genres:id,name,slug',
            ])
            ->published();

        // --- Kata kunci ---
        if ($keyword !== '') {
            $pola = '%'.$this->lolosWildcard($keyword).'%';

            $query->where(function (Builder $q) use ($pola) {
                $q->where('title', 'like', $pola)
                    ->orWhere('original_title', 'like', $pola);
            });
        }

        // --- Genre ---
        if ($request->filled('genre')) {
            $query->whereHas(
                'genres',
                fn (Builder $q) => $q->where('genres.slug', $request->string('genre'))
            );
        }

        // --- Negara ---
        if ($request->filled('country')) {
            $query->whereHas(
                'country',
                fn (Builder $q) => $q->where('countries.slug', $request->string('country'))
            );
        }

        // --- Status tayang ---
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // --- Khusus VIP ---
        if ($request->boolean('vip')) {
            $query->where('is_vip', true);
        }

        // --- Urutan ---
        match ($request->get('sort')) {
            'popular' => $query->orderByDesc('views'),
            'oldest'  => $query->orderBy('published_at'),
            default   => $query->orderByDesc('published_at'),
        };

        return $query
            ->paginate(self::PER_PAGE, ['*'], 'page', $this->halamanDari($request))
            ->withQueryString();
    }

    /**
     * Kata kunci yang sudah dibersihkan dan dibatasi panjangnya.
     *
     * `mb_substr`, bukan `substr`: judul Korea dan Mandarin ditulis dalam
     * karakter multibyte, dan memotong per byte membelah satu huruf menjadi
     * dua potongan tak sah di tengah. Hasilnya bukan sekadar judul terpotong
     * — string-nya berhenti menjadi UTF-8 yang valid, dan MySQL menolak
     * seluruh query dengan galat encoding.
     */
    private function kataKunciDari(Request $request): string
    {
        $mentah = (string) ($request->get('q') ?? $request->get('keyword') ?? '');

        return mb_substr(trim($mentah), 0, self::PANJANG_KATA_KUNCI_MAKS);
    }

    /**
     * Loloskan wildcard `LIKE` di dalam kata kunci.
     *
     * ## Ini bukan tentang SQL injection
     *
     * Nilainya sudah masuk sebagai parameter terikat, jadi tidak ada kutip
     * yang bisa lolos dan tidak ada query yang bisa disambung. Yang belum
     * ditangani adalah lapisan lain: `%` dan `_` punya arti khusus *di dalam*
     * pola `LIKE`, dan parameter terikat tidak menyentuh arti itu sama sekali.
     *
     * Akibatnya dua-duanya nyata. Yang ringan: mencari "100%" tidak pernah
     * menemukan judul "100%" karena `%`-nya dibaca sebagai "apa saja". Yang
     * berat: mencari `%` saja menghasilkan pola `%%%` yang cocok dengan
     * setiap baris di tabel — pemindaian penuh yang mengembalikan seluruh
     * katalog, dari satu karakter yang bisa diketik siapa pun.
     *
     * Backslash diloloskan lebih dulu, kalau tidak backslash yang kita
     * tambahkan sendiri ikut jadi bahan pelolosan berikutnya.
     */
    private function lolosWildcard(string $keyword): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $keyword
        );
    }

    /**
     * Nomor halaman yang sudah dijepit ke rentang wajar.
     *
     * Diteruskan ke `paginate()` alih-alih membiarkannya membaca `?page`
     * sendiri, supaya nilai di luar rentang tidak pernah sampai ke `OFFSET`.
     */
    private function halamanDari(Request $request): int
    {
        $halaman = (int) $request->get('page', 1);

        return max(1, min($halaman, self::HALAMAN_MAKS));
    }
}
