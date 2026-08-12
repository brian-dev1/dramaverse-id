<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Services\Membership\MembershipService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Concerns\LogsPaymentEvents;
use App\Support\Telegram\Notice;
use App\Support\Uang;
use App\Support\Waktu;
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

        $pesan = Notice::make('✅', 'Pembayaran berhasil')
            ->lead('Terima kasih. Membership Anda sudah AKTIF.')
            ->rows([
                'Paket'   => $invoice->plan_name,
                'Dibayar' => Uang::invoice($invoice),
                'Tagihan' => $invoice->number,
                'Waktu'   => Waktu::ringkas($invoice->paid_at ?? now()),
                // Tanggal saja memunculkan pertanyaan "jam berapa persisnya
                // aksesnya berhenti?" — pertanyaan yang paling sering datang
                // justru di hari terakhir, saat sudah terlambat menjawabnya.
                'Berlaku sampai' => $langganan?->expired_at !== null
                    ? Waktu::ringkas($langganan->expired_at)
                    : null,
            ])
            ->note('Seluruh episode premium sudah terbuka. Selamat menonton! 🎬');

        $this->kirim($user->telegram_id, $pesan, [
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

        $pesan = Notice::make('💰', 'Pembayaran diterima sebagian')
            ->lead('Membership aktif otomatis begitu jumlahnya cukup.')
            ->rows([
                'Masuk'     => Uang::format($masuk, $invoice->currency),
                'Terkumpul' => Uang::invoice($invoice, 'paid_amount')
                    .' / '.Uang::invoice($invoice)
                    .' ('.$invoice->paidPercent().'%)',
                'Kurang'    => Uang::format($invoice->outstanding(), $invoice->currency),
            ])
            ->text('Kirim sisanya dengan nomor tagihan yang sama:')
            ->code($invoice->number);

        $tombol = [];

        $url = $invoice->latestTransaction()->first()?->checkout_url;

        if (filled($url)) {
            $tombol[] = [['text' => '💳 Bayar sisanya', 'url' => $url]];
        }

        $tombol[] = [['text' => '👤 Cek status', 'callback_data' => 'profile']];

        $this->kirim($user->telegram_id, $pesan, $tombol, 'notify.partial', $invoice);
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
            Notice::make('⚠️', 'Pembayaran tidak selesai')
                ->lead($sebab)
                ->rows([
                    'Tagihan' => $invoice->number,
                    'Status'  => $invoice->status->label(),
                ])
                ->note('Anda bisa memilih paket lagi lewat menu Premium.'),
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
     * @param  array<int,array<int,array<string,string>>>  $tombol
     */
    private function kirim(
        int|string $chatId,
        Notice $pesan,
        array $tombol,
        string $event,
        Invoice $invoice
    ): void {

        try {
            $this->telegram->withRetries(2)->sendMessage(
                $chatId,
                $pesan->render(),
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
