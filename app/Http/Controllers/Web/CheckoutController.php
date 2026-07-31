<?php

namespace App\Http\Controllers\Web;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Membership\MembershipService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout dan tagihan dari sisi pengguna.
 *
 * Controller ini tidak memuat satu pun aturan pembayaran. Ia memvalidasi
 * masukan, memanggil service, dan menerjemahkan kegagalan jadi kalimat yang
 * aman dibaca pengguna — `PaymentException::forUser()` yang memutuskan mana
 * yang boleh ditampilkan dan mana yang cuma boleh masuk log.
 */
/**
 * CATATAN: pembuatan tagihan dipindahkan ke bot Telegram.
 *
 * `store()` dan `retry()` dibuang beserta route-nya. Yang tersisa di web
 * hanyalah MELIHAT tagihan dan membatalkannya — keduanya tidak menyentuh uang.
 *
 * Alasannya ada di PremiumHandler: Trakteer menyambungkan pembayaran ke
 * tagihan lewat pesan yang diketik pendukung, dan nomor tagihan harus ada di
 * tangan pengguna tepat sebelum ia menekan tautannya. Di bot keduanya dalam
 * satu percakapan; di web nomornya tertinggal di tab yang sudah ditutup.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected CheckoutService $checkout,
        protected PaymentGatewayManager $gateways,
        protected MembershipService $membership
    ) {
    }


    /**
     * Halaman satu tagihan.
     *
     * Nomor tagihan memuat bagian acak, tetapi itu bukan pengganti
     * pemeriksaan kepemilikan — nomor bisa tersebar lewat riwayat peramban,
     * pesan yang diteruskan, atau layar yang terlihat orang lain.
     */
    public function show(Request $request, string $number): View
    {
        $invoice = Invoice::query()
            ->with(['transactions.provider', 'plan', 'subscription'])
            ->where('number', $number)
            ->firstOrFail();

        if ($invoice->user_id !== $request->user()->id && ! $request->user()->is_admin) {

            // 404, bukan 403. Menjawab "dilarang" memberi tahu bahwa nomor
            // tagihannya benar ada — itu sudah satu keterangan lebih banyak
            // daripada yang perlu diketahui orang yang bukan pemiliknya.
            throw new NotFoundHttpException;
        }

        $terakhir = $invoice->transactions->sortByDesc('id')->first();

        return view('web.pages.invoice', [
            'invoice'     => $invoice,
            'transaction' => $terakhir,
            'provider'    => $terakhir?->provider,
            'membership'  => $this->membership->status($request->user()),
        ]);
    }


    /** Batalkan tagihan sendiri. */
    public function cancel(Request $request, string $number): RedirectResponse
    {
        $invoice = Invoice::where('number', $number)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($invoice->status !== PaymentStatus::PENDING) {
            return back()->with('error', 'Tagihan ini sudah tidak bisa dibatalkan.');
        }

        app(\App\Services\Payments\InvoiceService::class)
            ->cancel($invoice, 'Dibatalkan oleh pengguna.');

        $this->membership->cancelPendingFor($invoice);

        return redirect()->route('web.membership')->with('status', 'Tagihan dibatalkan.');
    }
}
