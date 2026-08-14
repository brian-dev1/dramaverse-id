<?php

namespace App\Http\Controllers\Web;

use App\Enums\PaymentRegion;
use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Services\Membership\MembershipService;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\TelegramDeepLink;
use App\Support\Uang;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Etalase harga — layar memilih paket, pindahan dari bot.
 *
 * ## Yang pindah dan yang tidak
 *
 * Yang pindah ke sini hanya **melihat harga**. Deretan tombol inline di
 * Telegram harus dibaca satu per satu dan tidak pernah bisa memperlihatkan
 * tujuh paket sekaligus; satu layar web bisa, lengkap dengan harga per
 * harinya, sehingga orang bisa membandingkan sebelum memutuskan.
 *
 * Yang **tidak** pindah: tagihan, QRIS, bukti bayar, dan aktivasinya. Tombol
 * di setiap kartu hanyalah tautan `t.me/<bot>?start=vip_<id>` — menekannya
 * mengembalikan orang ke chat bot, dan `PremiumHandler::buy()` melanjutkan
 * persis dari titik yang sama seperti sebelumnya.
 *
 * Alasannya ada di PremiumHandler: Trakteer menyambungkan pembayaran ke
 * tagihan lewat nomor yang harus ada di tangan pengguna tepat sebelum ia
 * membayar, dan di web nomor itu tertinggal di tab yang sudah ditutup.
 *
 * ## Kenapa halaman ini tetap terbuka tanpa login
 *
 * Harga adalah hal pertama yang dicari orang yang belum punya akun. Menuntut
 * login lebih dulu berarti menyuruh orang mendaftar untuk sesuatu yang belum
 * tentu ia beli.
 */
class MembershipController extends Controller
{
    public function __construct(
        protected MembershipService $membership,
        protected PaymentGatewayManager $gateways
    ) {
    }

