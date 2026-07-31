<?php

namespace App\Services\Payments;

use App\Support\Concerns\LogsPaymentEvents;
use App\Enums\PaymentStatus;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Membership\MembershipService;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalan sebuah pembayaran boleh berubah status.
 *
 * Callback provider, verifikasi terjadwal, dan tombol "Verifikasi Manual" di
 * panel admin ketiganya lewat sini. Itu disengaja: aturan idempotensi,
 * pencocokan nominal, penjagaan perpindahan status, dan aktivasi membership
 * ditulis SEKALI. Tiga jalur dengan tiga salinan aturan berarti tiga
 * kesempatan berbeda untuk mengaktifkan membership yang belum dibayar.
 *
 * ## Empat penjagaan, dan kenapa masing-masing ada
 *
 * 1. **Tanda tangan** — diverifikasi di dalam driver, sebelum satu baris pun
 *    dibaca. Callback yang tidak sah adalah seseorang yang mencoba
 *    mengaktifkan membership tanpa membayar.
 * 2. **Kunci baris** — `lockForUpdate()` di dalam transaction. Provider
 *    mengirim ulang callback yang tidak dijawab 200, dan dua callback yang
 *    tiba bersamaan tanpa kunci akan sama-sama lolos pemeriksaan "belum
 *    lunas" lalu mengaktifkan membership dua kali.
 * 3. **Perpindahan status** — `PaymentStatus::canTransitionTo()`. Callback
 *    yang datang terlambat tidak boleh mengembalikan transaksi lunas jadi
 *    menunggu, dan ikut mencabut membership yang sudah aktif.
 * 4. **Nominal** — yang dibayar harus sama dengan yang ditagih. Pembayaran
 *    kurang tidak diaktifkan; ia ditandai perlu diperiksa manual, bukan
 *    ditolak diam-diam, karena uangnya sudah terlanjur masuk.
 */
