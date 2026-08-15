<?php

namespace App\Telegram\Handlers;

use App\Enums\PaymentRegion;
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
use App\Support\Telegram\Notice;
use App\Support\Waktu;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use Throwable;
use App\Support\Uang;

/**
 * Pembuatan tagihan dan penagihannya — tetap di dalam bot.
 *
 * ## Pembagian tugas sekarang
 *
 * **Website** memajang harga. Satu layar memuat seluruh paket sekaligus,
 * lengkap dengan harga per harinya, dan orang bisa membandingkannya sambil
 * menggulir — sesuatu yang tidak pernah bisa dilakukan deretan tombol inline
 * yang harus dibaca satu per satu.
 *
 * **Bot** menerima pilihannya lewat `?start=vip_<id>` (lihat
 * `TelegramDeepLink::PLAN`), membuat tagihan, mengirim QRIS beserta nomor
 * tagihannya, lalu menerima bukti bayarnya.
 *
 * ## Kenapa pembayarannya TIDAK ikut pindah
 *
 * Trakteer menyambungkan pembayaran ke tagihan lewat pesan yang diketik
 * pendukung, dan nomor tagihan itu harus ada di tangan pengguna tepat sebelum
 * ia menekan tautannya. Di bot keduanya ada dalam satu percakapan yang bisa
 * digulir ulang berhari-hari kemudian; di web, nomornya tertinggal di tab yang
 * sudah ditutup.
 *
 * Karena itu yang berpindah ke website hanya layar memilih harga. Semua yang
 * berikutnya — tagihan, QRIS, bukti bayar, aktivasi — tetap di sini, lewat
 * jalan yang sama persis seperti sebelumnya.
 *
 * ## Yang TIDAK berubah
 *
 * Aturan bisnisnya sama sekali tidak disalin ke sini. Tagihan dibuat lewat
 * `CheckoutService`, provider dipilih `PaymentGatewayManager`, status
 * membership dibaca `MembershipService`.
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
        protected UserSessionService $sessions,
        protected \App\Services\Telegram\ChannelGate $channel
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Antar ke etalase
    |--------------------------------------------------------------------------
    */

    /**
     * `/vip`, `/premium`, dan tombol mahkota di menu.
     *
     * Tidak lagi menampilkan daftar paket. Harganya ada di halaman VIP
     * website, dan handler ini tinggal mengantar ke sana — kecuali bila ada
     * tagihan yang belum dibayar, yang tetap didahulukan di sini.
     *
     * Perintahnya sengaja TIDAK dihapus dari router. Orang yang terbiasa
     * mengetik /vip harus mendapat jalan, bukan balasan "command tidak
     * dikenali"; dan tombol Batal saat mengunggah bukti bayar mendarat di
     * fungsi ini juga — lihat `confirmPaid()`.
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

        $pesan = Notice::make('💎', 'Premium')->lead($this->statusLine($user));

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

            $pesan->section('🧾', 'Anda punya tagihan yang belum dibayar')
                ->rows([
                    'Nomor' => $tertunda->number,
                    'Paket' => $tertunda->plan_name,
                    'Sisa'  => Uang::format($tertunda->outstanding(), $tertunda->currency),
                    'Bayar sebelum' => $tertunda->due_at !== null
                        ? Waktu::lengkapRelatif($tertunda->due_at)
                        : null,
                ])
                ->note('Selesaikan dulu yang ini sebelum memilih paket baru.');

            $this->telegram->sendMessage($chatId, $pesan->render());

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

        if ($this->wilayahTersedia()->isEmpty()) {

            $pesan->text('Belum ada paket yang ditawarkan. Coba lagi nanti.');

            $this->telegram->sendMessage($chatId, $pesan->render());

            return;
        }

        $this->antarKeEtalase($chatId, $pesan);
    }

    /**
     * Kirim tautan halaman VIP di website.
     *
     * Dibuka sebagai Mini App bila situsnya sudah HTTPS: harganya tampil di
     * dalam Telegram, tanpa berpindah aplikasi, dan tombol paket di sana
     * kembali ke bot ini lewat `?start=vip_<id>`. Bila belum HTTPS — Telegram
     * menolak Mini App non-HTTPS — tombolnya turun jadi tautan biasa, bukan
     * hilang.
     */
    private function antarKeEtalase(int|string $chatId, Notice $pesan): void
    {
        $pesan->section('💳', 'Harga paket ada di website')
            ->bullets([
                'Semua paket beserta harga per harinya muat dalam satu layar.',
                'Tekan paket yang Anda mau — Anda kembali ke chat ini.',
                'Tagihan, QRIS, dan bukti bayarnya tetap di sini seperti biasa.',
            ])
            ->note('Pembayarannya tidak berubah sama sekali. Yang pindah ke '
                .'website hanya layar memilih harganya.');

        $this->telegram->sendMessage($chatId, $pesan->render(), [
            'reply_markup' => ['inline_keyboard' => [
                [$this->tombolEtalase()],
            ]],
        ]);
    }

    /**
     * Tombol yang membuka halaman VIP.
     *
     * @return array<string,mixed>
     */
    private function tombolEtalase(): array
    {
        $teks = '👑 Lihat harga paket';

        // Alamat Mini App dipakai bila ada; `route()` di dalam pekerjaan bot
        // mengikuti APP_URL, dan di server yang APP_URL-nya masih localhost
        // itu menghasilkan tautan yang tidak bisa dibuka siapa pun.
        $pangkal = rtrim((string) (config('telegram.miniapp_url') ?: config('app.url')), '/');

        $url = $pangkal.'/membership';

        return str_starts_with($url, 'https://')
            ? ['text' => $teks, 'web_app' => ['url' => $url]]
            : ['text' => $teks, 'url' => $url];
    }

    /**
     * Wilayah yang benar-benar siap melayani pembayaran.
     *
     * Syaratnya dua-duanya: ada paket aktif DAN ada provider yang bisa
     * dipakai. Wilayah yang paketnya ada tapi metode bayarnya belum diisi
     * tidak ditawarkan — tombolnya akan mengantar ke penolakan, dan tombol
     * semacam itu lebih buruk daripada tombol yang tidak ada.
     *
     * @return \Illuminate\Support\Collection<int,PaymentRegion>
     */
    private function wilayahTersedia(): \Illuminate\Support\Collection
    {
        return collect(PaymentRegion::cases())
            ->filter(fn (PaymentRegion $r) =>
                $this->paketBerbayar($r)->isNotEmpty()
                && $this->gateways->usable($r)->isNotEmpty())
            ->values();
    }

    /**
     * Paket satu wilayah, tanpa yang gratis.
     *
     * ## Kenapa paket gratis dibuang dari layar ini
     *
     * Layar ini adalah layar membeli. Paket Rp 0 tidak bisa dibeli: menekan
     * tombolnya membuat tagihan senilai nol yang tidak punya cara dibayar,
     * dan pengguna berakhir memandang QRIS tanpa nominal.
     *
     * Lebih dari itu, ia menyesatkan. Paket gratis bukan pilihan yang diambil
     * seseorang, melainkan keadaan awal setiap orang yang belum membayar —
     * mencantumkannya sejajar dengan paket berbayar membuatnya tampak seperti
     * sesuatu yang perlu dipilih dulu.
     *
     * Barisnya dibuang di sini, bukan di basis data. Paket gratis tetap
     * dibutuhkan sebagai acuan hak akses bawaan; yang tidak dibutuhkan hanya
     * penampakannya di etalase.
     *
     * @return \Illuminate\Support\Collection<int,MembershipPlan>
     */
    private function paketBerbayar(PaymentRegion $region): \Illuminate\Support\Collection
    {
        return $this->membership->plans($region)
            ->reject(fn (MembershipPlan $plan) => $plan->isFree())
            ->values();
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

        /*
        |----------------------------------------------------------------------
        | Gabung channel dulu
        |----------------------------------------------------------------------
        |
        | Ditempatkan sebelum apa pun dibuat. Menahan orang SETELAH tagihan
        | jadi berarti ada tagihan menggantung atas nama orang yang belum
        | pernah bisa membayarnya — dan tagihan itu akan menghalangi
        | pembeliannya sendiri begitu ia bergabung.
        |
        */

        if (! $this->channel->lolos($user)) {

            [$pesan, $opsi] = $this->channel->penahan('menyelesaikan pembelian');

            $this->telegram->sendMessage($chatId, $pesan, $opsi);

            return;
        }

        // Tagihan menggantung diperiksa lagi di sini, bukan hanya saat daftar
        // ditampilkan: tombol lama tetap menempel di pesan lama, dan yang
        // menekannya beberapa jam kemudian akan melewati pemeriksaan pertama.
        if ($tertunda = $this->pendingInvoice($user)) {

            /*
            |------------------------------------------------------------------
            | Ketukan kedua pada tombol yang sama
            |------------------------------------------------------------------
            |
            | Tombol paket tidak hilang setelah ditekan, dan tagihan butuh
            | sedetik dua detik untuk jadi. Selama jeda itu orang menekannya
            | lagi — begitu juga Telegram, yang mengirim ulang callback yang
            | belum sempat dijawab.
            |
            | Ketukan kedua itu dulu dijawab "Anda masih punya tagihan X yang
            | belum dibayar, selesaikan dulu yang itu" — menyebut nomor tagihan
            | yang BARU SAJA dikirim bot sendiri, satu pesan di atasnya.
            | Kalimatnya menuduh pengguna melalaikan sesuatu yang bahkan belum
            | sempat ia lihat, dan sebagian orang berhenti di situ karena
            | mengira ada tagihan lain yang tersangkut entah di mana.
            |
            | Kalau tagihannya memang baru dan memang untuk paket yang sama,
            | tidak ada yang perlu dikatakan: QRIS-nya masih terpampang di
            | layar. Diam adalah jawaban yang benar.
            |
            */

            $baru = $tertunda->created_at !== null
                && $tertunda->created_at->gt(now()->subMinutes(2));

            if ($baru && (int) $tertunda->membership_plan_id === $planId) {
                return;
            }

            /*
            | Tagihan lama, atau paket yang berbeda. Di sini pengguna memang
            | perlu diberi tahu — tetapi disertai tagihannya, bukan cuma
            | nomornya. Pesan lama sudah tergulir jauh ke atas, dan menyuruh
            | orang mencarinya sendiri adalah cara paling mudah membuat
            | tagihan tidak pernah dibayar.
            */

            $this->telegram->sendMessage(
                $chatId,
                Notice::make('🧾', 'Selesaikan tagihan ini dulu')
                    ->lead('Satu tagihan dulu, ya. Setelah yang ini lunas, Anda '
                        .'bisa memilih paket lain.')
                    ->rows([
                        'Nomor' => $tertunda->number,
                        'Paket' => $tertunda->plan_name,
                        'Sisa'  => Uang::format($tertunda->outstanding(), $tertunda->currency),
                        'Bayar sebelum' => $tertunda->due_at !== null
                            ? Waktu::lengkapRelatif($tertunda->due_at)
                            : null,
                    ])
                    ->render()
            );

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

        $plan = MembershipPlan::find($planId);

        if ($plan === null || ! $plan->is_active) {
            $this->telegram->sendMessage($chatId, 'Paket itu sudah tidak tersedia.');

            return;
        }

        try {
            // Provider diambil dari wilayah PAKETNYA, bukan dari pilihan
            // tombol tadi. Keduanya biasanya sama, tapi tombol wilayah bisa
            // ditekan berjam-jam kemudian dari pesan lama — sementara paket
            // yang ditunjuknya adalah kebenaran yang tidak bisa basi.
            $provider = $this->gateways->default($plan->region);

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
            Notice::make('📸', 'Kirim bukti pembayaran')
                ->lead('Kirim foto struk atau tangkapan layar transfer ke chat ini.')
                ->rows([
                    'Tagihan' => $invoice->number,
                    'Nominal' => Uang::invoice($invoice),
                ])
                ->text('Pastikan nominal dan waktunya terbaca.')
                ->note('Bukti yang masuk langsung tampil di panel admin. Membership '
                    .'aktif setelah admin memeriksanya — biasanya tidak lama.')
                ->render(),
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
                    $this->captionQris($invoice, $provider),
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
            $this->instruksi($invoice, $provider),
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
     */
    private function captionQris(Invoice $invoice, PaymentProvider $provider): string
    {
        return Notice::make('🧾', 'Scan '.$provider->labelQr().' untuk bayar')
            ->rows([
                'Paket'         => $invoice->plan_name.' — '.$invoice->durasi_tampil,
                'Nominal'       => Uang::invoice($invoice),
                'Nomor tagihan' => $invoice->number,
                'Atas nama'     => filled($merchant = $provider->credential('merchant_name'))
                    ? $merchant
                    : null,
                'Bayar sebelum' => $invoice->due_at !== null
                    ? Waktu::lengkapRelatif($invoice->due_at)
                    : null,
            ])
            ->text('⚠️ Isi nominalnya TEPAT SAMA dengan angka di atas. Selisih '
                .'membuat pembayaran Anda harus dicocokkan manual dan aktivasinya '
                .'jadi lebih lama.')
            ->note('Setelah membayar, tekan "Saya sudah bayar" lalu kirim foto buktinya.')
            ->render();
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
     */
    private function instruksi(Invoice $invoice, $provider): string
    {
        $pesan = Notice::make('🧾', 'Tagihan dibuat')
            ->rows([
                'Paket' => $invoice->plan_name.' — '.$invoice->durasi_tampil,
                'Total' => Uang::invoice($invoice),
                'Nomor' => $invoice->number,
                'Bayar sebelum' => $invoice->due_at !== null
                    ? Waktu::lengkapRelatif($invoice->due_at)
                    : null,
            ]);

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
                $pesan->section('📋', 'Cara bayar')->text($provider->instruction);
            }

            return $pesan
                ->text('⚠️ Bayar TEPAT sebesar nominal di atas. Selisih membuat '
                    .'pembayaran Anda harus dicocokkan manual.')
                ->note('Setelah membayar, tekan "Saya sudah bayar" lalu kirim foto '
                    .'buktinya. Membership aktif setelah admin memeriksanya.')
                ->render();
        }

        $saran = method_exists($provider, 'unitSuggestions')
            ? $provider->unitSuggestions((float) $invoice->total)
            : [];

        if ($saran !== []) {

            $pesan->section('🔢', 'Kirim salah satu')->rows(
                collect(array_slice($saran, 0, 4))
                    ->mapWithKeys(fn (array $u) => [
                        $u['jumlah'].' '.$u['nama'] => Uang::format($u['total'], $invoice->currency)
                            .($u['pas'] ? '  ✅ pas' : ''),
                    ])
                    ->all()
            );
        }

        return $pesan
            ->text('⚠️ JANGAN HAPUS pesan otomatisnya. Kolom pesan di halaman '
                .'pembayaran sudah terisi nomor tagihan di atas. Tanpa nomor itu, '
                .'pembayaran Anda tidak tersambung ke tagihan ini dan membership '
                .'tidak aktif otomatis.')
            ->note('Boleh dicicil. Setiap pembayaran dijumlahkan, dan membership '
                .'aktif sendiri begitu totalnya cukup. Pantau di menu Profil.')
            ->render();
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

            // Tanpa tag HTML: kalimat ini masuk ke Notice::lead(), yang
            // meng-escape isinya supaya nama paket dari basis data tidak
            // pernah bisa merusak parse pesan.
            'premium' => 'Status Anda: Premium aktif'
                .($aktif?->expired_at ? ' sampai '.Waktu::lengkap($aktif->expired_at) : '')
                .'. Membeli lagi menambah masa aktif, bukan menggantinya.',

            'expired' => 'Status Anda: Kedaluwarsa. Perpanjang untuk membuka '
                .'part premium lagi.',

            default => 'Status Anda: Gratis. Part premium belum bisa dibuka.',
        };
    }
}