    public function index(Request $request): View
    {
        $wilayah = $this->wilayahTersedia();

        $terpilih = $this->wilayahTerpilih($request, $wilayah);

        $siap = $this->siap($terpilih);

        $plans = $terpilih === null
            ? collect()
            : $this->kartu($this->paketBerbayar($terpilih), $siap);

        $subscriptions = collect();

        $status = null;

        if (Auth::check()) {

            $subscriptions = Auth::user()
                ->subscriptions()
                ->with('plan')
                ->latest()
                ->take(10)
                ->get();

            $status = $this->membership->status(Auth::user());
        }

        return view('web.pages.membership', [
            'plans'         => $plans,
            'wilayah'       => $wilayah,
            'terpilih'      => $terpilih,
            'siap'          => $siap,
            'subscriptions' => $subscriptions,
            'status'        => $status,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Data kartu
    |--------------------------------------------------------------------------
    */

    /**
     * Ubah baris paket menjadi kartu siap tampil.
     *
     * ## Kenapa harga per hari dihitung di sini
     *
     * "Rp 180.000" dan "Rp 3.000" tidak bisa dibandingkan langsung — yang satu
     * setahun, yang satu sehari. Yang membuat keduanya sebanding adalah harga
     * per harinya, dan itulah angka yang sebenarnya dicari orang saat
     * memandang daftar paket. Dihitung, bukan disimpan: ia turunan dari harga
     * dan durasi, dan kolom yang menyimpan turunan cepat atau lambat berbeda
     * dari sumbernya.
     *
     * ## Badge
     *
     * Kolom `badge` yang diisi admin selalu menang. Bila kosong, dua label
     * dihitung sendiri: HEMAT untuk harga per hari termurah, TERLARIS untuk
     * paket dengan langganan terbanyak. Keduanya hanya dipasang bila ada
     * pembandingnya — satu-satunya paket di halaman bukan yang "terlaris",
     * ia cuma satu-satunya.
     *
     * @param  Collection<int,MembershipPlan>  $plans
     * @param  bool  $siap  wilayahnya sudah punya metode pembayaran
     * @return Collection<int,array<string,mixed>>
     */
    private function kartu(Collection $plans, bool $siap = true): Collection
    {
        if ($plans->isEmpty()) {
            return collect();
        }

        $perHari = fn (MembershipPlan $p) => (float) $p->price / max(1, (int) $p->duration);

        // Label HEMAT dan TERLARIS dibandingkan antar paket BERDURASI saja.
        // Paket seumur hidup selalu memenangkan hitungan per hari — bagi
        // berapa pun dengan hari yang tak terbatas hasilnya mendekati nol —
        // jadi memasukkannya berarti label itu tidak pernah berpindah.
        $berdurasi = $plans->reject(fn (MembershipPlan $p) => $p->isLifetime());

        // Dibandingkan sebagai string berformat, bukan sebagai float. Dua
        // paket yang tampil sama-sama "Rp 1.000/hari" tidak boleh yang satu
        // mendapat label HEMAT hanya karena selisih pecahan yang tidak pernah
        // terlihat di layar.
        $murah = $berdurasi->sortBy($perHari)->first();

        $termurah = $murah !== null
            ? Uang::format($perHari($murah), $murah->currency)
            : null;

        $terlaris = $plans->count() > 1
            ? $this->paketTerlaris($plans)
            : null;

        return $plans->values()->map(function (MembershipPlan $plan) use ($perHari, $termurah, $terlaris, $plans, $berdurasi, $siap) {

            $hargaHarian = Uang::format($perHari($plan), $plan->currency);

            $badge = filled($plan->badge) ? $plan->badge : match (true) {
                $plan->isLifetime() => 'Selamanya',
                $terlaris !== null && $terlaris === (int) $plan->id => 'Terlaris',
                $berdurasi->count() > 1 && $hargaHarian === $termurah => 'Hemat',
                default => null,
            };

            return [
                'nama'   => $plan->name,
                'harga'  => Uang::format($plan->price, $plan->currency),
                // Null untuk paket seumur hidup: "harga per hari" adalah
                // pembagian dengan jumlah hari yang tidak pernah habis, dan
                // angka apa pun yang keluar dari situ mengarang batas yang
                // justru tidak dimiliki paket ini.
                'harian' => $plan->isLifetime() ? null : $hargaHarian,
                'durasi' => $plan->durasi_tampil,
                'tier'   => $this->tier($plan),
                'badge'  => $badge,
                // Null bila TELEGRAM_BOT_USERNAME belum diisi, ATAU bila
                // wilayahnya belum punya metode pembayaran. View merender
                // kartunya tanpa tautan, bukan tombol yang tidak menuju ke
                // mana pun — harganya tetap terbaca, yang hilang cuma
                // kemampuan menekannya.
                'tautan' => $siap ? TelegramDeepLink::buyPlan($plan) : null,
            ];
        });
    }

    /**
     * Tingkat paket, 1 sampai 5, untuk memilih lambangnya.
     *
     * ## Kenapa dari jumlah hari, bukan dari urutan baris
     *
     * Lambang lama dibedakan `nth-child`: paket pertama ungu, kedua hijau,
     * dan seterusnya. Artinya warnanya melekat pada POSISI, bukan pada
     * paketnya — menambah satu paket murah di puncak daftar menggeser warna
     * semua paket di bawahnya, dan pengunjung yang kembali minggu depan
     * menemukan paket yang sama memakai lambang berbeda.
     *
     * Ambangnya tetap dan tidak bergantung pada berapa banyak paket yang ada,
     * sehingga dua wilayah dengan susunan paket berbeda tetap memberi isyarat
     * yang sama: yang berlambang mahkota selalu paket panjang, di mana pun.
     *
     * @return int 1 percikan, 2 bintang, 3 permata, 4 mahkota, 5 tak terhingga
     */
    private function tier(MembershipPlan $plan): int
    {
        if ($plan->isLifetime()) {
            return 5;
        }

        return match (true) {
            $plan->duration >= 365 => 5,
            $plan->duration >= 90  => 4,
            $plan->duration >= 30  => 3,
            $plan->duration >= 7   => 2,
            default                => 1,
        };
    }

    /**
     * Id paket dengan langganan terbanyak, atau null.
     *
     * Satu query pengelompokan untuk seluruh halaman, bukan satu `count()`
     * per kartu — daftar tujuh paket akan berarti tujuh query yang jawabannya
     * bisa didapat sekaligus.
     *
     * Nol langganan tidak pernah dianggap "terlaris". Di pemasangan baru
     * semua paket sama-sama nol, dan yang pertama akan mendapat labelnya
     * hanya karena urutan — pernyataan yang tidak berdasar apa pun.
     *
     * @param  Collection<int,MembershipPlan>  $plans
     */
    private function paketTerlaris(Collection $plans): ?int
    {
        $teratas = Subscription::query()
            ->selectRaw('membership_plan_id, COUNT(*) as jumlah')
            ->whereIn('membership_plan_id', $plans->pluck('id'))
            ->groupBy('membership_plan_id')
            ->orderByDesc('jumlah')
            ->first();

        return $teratas !== null && (int) $teratas->jumlah > 0
            ? (int) $teratas->membership_plan_id
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Wilayah
    |--------------------------------------------------------------------------
    */

    /**
     * Wilayah yang punya daftar harga.
     *
     * ## Kenapa provider tidak lagi jadi syarat
     *
     * Dulu wilayah baru muncul bila paketnya ada DAN metode bayarnya siap.
     * Niatnya benar — tombol yang mengantar ke penolakan lebih buruk daripada
     * tombol yang tidak ada — tetapi ia menyelesaikan masalahnya dengan
     * menyembunyikan seluruh daftar harga, dan itu terlalu mahal.
     *
     * Halaman ini pertama-tama adalah daftar harga. Orang datang untuk
     * mengetahui berapa, sering jauh sebelum ia siap membayar. Menyembunyikan
     * harga Malaysia sampai QRIS-nya siap berarti pengunjung Malaysia melihat
     * harga Rupiah dan menyimpulkan situs ini tidak melayani negaranya —
     * kesimpulan yang jauh lebih merugikan daripada tombol yang belum aktif.
     *
     * Penolakannya tetap dicegah, hanya dipindahkan: `siap()` menentukan
     * apakah kartunya membawa tautan beli. Wilayah yang belum punya provider
     * tetap memperlihatkan harganya, tanpa tombol yang tidak menuju ke mana
     * pun, disertai keterangan kenapa.
     *
     * @return Collection<int,PaymentRegion>
     */
    private function wilayahTersedia(): Collection
    {
        return collect(PaymentRegion::cases())
            ->filter(fn (PaymentRegion $r) => $this->paketBerbayar($r)->isNotEmpty())
            ->values();
    }

    /** Wilayah itu sudah punya metode pembayaran yang bisa dipakai. */
    private function siap(?PaymentRegion $region): bool
    {
        return $region !== null && $this->gateways->usable($region)->isNotEmpty();
    }

    /**
     * Wilayah yang sedang dilihat.
     *
     * Diambil dari query `?wilayah=`, bukan ditebak dari IP atau bahasa
     * peramban. Yang menentukan bukan asal orangnya melainkan alat bayar yang
     * ada di tangannya — orang Indonesia yang bekerja di Johor memegang
     * aplikasi bank Malaysia. Nilai yang tidak dikenal jatuh ke wilayah
     * pertama, bukan menghasilkan halaman kosong.
     *
     * @param  Collection<int,PaymentRegion>  $tersedia
     */
    private function wilayahTerpilih(Request $request, Collection $tersedia): ?PaymentRegion
    {
        if ($tersedia->isEmpty()) {
            return null;
        }

        $diminta = strtoupper(trim((string) $request->query('wilayah')));

        $cocok = $tersedia->first(fn (PaymentRegion $r) => $r->value === $diminta);

        return $cocok ?? $tersedia->first();
    }

    /**
     * Paket satu wilayah, tanpa yang gratis.
     *
     * Paket Rp 0 tidak bisa dibeli: menekan tombolnya membuat tagihan senilai
     * nol yang tidak punya cara dibayar. Ia juga menyesatkan — paket gratis
     * bukan pilihan yang diambil seseorang, melainkan keadaan awal setiap
     * orang yang belum membayar.
     *
     * Dibuang di sini, bukan di basis data: barisnya tetap dibutuhkan sebagai
     * acuan hak akses bawaan.
     *
     * @return Collection<int,MembershipPlan>
     */
    private function paketBerbayar(PaymentRegion $region): Collection
    {
        return $this->membership->plans($region)
            ->reject(fn (MembershipPlan $plan) => $plan->isFree())
            ->sortBy([
                ['sort_order', 'asc'],
                ['duration', 'asc'],
            ])
            ->values();
    }
}