class PaymentCallbackService
{
    use LogsPaymentEvents;

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected InvoiceService $invoices,
        protected MembershipService $membership
    ) {
    }

    /**
     * Proses satu callback dari provider.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $headers
     *
     * @throws PaymentException
     */
    public function handle(PaymentProvider $provider, array $payload, array $headers = []): PaymentTransaction
    {
        $this->log('info', 'callback.received', [
            'provider' => $provider->slug,
            // Isi payload TIDAK ikut secara utuh: ia bisa memuat nama dan
            // email pembayar. Yang dicatat cukup untuk menelusuri.
            'keys'     => array_keys($payload),
        ]);

        // Tanda tangan diverifikasi DI DALAM driver. Kegagalannya dilempar,
        // bukan dikembalikan sebagai hasil dengan penanda — supaya tidak ada
        // jalan apa pun untuk terus memproses callback yang tidak sah.
        $hasil = $this->gateways->for($provider)->parseCallback($provider, $payload, $headers);

        $transaction = $this->locate($provider, $hasil);

        return $this->apply($transaction, $hasil, 'callback');
    }

    /**
     * Terapkan hasil verifikasi ke transaksi.
     *
     * Dipakai callback, verifikasi terjadwal, DAN verifikasi manual admin.
     *
     * @param  string  $sumber  callback, verify, atau manual — untuk log
     *
     * @throws PaymentException
     */
    public function apply(PaymentTransaction $transaction, PaymentResult $hasil, string $sumber): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $hasil, $sumber) {

            // Kunci barisnya. Dua callback yang tiba bersamaan akan
            // berbaris di sini, bukan sama-sama lolos.
            $tx = PaymentTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |------------------------------------------------------------------
            | Idempotensi
            |------------------------------------------------------------------
            |
            | Status yang sama datang lagi bukan kesalahan — itu perilaku
            | normal provider. Dijawab berhasil tanpa mengerjakan apa pun,
            | supaya provider berhenti mengirim ulang.
            |
            */

            if ($tx->status === $hasil->status) {

                $this->log('info', 'callback.duplicate', [
                    'reference' => $tx->reference,
                    'status'    => $hasil->status->value,
                    'sumber'    => $sumber,
                ]);

                return $tx;
            }

            if (! $tx->status->canTransitionTo($hasil->status)) {

                $this->log('warning', 'callback.illegal_transition', [
                    'reference' => $tx->reference,
                    'dari'      => $tx->status->value,
                    'ke'        => $hasil->status->value,
                    'sumber'    => $sumber,
                ]);

                throw PaymentException::illegalTransition(
                    $tx->status->value,
                    $hasil->status->value
                );
            }

            /*
            |------------------------------------------------------------------
            | Nominal
            |------------------------------------------------------------------
            |
            | Hanya diperiksa saat menyatakan lunas. Toleransi satu rupiah
            | untuk pembulatan biaya layanan di sisi provider.
            |
            */

            if ($hasil->isPaid() && $hasil->amount !== null) {

                if (abs($hasil->amount - (float) $tx->amount) > 1) {

                    $tx->forceFill([
                        'last_error' => 'Nominal tidak cocok: dibayar '
                            .number_format($hasil->amount, 2)
                            .', ditagih '.number_format((float) $tx->amount, 2)
                            .'. Perlu diperiksa manual.',
                        'response_payload' => $hasil->raw,
                    ])->save();

                    $this->log('error', 'callback.amount_mismatch', [
                        'reference' => $tx->reference,
                        'dibayar'   => $hasil->amount,
                        'ditagih'   => (float) $tx->amount,
                    ]);

                    throw PaymentException::amountMismatch((float) $tx->amount, $hasil->amount);
                }
            }

            /*
            |------------------------------------------------------------------
            | Simpan
            |------------------------------------------------------------------
            */

            $tx->forceFill([
                'status'           => $hasil->status,
                'external_id'      => $hasil->externalId ?? $tx->external_id,
                'method'           => $hasil->method ?? $tx->method,
                'response_payload' => $hasil->raw ?: $tx->response_payload,
                'verified_at'      => now(),
                'paid_at'          => $hasil->isPaid() ? ($tx->paid_at ?? now()) : $tx->paid_at,
                'last_error'       => $hasil->isPaid() ? null : $hasil->message,
            ])->save();

            $invoice = $tx->invoice()->lockForUpdate()->first();

            if ($invoice === null) {
                return $tx;
            }

            if ($hasil->isPaid()) {

                $this->invoices->markPaid($invoice);

                // Aktivasi ada di MembershipService, bukan di sini. Lapisan
                // pembayaran tahu uang sudah masuk; berapa lama membership
                // berlaku dan bagaimana perpanjangan dihitung adalah aturan
                // membership, dan menaruhnya di sini berarti dua tempat yang
                // sama-sama memutuskan masa aktif.
                $this->membership->activateFromInvoice($invoice);

            } elseif ($hasil->status !== PaymentStatus::PENDING) {

                $invoice->forceFill(['status' => $hasil->status])->save();

                $this->membership->cancelPendingFor($invoice);
            }

            $this->log('info', 'callback.applied', [
                'reference' => $tx->reference,
                'invoice'   => $invoice->number,
                'status'    => $hasil->status->value,
                'sumber'    => $sumber,
            ]);

            return $tx->refresh();
        });
    }

    /**
     * Temukan transaksi yang dimaksud callback.
     *
     * Dicari lewat referensi kita lebih dulu, baru lewat id provider. Urutan
     * itu penting: referensi kita pasti unik, sedangkan id provider bisa
     * kosong pada callback pertama yang datang sebelum jawaban charge sampai.
     *
     * @throws PaymentException
     */
    private function locate(PaymentProvider $provider, PaymentResult $hasil): PaymentTransaction
    {
        $query = PaymentTransaction::query()->where('payment_provider_id', $provider->id);

        if (filled($hasil->reference)) {

            // Referensi bisa berupa referensi transaksi ATAU nomor invoice —
            // Trakteer hanya bisa membawa nomor invoice di pesan bebas.
            $tx = (clone $query)->where('reference', $hasil->reference)->first()
                ?? (clone $query)
                    ->whereHas('invoice', fn ($q) => $q->where('number', $hasil->reference))
                    ->latest('id')
                    ->first();

            if ($tx !== null) {
                return $tx;
            }
        }

        if (filled($hasil->externalId)) {

            $tx = (clone $query)->where('external_id', $hasil->externalId)->first();

            if ($tx !== null) {
                return $tx;
            }
        }

        $this->log('warning', 'callback.unknown_reference', [
            'provider'  => $provider->slug,
            'reference' => $hasil->reference,
            'external'  => $hasil->externalId,
        ]);

        throw PaymentException::unknownReference(
            $hasil->reference ?? $hasil->externalId ?? '(tanpa referensi)'
        );
    }

}
