<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Admin\ActivityLogger;
use App\Services\Membership\MembershipService;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\InvoiceService;
use App\Services\Payments\PaymentAlertService;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentResult;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Support\Waktu;

/**
 * Tagihan dan transaksi di panel admin.
 *
 * Search, filter, sort, pagination, ekspor, verifikasi manual, aktivasi
 * manual, dan pembatalan manual — semuanya di satu halaman, karena semuanya
 * bekerja pada daftar yang sama dan memisahkannya berarti admin berpindah
 * halaman untuk melanjutkan pekerjaan yang sama.
 *
 * ## Verifikasi manual tetap lewat jalur callback
 *
 * Tombol "Verifikasi Manual" TIDAK mengubah status sendiri. Ia menyusun
 * `PaymentResult` lalu menyerahkannya ke `PaymentCallbackService::apply()` —
 * jalur yang sama dengan callback provider dan verifikasi terjadwal.
 *
 * Karena itu idempotensi, pencocokan nominal, penjagaan perpindahan status,
 * dan aktivasi membership berlaku persis sama. Menulisnya terpisah akan
 * melahirkan jalur keempat yang bisa mengaktifkan membership tanpa melewati
 * penjagaan yang sama.
 */
class InvoiceController extends Controller
{
    /** Daftar tertutup. Nama kolom dari query string tidak pernah masuk orderBy. */
    private const SORTABLE = [
        'id'      => 'id',
        'number'  => 'number',
        'total'   => 'total',
        'status'  => 'status',
        'paid_at' => 'paid_at',
        'due_at'  => 'due_at',
    ];

    public function __construct(
        protected InvoiceService $invoices,
        protected PaymentCallbackService $callbacks,
        protected MembershipService $membership,
        protected PaymentAlertService $alerts
    ) {
    }

    public function index(Request $request): View
    {
        $sort = array_key_exists((string) $request->query('sort'), self::SORTABLE)
            ? (string) $request->query('sort')
            : 'id';

        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $invoices = $this->filtered($request)
            ->with(['user:id,name,telegram_username', 'latestTransaction.provider'])
            ->orderBy(self::SORTABLE[$sort], $dir)
            ->paginate(25)
            ->withQueryString();

        return view('web.pages.admin.invoice', [
            'invoices' => $invoices,
            'stats'    => $this->stats(),
            'statuses' => PaymentStatus::options(),
            'status'   => $request->query('status'),
            'q'        => $request->query('q'),
            'sort'     => $sort,
            'dir'      => $dir,
        ]);
    }

