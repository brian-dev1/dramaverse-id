<?php

namespace App\Http\Controllers\Web;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout dan tagihan dari sisi pengguna.
 *
 * Controller ini tidak memuat satu pun aturan pembayaran. Ia memvalidasi
 * masukan, memanggil service, dan menerjemahkan kegagalan jadi kalimat yang
 * aman dibaca pengguna — `PaymentException::forUser()` yang memutuskan mana
 * yang boleh ditampilkan dan mana yang cuma boleh masuk log.
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
     * Mulai pembayaran satu paket.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan'     => ['required', 'string', 'exists:membership_plans,slug'],
            'provider' => ['nullable', 'string'],
        ], [
            'plan.exists' => 'Paket yang Anda pilih sudah tidak tersedia.',
        ]);

        $user = $request->user();

        /*
        |----------------------------------------------------------------------
        | Pencegahan penyalahgunaan
        |----------------------------------------------------------------------
        |
        | Satu pengguna tidak boleh menumpuk tagihan menggantung. Tanpa batas,
        | satu skrip bisa membuat ribuan tagihan dalam semenit — memenuhi tabel
        | sekaligus mengacaukan seluruh angka pendapatan.
        |
        | Yang lama ditawarkan untuk dilanjutkan, bukan sekadar ditolak: orang
        | yang menekan tombol dua kali karena halamannya lambat tidak sedang
        | menyalahgunakan apa pun.
        |
        */

        $menggantung = Invoice::query()
            ->where('user_id', $user->id)
            ->unpaid()
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()))
            ->latest('id')
            ->get();

        if ($menggantung->count() >= (int) config('payment.guard.max_pending_invoices', 3)) {

            return redirect()
                ->route('web.invoice.show', $menggantung->first()->number)
                ->with('error', 'Anda masih punya tagihan yang belum dibayar. '
                    .'Selesaikan atau batalkan dulu sebelum membuat yang baru.');
        }

        $plan = MembershipPlan::where('slug', $data['plan'])->firstOrFail();

        try {
            $provider = filled($data['provider'] ?? null)
                ? $this->gateways->find($data['provider'])
                : $this->gateways->default();

            $transaction = $this->checkout->start($user, $plan, $provider);

        } catch (PaymentException $e) {

            return back()->with('error', $e->forUser());
        }

        // Provider yang punya halaman sendiri: langsung ke sana. Yang manual
        // tidak punya, jadi pengguna diantar ke halaman tagihan yang memuat
        // nomor rekening.
        return $transaction->checkout_url
            ? redirect()->away($transaction->checkout_url)
            : redirect()->route('web.invoice.show', $transaction->invoice->number);
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
            'providers'   => $this->gateways->usable(),
            'membership'  => $this->membership->status($request->user()),
        ]);
    }

    /** Bayar ulang tagihan yang sama dengan provider lain. */
    public function retry(Request $request, string $number): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string'],
        ]);

        $invoice = Invoice::where('number', $number)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $transaction = $this->checkout->retry(
                $invoice,
                $this->gateways->find($data['provider'])
            );

        } catch (PaymentException $e) {

            return back()->with('error', $e->forUser());
        }

        return $transaction->checkout_url
            ? redirect()->away($transaction->checkout_url)
            : back()->with('status', 'Metode pembayaran diperbarui.');
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
