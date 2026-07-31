<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Monitoring\AlertService;

/**
 * Kosakata peringatan khusus pembayaran.
 *
 * Penahan dan pengirimannya ada di `AlertService` milik Phase 9 — kelas ini
 * hanya menyusun judul dan kalimatnya. Pola yang sama dengan
 * `TelegramAlertService`: menyalin logika penahan ke setiap modul berarti
 * beberapa salinan dari satu aturan.
 */
class PaymentAlertService
{
    public function __construct(
        protected AlertService $alerts
    ) {
    }

    /**
     * Nominal callback tidak sama dengan yang ditagih.
     *
     * KRITIS dan melewati penahan. Ini satu-satunya keadaan di seluruh sistem
     * pembayaran yang berarti uang sudah berpindah tetapi tidak ada yang tahu
     * harus diapakan — dan setiap kejadiannya berdiri sendiri, jadi menahan
     * yang kedua berarti satu orang tidak akan pernah dilayani.
     */
    public function amountMismatch(PaymentTransaction $tx, float $dibayar): void
    {
        $this->alerts->critical(
            'payment-mismatch-'.$tx->id,
            'Nominal pembayaran tidak cocok',
            sprintf(
                "Transaksi %s: dibayar %s, ditagih %s.\n\nUang sudah masuk tetapi "
                ."membership TIDAK diaktifkan. Perlu diperiksa manual.",
                $tx->reference,
                number_format($dibayar, 2),
                number_format((float) $tx->amount, 2)
            ),
            ['transaction_id' => $tx->id, 'reference' => $tx->reference]
        );
    }

    /** Callback datang dengan tanda tangan yang tidak sah. */
    public function invalidSignature(string $provider, ?string $ip): void
    {
        $this->alerts->send(
            'payment-invalid-signature-'.$provider,
            'Callback pembayaran ditolak',
            "Callback ke provider `{$provider}` datang dengan tanda tangan yang "
            ."tidak cocok dan TIDAK diproses.\n\nAsal: ".($ip ?: 'tidak diketahui')
            ."\n\nSatu-dua kejadian biasanya salah konfigurasi. Berulang-ulang "
            .'dari alamat yang sama patut dicurigai.',
            ['provider' => $provider, 'ip' => $ip]
        );
    }

    /** Callback menyebut referensi yang tidak dikenal. */
    public function unknownReference(string $provider, string $reference): void
    {
        $this->alerts->send(
            'payment-unknown-ref-'.$provider,
            'Pembayaran tanpa tagihan',
            "Callback dari `{$provider}` menyebut `{$reference}` yang tidak "
            ."cocok dengan tagihan mana pun.\n\nBila ini pembayaran sungguhan, "
            .'payload lengkapnya ada di log dengan peristiwa '
            .'`payment.callback.unmatched` dan bisa dicocokkan manual.',
            ['provider' => $provider, 'reference' => $reference]
        );
    }

    /** Gateway menolak permintaan pembayaran baru. */
    public function gatewayFailed(string $provider, string $sebab): void
    {
        $this->alerts->send(
            'payment-gateway-'.$provider,
            'Gateway pembayaran menolak',
            "Provider `{$provider}` menolak permintaan pembayaran.\n\n{$sebab}"
            ."\n\nSelama ini berlangsung, pengguna tidak bisa berlangganan lewat "
            .'metode tersebut.',
            ['provider' => $provider, 'sebab' => $sebab]
        );
    }

    /** Membership diaktifkan tanpa pembayaran, oleh admin. */
    public function manualActivation(Invoice $invoice, string $oleh): void
    {
        $this->alerts->send(
            'payment-manual-activation',
            'Aktivasi manual',
            "Tagihan {$invoice->number} ({$invoice->plan_name}) diverifikasi manual "
            ."oleh {$oleh}.",
            ['invoice' => $invoice->number, 'oleh' => $oleh]
        );
    }
}