    public function show(string $number): View
    {
        $invoice = Invoice::query()
            ->with(['user', 'plan', 'subscription.plan', 'transactions.provider'])
            ->where('number', $number)
            ->firstOrFail();

        return view('web.pages.admin.invoice-detail', [
            'invoice'      => $invoice,
            'transactions' => $invoice->transactions->sortByDesc('id'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Tindakan manual
    |--------------------------------------------------------------------------
    */

    /**
     * Tandai lunas setelah admin melihat mutasi rekening.
     *
     * Inilah yang membuat driver `manual` berfungsi penuh — dan yang menutup
     * keadaan ketika pembayaran otomatis masuk tetapi callback-nya hilang.
     */
    public function verify(Request $request, int $id): RedirectResponse
    {
        $tx = PaymentTransaction::with('invoice')->findOrFail($id);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->callbacks->apply(
                $tx,
                new PaymentResult(
                    status: PaymentStatus::PAID,
                    reference: $tx->reference,
                    externalId: $tx->external_id,
                    // Nominal sengaja diambil dari tagihan, bukan diisi admin.
                    // Membiarkan admin mengetik angkanya berarti penjagaan
                    // pencocokan nominal bisa dilewati dengan mengetik ulang
                    // angka yang salah.
                    amount: (float) $tx->amount,
                    method: $tx->method ?? 'manual',
                    raw: ['verified_by' => $request->user()->name, 'note' => $data['note'] ?? null],
                ),
                'manual'
            );

        } catch (PaymentException $e) {

            return back()->with('error', $e->getMessage());
        }

        app(ActivityLogger::class)->log('verify', 'invoice', $tx->invoice, [
            'reference' => $tx->reference,
        ]);

        $this->alerts->manualActivation($tx->invoice, $request->user()->name);

        return back()->with('status', 'Pembayaran diverifikasi dan membership diaktifkan.');
    }

    /** Batalkan tagihan beserta langganan pending-nya. */
    public function cancel(Request $request, string $number): RedirectResponse
    {
        $invoice = Invoice::where('number', $number)->firstOrFail();

        if ($invoice->status !== PaymentStatus::PENDING) {
            return back()->with('error', 'Hanya tagihan yang masih menunggu yang bisa dibatalkan.');
        }

        DB::transaction(function () use ($invoice, $request) {

            $this->invoices->cancel(
                $invoice,
                'Dibatalkan admin: '.($request->input('note') ?: 'tanpa keterangan')
            );

            $invoice->transactions()
                ->where('status', PaymentStatus::PENDING->value)
                ->update(['status' => PaymentStatus::CANCELLED->value]);

            $this->membership->cancelPendingFor($invoice);
        });

        app(ActivityLogger::class)->log('cancel', 'invoice', $invoice);

        return back()->with('status', "Tagihan {$invoice->number} dibatalkan.");
    }

    /*
    |--------------------------------------------------------------------------
    | Ekspor
    |--------------------------------------------------------------------------
    */

    /**
     * Unduh hasil penyaringan sebagai CSV.
     *
     * Dialirkan, bukan disusun di memori. Ekspor sepuluh ribu tagihan yang
     * dibangun jadi satu string akan menghabiskan memory_limit sebelum
     * berkasnya terkirim.
     *
     * Baris pertamanya BOM UTF-8: tanpa itu Excel di Windows membaca nama
     * berhuruf non-ASCII sebagai karakter rusak, dan itulah yang membuka
     * berkasnya di sini.
     */
    public function export(Request $request): Response
    {
        $nama = 'invoice-'.now()->format('Ymd-His').'.csv';

        $query = $this->filtered($request)->with('user:id,name');

        return response()->streamDownload(function () use ($query) {

            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Nomor', 'Tanggal', 'Pengguna', 'Paket', 'Durasi (hari)',
                'Subtotal', 'Biaya', 'Total', 'Status', 'Dibayar',
            ]);

            $query->orderBy('id')->chunk(500, function ($rows) use ($out) {

                foreach ($rows as $invoice) {
                    fputcsv($out, [
                        $invoice->number,
                        Waktu::presisi($invoice->created_at),
                        $invoice->user?->name ?? '-',
                        $invoice->plan_name,
                        $invoice->plan_duration,
                        $invoice->subtotal,
                        $invoice->fee,
                        $invoice->total,
                        $invoice->status->label(),
                        Waktu::presisi($invoice->paid_at, '-'),
                    ]);
                }
            });

            fclose($out);

        }, $nama, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    private function filtered(Request $request): Builder
    {
        $status = (string) $request->query('status');

        $q = trim((string) $request->query('q'));

        return Invoice::query()
            ->when(
                PaymentStatus::tryFrom($status) !== null,
                fn (Builder $b) => $b->where('status', $status)
            )
            ->when($q !== '', function (Builder $b) use ($q) {

                $b->where(function (Builder $w) use ($q) {

                    $w->where('number', 'like', "%{$q}%")
                        ->orWhere('plan_name', 'like', "%{$q}%")
                        ->orWhereHas('user', fn (Builder $u) => $u
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('telegram_username', 'like', "%{$q}%"))
                        ->orWhereHas('transactions', fn (Builder $t) => $t
                            ->where('reference', 'like', "%{$q}%")
                            ->orWhere('external_id', 'like', "%{$q}%"));
                });
            });
    }

    /** Angka ringkas untuk kartu statistik. */
    private function stats(): array
    {
        $lunas = Invoice::where('status', PaymentStatus::PAID->value);

        /*
        |----------------------------------------------------------------------
        | Pendapatan dirinci per mata uang
        |----------------------------------------------------------------------
        |
        | Halaman inilah satu-satunya tempat pendapatan mata uang selain
        | Rupiah terlihat utuh. Dashboard dan Analytics sengaja hanya
        | menghitung mata uang pokok — menjumlahkan Rupiah dengan Ringgit
        | menghasilkan bilangan yang bukan keduanya (lihat
        | AnalyticsRepository::mataUangPokok()).
        |
        | Karena itu di sini keduanya dipisah, bukan dijumlahkan. Satu baris
        | per mata uang: apa adanya, tanpa kurs, tanpa asumsi.
        |
        */
        $perMataUang = (clone $lunas)
            ->selectRaw('currency, COALESCE(SUM(total), 0) as jumlah')
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('jumlah', 'currency');

        $perMataUang30h = (clone $lunas)
            ->where('paid_at', '>=', now()->subDays(30))
            ->selectRaw('currency, COALESCE(SUM(total), 0) as jumlah')
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('jumlah', 'currency');

        return [
            'total'       => Invoice::count(),
            'pending'     => Invoice::where('status', PaymentStatus::PENDING->value)->count(),
            'paid'        => (clone $lunas)->count(),
            'per_currency'     => $perMataUang,
            'per_currency_30d' => $perMataUang30h,
            'active'      => DB::table('subscriptions')
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))
                ->count(),
        ];
    }
}
