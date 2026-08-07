<?php

namespace App\Telegram\Handlers;

use App\Models\Invoice;
use App\Models\MembershipPlan;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Services\Membership\MembershipService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
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

    /**
     * Awalan callback tombol "Saya sudah bayar".
     *
     * Argumennya id invoice, bukan id transaksi. Tombolnya menempel di pesan
     * yang bisa ditekan berjam-jam kemudian, dan sampai saat itu transaksinya
     * mungkin sudah berganti karena percobaan ulang — nomor tagihan tidak
     * pernah berganti.
     */
    public const PAID = 'paid';

    /** State percakapan saat bot menunggu foto bukti bayar. */
    public const STATE_PROOF = 'PAY_PROOF';

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership,
        protected CheckoutService $checkout,
        protected PaymentGatewayManager $gateways,
        protected UserSessionService $sessions
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

        // Kembali ke daftar paket berarti keluar dari percakapan unggah bukti.
        // Tanpa ini, tombol Batal tidak membatalkan apa pun: foto berikutnya
        // yang dikirim pengguna — foto apa saja — masih akan ditafsirkan
        // sebagai bukti bayar.
        if ($user !== null && $this->sessions->current((int) $user->id) === self::STATE_PROOF) {
            $this->sessions->clear((int) $user->id);
        }

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

            $this->telegram->sendMessage($chatId, implode("\n", $baris));

            // QRIS-nya dikirim ulang, bukan cuma disebut. Pesan lama sudah
            // tergulir jauh ke atas, dan menyuruh orang mencarinya sendiri
            // adalah cara paling mudah membuat tagihan tidak pernah dibayar.
            $provider = $tertunda->latestTransaction()->with('provider')->first()?->provider;

            if ($provider !== null) {
                $this->kirimTagihan($chatId, $tertunda, $provider);
            } else {
                $this->telegram->sendMessage($chatId, 'Tekan tombol di bawah untuk melanjutkan.', [
                    'reply_markup' => ['inline_keyboard' => $this->tombolBayar($tertunda)],
                ]);
            }

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

        $respon = $this->kirimTagihan(
            $chatId,
            $transaction->invoice,
            $provider,
            $transaction->checkout_url
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
    | Konfirmasi bayar
    |--------------------------------------------------------------------------
    */

    /**
     * Pengguna menekan "Saya sudah bayar".
     *
     * Yang terjadi di sini HANYA membuka percakapan unggah bukti. Status
     * tagihan tidak disentuh sedikit pun — bukan karena belum sempat, tetapi
     * karena tombol yang ditekan pengguna bukan bukti bahwa uangnya pindah.
     * Satu-satunya yang boleh melunasi tagihan adalah admin yang melihat
     * mutasi, lewat `PaymentCallbackService` yang sama dengan callback gateway
     * sungguhan.
     */
    public function confirmPaid(array $callback, ?User $user, int $invoiceId): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kirim /start dulu supaya akun Anda dikenali.');

            return;
        }

        $invoice = Invoice::query()
            ->where('id', $invoiceId)
            ->where('user_id', $user->id)
            ->first();

        // Kepemilikan diperiksa, bukan diasumsikan. Callback data datang dari
        // klien dan bisa disusun siapa pun; tanpa penjagaan ini seseorang bisa
        // menempelkan bukti bayar ke tagihan orang lain.
        if ($invoice === null) {
            $this->telegram->sendMessage($chatId, 'Tagihan itu tidak ditemukan di akun Anda.');

            return;
        }

        if ($invoice->status !== \App\Enums\PaymentStatus::PENDING) {

            $this->telegram->sendMessage(
                $chatId,
                'Tagihan <code>'.e($invoice->number).'</code> sudah tidak menunggu '
                .'pembayaran. Cek statusnya di menu Profil.'
            );

            return;
        }

        $this->sessions->set((int) $user->id, self::STATE_PROOF, [
            'invoice_id' => $invoice->id,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", [
                '📸 <b>Kirim bukti pembayaran</b>',
                '',
                'Tagihan: <code>'.e($invoice->number).'</code>',
                'Nominal: Rp '.number_format((float) $invoice->total, 0, ',', '.'),
                '',
                'Kirim <b>foto</b> struk atau tangkapan layar keberhasilan transfer '
                .'ke chat ini. Pastikan nominal dan waktunya terbaca.',
                '',
                'Bukti yang masuk langsung tampil di panel admin. Membership aktif '
                .'setelah admin memeriksanya — biasanya tidak lama.',
            ]),
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => '✖️ Batal', 'callback_data' => 'premium']],
            ]]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pengiriman tagihan
    |--------------------------------------------------------------------------
    */

    /**
     * Kirim tagihan; sebagai gambar QRIS bila providernya punya.
     *
     * Gambar dikirim sebagai BERKAS dari disk, bukan sebagai URL. Telegram
     * yang mengambil sendiri lewat URL memerlukan situs kita bisa dijangkau
     * dari internet — di server yang masih di balik tunnel atau yang
     * `APP_URL`-nya belum benar, itu gagal diam-diam dan pengguna menerima
     * tagihan tanpa QR. Mengunggah berkasnya menghilangkan seluruh
     * ketergantungan itu.
     *
     * Kalau berkasnya raib dari disk, kirimannya turun ke pesan teks biasa,
     * bukan gagal: pengguna sudah punya tagihan, dan menahan instruksinya
     * karena satu gambar hilang hanya menambah satu masalah lagi.
     */
    private function kirimTagihan(
        int|string $chatId,
        Invoice $invoice,
        PaymentProvider $provider,
        ?string $checkoutUrl = null
    ): \App\Services\Telegram\TelegramResponse {

        $tombol = ['reply_markup' => ['inline_keyboard' =>
            $this->tombolBayar($invoice, $checkoutUrl, $provider),
        ]];

        $gambar = $provider->qrisAbsolutePath();

        if ($gambar !== null) {

            try {
                return $this->telegram->sendPhoto(
                    $chatId,
                    new SplFileInfo($gambar),
                    implode("\n", $this->captionQris($invoice, $provider)),
                    $tombol
                );

            } catch (Throwable $e) {

                // Dicatat, lalu diteruskan ke jalur teks. Kegagalan mengirim
                // gambar tidak boleh berarti pengguna tidak menerima nomor
                // tagihannya sama sekali.
                Log::warning('payment.telegram.qris_photo_failed', [
                    'invoice' => $invoice->number,
                    'sebab'   => $e->getMessage(),
                ]);
            }
        }

        return $this->telegram->sendMessage(
            $chatId,
            implode("\n", $this->instruksi($invoice, $provider)),
            $tombol
        );
    }

    /**
     * Keterangan di bawah gambar QRIS.
     *
     * Sengaja pendek. Telegram memotong caption di 1024 karakter, dan yang
     * terpotong selalu bagian akhir — tempat nomor tagihan berada kalau
     * instruksinya ditulis sepanjang versi teks. Yang perlu terbaca sambil
     * memandang layar pembayaran cuma tiga: berapa, ke siapa, dan nomor
     * tagihannya.
     *
     * @return array<int,string>
     */
    private function captionQris(Invoice $invoice, PaymentProvider $provider): array
    {
        $baris = [
            '🧾 <b>Scan QRIS untuk bayar</b>',
            '',
            '<b>Paket</b>: '.e($invoice->plan_name).' — '.(int) $invoice->plan_duration.' hari',
            '<b>Nominal</b>: Rp '.number_format((float) $invoice->total, 0, ',', '.'),
            '<b>Nomor tagihan</b>: <code>'.e($invoice->number).'</code>',
        ];

        if (filled($merchant = $provider->credential('merchant_name'))) {
            $baris[] = '<b>Atas nama</b>: '.e($merchant);
        }

        if ($invoice->due_at !== null) {
            $baris[] = '<b>Bayar sebelum</b>: '.\App\Support\Waktu::lengkapRelatif($invoice->due_at);
        }

        $baris[] = '';
        $baris[] = '⚠️ Isi nominalnya <b>tepat sama</b> dengan angka di atas. '
            .'Selisih membuat pembayaran Anda harus dicocokkan manual dan '
            .'aktivasinya jadi lebih lama.';

        $baris[] = '';
        $baris[] = 'Setelah membayar, tekan <b>Saya sudah bayar</b> lalu kirim '
            .'foto buktinya.';

        return $baris;
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
            $baris[] = '<b>Bayar sebelum</b>: '.\App\Support\Waktu::lengkapRelatif($invoice->due_at);
        }

        /*
        |----------------------------------------------------------------------
        | Driver manual berhenti di sini
        |----------------------------------------------------------------------
        |
        | Sisa fungsi ini seluruhnya tentang Trakteer: saran jumlah unit, dan
        | peringatan agar pesan otomatis berisi nomor tagihan tidak dihapus.
        | Keduanya salah kalau ditempelkan ke transfer bank atau QRIS — tidak
        | ada halaman pembayaran yang punya kolom pesan, dan tidak ada unit
        | yang perlu dihitung.
        |
        | Instruksi yang keliru lebih buruk daripada tidak ada instruksi:
        | pengguna yang mencari "kolom pesan" yang tidak pernah ada akan
        | berhenti dan bertanya, bukan membayar.
        |
        */

        if ($provider instanceof PaymentProvider && $provider->driver->isManual()) {

            if (filled($provider->instruction)) {
                $baris[] = '';
                $baris[] = e($provider->instruction);
            }

            $baris[] = '';
            $baris[] = '⚠️ Bayar <b>tepat</b> sebesar nominal di atas. Selisih '
                .'membuat pembayaran Anda harus dicocokkan manual.';

            $baris[] = '';
            $baris[] = 'Setelah membayar, tekan <b>Saya sudah bayar</b> lalu kirim '
                .'foto buktinya. Membership aktif setelah admin memeriksanya.';

            return $baris;
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

    /**
     * Tombol di bawah tagihan.
     *
     * "Saya sudah bayar" hanya muncul untuk driver yang diverifikasi manusia.
     * Untuk gateway yang punya callback, tombol itu justru merugikan: ia
     * mengundang pengguna melapor manual atas pembayaran yang sebenarnya akan
     * aktif sendiri dalam hitungan detik, dan menciptakan antrean ACC yang
     * tidak perlu ada.
     *
     * @return array<int,array<int,array<string,string>>>
     */
    private function tombolBayar(Invoice $invoice, ?string $url = null, ?PaymentProvider $provider = null): array
    {
        $url ??= $invoice->latestTransaction()->first()?->checkout_url;

        $provider ??= $invoice->latestTransaction()->with('provider')->first()?->provider;

        $tombol = [];

        if (filled($url)) {
            $tombol[] = [['text' => '💳 Bayar sekarang', 'url' => $url]];
        }

        if ($provider?->driver->isManual()) {
            $tombol[] = [[
                'text'          => '✅ Saya sudah bayar',
                'callback_data' => self::PAID.':'.$invoice->id,
            ]];
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
                .($aktif?->expired_at ? ' sampai '.\App\Support\Waktu::lengkap($aktif->expired_at) : '')
                .'. Membeli lagi menambah masa aktif, bukan menggantinya.',

            'expired' => 'Status Anda: <b>Kedaluwarsa</b>. Perpanjang untuk membuka '
                .'episode premium lagi.',

            default => 'Status Anda: <b>Gratis</b>. Episode premium belum bisa dibuka.',
        };
    }
}