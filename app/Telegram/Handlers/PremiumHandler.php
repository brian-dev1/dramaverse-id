<?php

namespace App\Telegram\Handlers;

use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\Membership\MembershipService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Penawaran paket dan pembuatan tagihan — seluruhnya di dalam bot.
 *
 * ## Kenapa berlangganan pindah ke sini
 *
 * Website tidak lagi menerima pembayaran. Alurnya jadi satu jalan saja:
 * pengguna memilih paket di bot, bot membuat tagihan, bot memberi tautan
 * Trakteer beserta nomor tagihannya.
 *
 * Alasannya praktis. Trakteer menyambungkan pembayaran ke tagihan lewat pesan
 * yang diketik pendukung, dan nomor tagihan itu harus sampai ke tangan
 * pengguna tepat sebelum ia menekan tautannya. Di bot, keduanya ada dalam satu
 * percakapan yang bisa digulir ulang; di website, nomornya tertinggal di tab
 * yang sudah ditutup.
 *
 * ## Yang TIDAK berubah
 *
 * Aturan bisnisnya sama sekali tidak disalin ke sini. Tagihan dibuat lewat
 * `CheckoutService`, provider dipilih `PaymentGatewayManager`, status
 * membership dibaca `MembershipService` — ketiganya service yang sama yang
 * dipakai website sebelum ini. Yang berpindah hanya tampilannya.
 */
class PremiumHandler
{
    /** Awalan callback tombol beli. Lihat EpisodeKeyboard untuk yang lain. */
    public const BUY = 'buy';

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership,
        protected CheckoutService $checkout,
        protected PaymentGatewayManager $gateways
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar paket
    |--------------------------------------------------------------------------
    */

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        $baris = ['💎 <b>Premium</b>', ''];

        $baris[] = $this->statusLine($user);

        /*
        |----------------------------------------------------------------------
        | Tagihan yang belum dibayar didahulukan
        |----------------------------------------------------------------------
        |
        | Menawarkan paket baru kepada orang yang tagihannya masih menggantung
        | menghasilkan dua tagihan untuk satu niat, dan yang kedua membuat
        | pembayaran atas yang pertama tidak pernah cukup.
        |
        */

        $tertunda = $user === null ? null : $this->pendingInvoice($user);

        if ($tertunda !== null) {

            $baris[] = '';
            $baris[] = '🧾 <b>Anda punya tagihan yang belum dibayar</b>';
            $baris[] = '<b>Nomor</b>: <code>'.e($tertunda->number).'</code>';
            $baris[] = '<b>Paket</b>: '.e($tertunda->plan_name);
            $baris[] = '<b>Sisa</b>: Rp '.number_format($tertunda->outstanding(), 0, ',', '.');
            $baris[] = '';
            $baris[] = 'Selesaikan dulu yang ini sebelum memilih paket baru.';

            $this->telegram->sendMessage($chatId, implode("\n", $baris), [
                'reply_markup' => ['inline_keyboard' => $this->tombolBayar($tertunda)],
            ]);

            return;
        }

        $plans = $this->membership->plans();

        if ($plans->isEmpty()) {

            $baris[] = '';
            $baris[] = 'Belum ada paket yang ditawarkan. Coba lagi nanti.';

            $this->telegram->sendMessage($chatId, implode("\n", $baris));

            return;
        }

        $baris[] = '';
        $baris[] = '<b>Paket yang tersedia</b>';

        $tombol = [];

        foreach ($plans as $plan) {

            $harga = 'Rp '.number_format((float) $plan->price, 0, ',', '.');

            $baris[] = '';
            $baris[] = '• <b>'.e($plan->name).'</b> — '.$harga.' / '.(int) $plan->duration.' hari';

            if (filled($plan->description)) {
                $baris[] = '  '.e($plan->description);
            }

            $tombol[] = [[
                'text'          => $plan->name.' — '.$harga,
                'callback_data' => self::BUY.':'.$plan->id,
            ]];
        }

