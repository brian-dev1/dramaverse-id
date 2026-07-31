<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Services\Membership\MembershipService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Concerns\LogsPaymentEvents;
use Throwable;

/**
 * Memberi tahu pengguna lewat bot bahwa pembayarannya diterima.
 *
 * ## Kenapa ini baru ada sekarang
 *
 * Sebelumnya tidak ada satu pun pemberitahuan setelah pembayaran lunas.
 * Membership benar-benar aktif, kolom `users.is_premium` benar-benar berubah,
 * tetapi pengguna tidak pernah diberi tahu — ia harus menebak, lalu membuka
 * menu Profil untuk memastikannya sendiri.
 *
 * Dari sudut pandang orang yang baru mengirim uang, sistem yang diam tidak
 * bisa dibedakan dari sistem yang gagal. Itu bukan fitur yang kurang, itu
 * kepercayaan yang hilang.
 *
 * ## Dikirim SETELAH transaksi basis data selesai
 *
 * Bukan di dalamnya. Mengirim HTTP ke Telegram di dalam transaction menahan
 * kunci baris selama permintaan jaringan berlangsung — dan bila Telegram
 * lambat, tagihan yang sedang dilunasi ikut terkunci selama itu.
 *
 * ## Kegagalannya tidak pernah membatalkan pembayaran
 *
 * Pembayaran sudah diterima dan membership sudah aktif sebelum method ini
 * dipanggil. Gagal mengirim kabar adalah persoalan yang jauh lebih kecil
 * daripada membatalkan sesuatu yang sudah benar, jadi seluruh kegagalannya
 * ditelan dan hanya dicatat.
 */
class PaymentNotifier
{
    use LogsPaymentEvents;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership
    ) {
    }

    /** Tagihan lunas dan membership aktif. */
    public function paid(Invoice $invoice): void
    {
        $user = $invoice->user;

        if ($user === null || blank($user->telegram_id)) {
            return;
        }

        $langganan = $invoice->subscription()->first();

        $baris = [
            '✅ <b>Pembayaran berhasil!</b>',
            '',
            'Terima kasih. Membership Anda sudah <b>AKTIF</b>.',
            '',
            '<b>Paket</b>: '.e($invoice->plan_name),
            '<b>Dibayar</b>: Rp '.number_format((float) $invoice->total, 0, ',', '.'),
            '<b>Tagihan</b>: <code>'.e($invoice->number).'</code>',
        ];

        if ($langganan?->expired_at !== null) {
            $baris[] = '<b>Berlaku sampai</b>: '.$langganan->expired_at->format('d M Y');
        }

        $baris[] = '';
        $baris[] = 'Seluruh episode premium sudah terbuka. Selamat menonton! 🎬';

        $this->kirim($user->telegram_id, $baris, [
            [['text' => '🔎 Cari Drama', 'callback_data' => 'search']],
            [['text' => '👤 Profil', 'callback_data' => 'profile']],
        ], 'notify.paid', $invoice);
    }

    /**
     * Cicilan masuk, tetapi belum cukup.
     *
     * Ini yang paling sering ditanyakan ke dukungan: uang sudah dikirim,
     * membership belum aktif. Memberitahukan sisanya di sini menghilangkan
     * seluruh percakapan itu.
     */
    public function partial(Invoice $invoice, float $masuk): void
    {
        $user = $invoice->user;

        if ($user === null || blank($user->telegram_id)) {
            return;
        }

        $baris = [
            '💰 <b>Pembayaran diterima sebagian</b>',
            '',
            '<b>Masuk</b>: Rp '.number_format($masuk, 0, ',', '.'),
            '<b>Terkumpul</b>: Rp '.number_format((float) $invoice->paid_amount, 0, ',', '.')
                .' dari Rp '.number_format((float) $invoice->total, 0, ',', '.')
                .' ('.$invoice->paidPercent().'%)',
            '<b>Kurang</b>: Rp '.number_format($invoice->outstanding(), 0, ',', '.'),
            '',
            'Membership aktif otomatis begitu jumlahnya cukup. Kirim sisanya '
                .'dengan nomor tagihan yang sama:',
            '<code>'.e($invoice->number).'</code>',
        ];

        $tombol = [];

        $url = $invoice->latestTransaction()->first()?->checkout_url;

        if (filled($url)) {
            $tombol[] = [['text' => '💳 Bayar sisanya', 'url' => $url]];
        }

        $tombol[] = [['text' => '👤 Cek status', 'callback_data' => 'profile']];

        $this->kirim($user->telegram_id, $baris, $tombol, 'notify.partial', $invoice);
    }

    /** Pembayaran gagal, kedaluwarsa, atau dibatalkan. */
    public function failed(Invoice $invoice, string $sebab): void
    {
        $user = $invoice->user;

        if ($user === null || blank($user->telegram_id)) {
            return;
        }

        $this->kirim(
            $user->telegram_id,
            [
                '⚠️ <b>Pembayaran tidak selesai</b>',
                '',
                '<b>Tagihan</b>: <code>'.e($invoice->number).'</code>',
                '<b>Status</b>: '.e($invoice->status->label()),
                '',
                e($sebab),
                '',
                'Anda bisa memilih paket lagi lewat menu Premium.',
            ],
            [[['text' => '💎 Premium', 'callback_data' => 'premium']]],
            'notify.failed',
            $invoice
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int,string>  $baris
     * @param  array<int,array<int,array<string,string>>>  $tombol
     */
    private function kirim(
        int|string $chatId,
        array $baris,
        array $tombol,
        string $event,
        Invoice $invoice
    ): void {

        try {
            $this->telegram->withRetries(2)->sendMessage(
                $chatId,
                implode("\n", $baris),
                $tombol === [] ? [] : ['reply_markup' => ['inline_keyboard' => $tombol]]
            );

            $this->log('info', $event, ['invoice' => $invoice->number]);

        } catch (Throwable $e) {

            // Pembayarannya sudah diterima. Gagal mengirim kabar tidak boleh
            // membatalkan apa pun -- lihat alasannya di docblock kelas.
            $this->log('warning', $event.'.failed', [
                'invoice' => $invoice->number,
                'sebab'   => $e->getMessage(),
            ]);
        }
    }
}
