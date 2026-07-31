<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Membership\MembershipService;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentResult;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mengaktifkan tagihan secara manual, dari baris perintah.
 *
 * ## Kenapa ini ada
 *
 * Uang yang sudah masuk tetapi tidak tersambung ke tagihan mana pun adalah
 * keadaan yang PASTI terjadi pada Trakteer — pendukung yang menghapus nomor
 * tagihan dari kolom pesan menghasilkan pembayaran yang sah tanpa referensi.
 *
 * Panel admin punya tombol Verifikasi Manual untuk itu. Perintah ini ada
 * untuk keadaan yang lebih buruk: panelnya sendiri belum bisa dipakai, atau
 * yang perlu diaktifkan adalah tagihan orang yang sedang menunggu di
 * seberang chat.
 *
 * ## Lewat jalur yang SAMA dengan callback
 *
 * Bukan `UPDATE invoices SET status='paid'`. Diserahkan ke
 * `PaymentCallbackService` supaya seluruh penjagaan tetap berlaku: perpindahan
 * status, idempotensi, aktivasi membership, penyesuaian `users.is_premium`,
 * dan pemberitahuan ke bot.
 *
 * Menulis langsung ke tabel akan melewati semuanya, dan hasilnya tagihan yang
 * lunas tetapi membership yang tidak pernah aktif — persis masalah yang
 * sedang dicoba diperbaiki.
 *
 *     php artisan payment:activate INV-20260801-AB12CD
 */
class PaymentActivate extends Command
{
    protected $signature = 'payment:activate
                            {invoice : Nomor tagihan}
                            {--amount= : Nominal yang benar-benar dibayar. Bawaannya total tagihan}
                            {--force : Lewati konfirmasi}';

    protected $description = 'Aktifkan membership dari tagihan yang pembayarannya sudah masuk';

    public function __construct(
        protected PaymentCallbackService $callbacks,
        protected MembershipService $membership
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $invoice = Invoice::query()
            ->with(['user', 'transactions'])
            ->where('number', strtoupper(trim((string) $this->argument('invoice'))))
            ->first();

        if ($invoice === null) {
            $this->components->error('Tagihan tidak ditemukan.');

            return self::FAILURE;
        }

        if ($invoice->status === PaymentStatus::PAID) {

            $langganan = $invoice->subscription()->first();

            if ($langganan?->status === \App\Enums\SubscriptionStatus::ACTIVE->value
                && $invoice->user?->is_premium
            ) {
                $this->components->info('Tagihan ini sudah lunas dan membership sudah aktif.');

                return self::SUCCESS;
            }

            $this->components->warn(
                'Tagihan sudah lunas, tetapi membership/user belum aktif. Menyinkronkan ulang...'
            );

            $langganan = $this->membership->activateFromInvoice($invoice);

            if ($langganan === null) {
                $this->components->error('Gagal: langganan untuk invoice ini tidak bisa dibuat/ditemukan.');

                return self::FAILURE;
            }

            $this->components->info('Membership sudah disinkronkan.');

            return self::SUCCESS;
        }

        $transaction = $invoice->transactions
            ->where('status', PaymentStatus::PENDING)
            ->sortByDesc('id')
            ->first()
            ?? $invoice->transactions->sortByDesc('id')->first();

        if ($transaction === null) {
            $this->components->error(
                'Tagihan ini tidak punya satu pun transaksi, jadi tidak ada yang '
                .'bisa ditandai lunas. Buat tagihan baru lewat bot.'
            );

            return self::FAILURE;
        }

        $nominal = $this->option('amount') !== null
            ? (float) $this->option('amount')
            : (float) $invoice->total;

        if ($transaction->status !== PaymentStatus::PENDING) {
            $transaction = PaymentTransaction::create([
                'invoice_id'          => $invoice->id,
                'payment_provider_id' => $transaction->payment_provider_id,
                'reference'           => $invoice->number.'-CMD-'.Str::upper(Str::random(6)),
                'amount'              => $nominal,
                'currency'            => $invoice->currency,
                'status'              => PaymentStatus::PENDING,
                'method'              => 'manual',
                'expires_at'          => $invoice->due_at,
            ]);
        }

        $this->components->twoColumnDetail('Tagihan', $invoice->number);
        $this->components->twoColumnDetail('Pengguna', $invoice->user?->name ?? '-');
        $this->components->twoColumnDetail('Paket', $invoice->plan_name);
        $this->components->twoColumnDetail('Akan dicatat', 'Rp '.number_format($nominal, 0, ',', '.'));

        if (! $this->option('force') && ! $this->confirm('Aktifkan membership untuk tagihan ini?', true)) {
            return self::SUCCESS;
        }

        try {
            $this->callbacks->apply(
                $transaction,
                new PaymentResult(
                    status: PaymentStatus::PAID,
                    reference: $transaction->reference,
                    externalId: $transaction->external_id,
                    amount: $nominal,
                    method: $transaction->method ?? 'manual',
                    raw: ['sumber' => 'payment:activate', 'oleh' => 'baris perintah'],
                ),
                'manual'
            );

        } catch (Throwable $e) {
            $this->components->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $invoice->refresh();

        $langganan = $invoice->subscription()->first();

        $this->newLine();
        $this->components->info('Selesai.');

        $this->components->twoColumnDetail('Status tagihan', $invoice->status->label());
        $this->components->twoColumnDetail(
            'Langganan',
            $langganan === null
                ? 'tidak ada'
                : $langganan->status.' sampai '.($langganan->expired_at?->format('d M Y') ?? '-')
        );

        $this->line('        Pengguna sudah menerima pemberitahuan di bot.');

        return self::SUCCESS;
    }
}