        $baris[] = '';
        $baris[] = 'Pilih paket di bawah. Bot akan membuatkan tagihan beserta '
            .'tautan pembayarannya.';

        $this->telegram->sendMessage($chatId, implode("\n", $baris), [
            'reply_markup' => ['inline_keyboard' => $tombol],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Buat tagihan
    |--------------------------------------------------------------------------
    */

    /**
     * Pengguna menekan tombol salah satu paket.
     *
     * Seluruh penjagaan tetap milik `CheckoutService` — batas tagihan
     * menggantung, paket nonaktif, provider yang belum siap. Handler ini hanya
     * menerjemahkan penolakannya jadi kalimat yang bisa dibaca orang.
     */
    public function buy(array $callback, ?User $user, int $planId): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        // Tagihan menggantung diperiksa lagi di sini, bukan hanya saat daftar
        // ditampilkan: tombol lama tetap menempel di pesan lama, dan yang
        // menekannya beberapa jam kemudian akan melewati pemeriksaan pertama.
        if ($tertunda = $this->pendingInvoice($user)) {

            $this->telegram->sendMessage(
                $chatId,
                "Anda masih punya tagihan <code>".e($tertunda->number)."</code> yang "
                .'belum dibayar. Selesaikan dulu yang itu.',
                ['reply_markup' => ['inline_keyboard' => $this->tombolBayar($tertunda)]]
            );

            return;
        }

        $plan = MembershipPlan::find($planId);

        if ($plan === null || ! $plan->is_active) {
            $this->telegram->sendMessage($chatId, 'Paket itu sudah tidak tersedia.');

            return;
        }

        try {
            $provider = $this->gateways->default();

            $transaction = $this->checkout->start($user, $plan, $provider);

        } catch (PaymentException $e) {

            // Pesan yang aman ditampilkan saja; sisanya sudah masuk log.
            $this->telegram->sendMessage($chatId, $e->forUser());

            return;

        } catch (Throwable $e) {

            Log::error('payment.telegram.checkout_failed', [
                'user_id' => $user->id,
                'plan_id' => $planId,
                'sebab'   => $e->getMessage(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Maaf, tagihan tidak bisa dibuat sekarang. Coba lagi beberapa saat lagi.'
            );

            return;
        }

        $respon = $this->telegram->sendMessage(
            $chatId,
            implode("\n", $this->instruksi($transaction->invoice, $provider)),
            ['reply_markup' => ['inline_keyboard' => $this->tombolBayar($transaction->invoice, $transaction->checkout_url)]]
        );

        // Disimpan supaya bisa dihapus lagi kalau tagihannya nanti dibatalkan
        // otomatis karena basi — lihat `PaymentAutomation::stale()`. Kalau
        // Telegram tidak mengembalikan message_id (jarang, tapi bukan
        // keharusan), pesannya sederhana tidak akan pernah dihapus otomatis;
        // itu lebih aman daripada menyimpan id yang salah.
        if (($messageId = $respon->messageId()) !== null) {

            $transaction->invoice->forceFill([
                'telegram_chat_id'    => $chatId,
                'telegram_message_id' => $messageId,
            ])->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Instruksi pembayaran, lengkap dengan saran jumlah unit.
     *
     * Nomor tagihan diulang dua kali dengan sengaja — sekali sebagai data,
     * sekali sebagai peringatan. Itu satu-satunya penyambung antara uang yang
     * masuk dan tagihan yang menunggu, dan pengguna yang menghapusnya dari
     * kolom pesan Trakteer membuat pembayarannya tidak tersambung ke mana pun.
     *
     * @return array<int,string>
     */
    private function instruksi(Invoice $invoice, $provider): array
    {
        $baris = [
            '🧾 <b>Tagihan dibuat</b>',
            '',
            '<b>Paket</b>: '.e($invoice->plan_name).' — '.(int) $invoice->plan_duration.' hari',
            '<b>Total</b>: Rp '.number_format((float) $invoice->total, 0, ',', '.'),
            '<b>Nomor</b>: <code>'.e($invoice->number).'</code>',
        ];

        if ($invoice->due_at !== null) {
            $baris[] = '<b>Bayar sebelum</b>: '.$invoice->due_at->format('d M Y H:i');
        }

        $saran = method_exists($provider, 'unitSuggestions')
            ? $provider->unitSuggestions((float) $invoice->total)
            : [];

        if ($saran !== []) {

            $baris[] = '';
            $baris[] = '<b>Kirim salah satu:</b>';

            foreach (array_slice($saran, 0, 4) as $u) {

                $baris[] = '• '.$u['jumlah'].' '.e($u['nama'])
                    .' = Rp '.number_format($u['total'], 0, ',', '.')
                    .($u['pas'] ? ' ✅ pas' : '');
            }
        }

        $baris[] = '';
        $baris[] = '⚠️ <b>Jangan hapus pesan otomatisnya.</b> Kolom pesan di halaman '
            .'pembayaran sudah terisi nomor tagihan di atas. Tanpa nomor itu, '
            .'pembayaran Anda tidak tersambung ke tagihan ini dan membership '
            .'tidak aktif otomatis.';

        $baris[] = '';
        $baris[] = 'Boleh dicicil. Setiap pembayaran dijumlahkan, dan membership '
            .'aktif sendiri begitu totalnya cukup. Pantau di menu Profil.';

        return $baris;
    }

    /** @return array<int,array<int,array<string,string>>> */
    private function tombolBayar(Invoice $invoice, ?string $url = null): array
    {
        $url ??= $invoice->latestTransaction()->first()?->checkout_url;

        $tombol = [];

        if (filled($url)) {
            $tombol[] = [['text' => '💳 Bayar sekarang', 'url' => $url]];
        }

        $tombol[] = [['text' => '👤 Cek status di Profil', 'callback_data' => 'profile']];

        return $tombol;
    }

    /**
     * Tagihan yang masih menunggu pembayaran, bila sudah mencapai batas.
     *
     * Batasnya `payment.guard.max_pending_invoices`. Penjagaan ini pindah ke
     * sini dari `CheckoutController::store()` saat pembuatan tagihan
     * dipindahkan ke bot — kalau ikut terbuang bersama controllernya, satu
     * pengguna bisa menekan tombol paket berkali-kali dan menumpuk tagihan
     * yang tidak satu pun pernah dibayar.
     *
     * Bawaannya 1 untuk alur Trakteer: pembayarannya bisa dicicil, dan dua
     * tagihan sekaligus membuat cicilan pengguna terpecah ke tagihan yang
     * salah tanpa ada yang menyadarinya.
     */
    private function pendingInvoice(User $user): ?Invoice
    {
        $batas = max(1, (int) config('payment.guard.max_pending_invoices', 1));

        $menggantung = Invoice::query()
            ->where('user_id', $user->id)
            ->unpaid()
            ->latest('id')
            ->get();

        return $menggantung->count() >= $batas ? $menggantung->first() : null;
    }

    /**
     * Tiga keadaan yang dibedakan: Free, Premium, dan Expired.
     *
     * Expired sengaja tidak disamakan dengan Free. Pengguna yang langganannya
     * habis perlu tahu bahwa ia PERNAH punya akses — memberitahunya "Anda
     * pengguna gratis" adalah jawaban yang terasa salah bagi orang yang baru
     * saja membayar.
     */
    private function statusLine(?User $user): string
    {
        if ($user === null) {
            return 'Kirim /start dulu supaya akun Anda dikenali.';
        }

        $status = $this->membership->status($user);

        $aktif = $this->membership->active($user);

        return match ($status['status']) {

            'premium' => 'Status Anda: <b>Premium aktif</b>'
                .($aktif?->expired_at ? ' sampai '.$aktif->expired_at->format('d M Y') : '')
                .'. Membeli lagi menambah masa aktif, bukan menggantinya.',

            'expired' => 'Status Anda: <b>Kedaluwarsa</b>. Perpanjang untuk membuka '
                .'episode premium lagi.',

            default => 'Status Anda: <b>Gratis</b>. Episode premium belum bisa dibuka.',
        };
    }
}