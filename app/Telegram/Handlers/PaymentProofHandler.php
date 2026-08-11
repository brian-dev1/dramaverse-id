<?php

namespace App\Telegram\Handlers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Monitoring\AlertService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\UserSessionService;
use App\Support\Telegram\Notice;
use App\Support\Waktu;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use App\Support\Uang;

/**
 * Menerima foto bukti bayar yang dikirim pengguna ke bot.
 *
 * ## Bukti TIDAK mengubah status apa pun
 *
 * Ini penekanan yang paling penting di kelas ini. Foto yang masuk hanya
 * ditempelkan ke transaksinya dan dicatat waktunya. Tagihan tetap PENDING,
 * membership tetap mati.
 *
 * Alasannya sederhana: tangkapan layar bisa dipalsukan dalam dua menit dengan
 * alat yang ada di ponsel siapa pun. Yang membuktikan uang berpindah bukan
 * gambarnya, melainkan mutasi rekening — dan yang bisa melihat mutasi hanya
 * admin. Pelunasannya karena itu tetap lewat `PaymentCallbackService::apply()`
 * dari panel, jalur yang sama persis dengan callback gateway sungguhan.
 *
 * Bukti bayar di sini gunanya mempersempit pencarian admin di daftar mutasi,
 * bukan menggantikannya.
 *
 * ## Berkasnya disalin ke disk kita
 *
 * Telegram menyimpan foto dan memberi kita `file_id`, dan sempat terlihat
 * cukup untuk hanya menyimpan id itu. Tidak cukup: membukanya memerlukan
 * token bot, sehingga panel admin tidak bisa menampilkannya sebagai gambar
 * biasa tanpa memproksikan setiap permintaan lewat aplikasi.
 *
 * Jadi berkasnya diunduh sekali dan disimpan di disk `public`. `file_id`-nya
 * tetap ikut dicatat sebagai cadangan — lihat migrasi
 * `add_payment_proof_to_payment_transactions_table`.
 */
class PaymentProofHandler
{
    /** Ukuran maksimum bukti yang mau diunduh, dalam byte. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected UserSessionService $sessions,
        protected AlertService $alerts
    ) {
    }

    /**
     * Pengguna mengirim foto saat state-nya PAY_PROOF.
     *
     * @param  array  $message  objek message Telegram apa adanya
     */
    public function handle(array $message, User $user, array $payload = []): void
    {
        $chatId = $message['chat']['id'];

        $invoice = $this->invoice($user, $payload);

        if ($invoice === null) {

            $this->sessions->clear((int) $user->id);

            $this->telegram->sendMessage(
                $chatId,
                'Tagihannya sudah tidak menunggu pembayaran, jadi buktinya tidak '
                .'jadi disimpan. Cek status di menu Profil.'
            );

            return;
        }

        $transaction = $invoice->transactions()->latest('id')->first();

        if ($transaction === null) {

            $this->sessions->clear((int) $user->id);

            $this->telegram->sendMessage(
                $chatId,
                'Tagihan ini belum punya percobaan pembayaran, jadi buktinya belum '
                .'bisa ditempelkan. Tekan /vip untuk membuat ulang.'
            );

            return;
        }

        /*
        |----------------------------------------------------------------------
        | Ambil ukuran terbesar
        |----------------------------------------------------------------------
        |
        | Telegram mengirim `photo` sebagai daftar beberapa ukuran dari yang
        | paling kecil. Yang berguna bagi admin adalah yang paling besar —
        | nominal di struk sering tidak terbaca pada thumbnail.
        |
        */

        $foto = $message['photo'] ?? [];

        $terbesar = is_array($foto) && $foto !== [] ? end($foto) : null;

        $fileId = $terbesar['file_id'] ?? null;

        if (! is_string($fileId) || $fileId === '') {

            $this->telegram->sendMessage(
                $chatId,
                'Kirim buktinya sebagai <b>foto</b>, ya. Dokumen dan stiker belum '
                .'bisa dibaca panel admin.'
            );

            return;
        }

        $path = $this->unduh($fileId, $invoice->number);

        $transaction->forceFill([
            'proof_path'        => $path,
            'proof_file_id'     => $fileId,
            'proof_uploaded_at' => now(),
            'proof_note'        => Str::limit(trim((string) ($message['caption'] ?? '')), 480, ''),
        ])->save();

        $this->sessions->clear((int) $user->id);

        Log::info('payment.proof.received', [
            'invoice'   => $invoice->number,
            'user_id'   => $user->id,
            'tersimpan' => $path !== null,
        ]);

        $this->beritahuAdmin($invoice, $user);

        $this->telegram->sendMessage(
            $chatId,
            Notice::make('✅', 'Bukti diterima')
                ->lead('Admin akan mencocokkannya dengan mutasi rekening.')
                ->rows([
                    'Tagihan' => $invoice->number,
                    'Nominal' => Uang::invoice($invoice),
                    'Diterima' => Waktu::ringkas(now()),
                ])
                ->text('Anda mendapat pesan lagi begitu membership aktif.')
                ->note('Belum ada kabar setelah beberapa jam? Kirim ulang buktinya '
                    .'atau hubungi admin dengan menyebut nomor tagihan di atas.')
                ->render(),
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => '👤 Cek status di Profil', 'callback_data' => 'profile']],
            ]]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Tagihan yang buktinya sedang ditunggu.
     *
     * Dibaca dari payload state, lalu diperiksa ulang kepemilikan dan
     * statusnya. State bisa tertinggal berjam-jam — tagihannya mungkin sudah
     * dibatalkan otomatis karena basi sejak tombolnya ditekan.
     */
    private function invoice(User $user, array $payload): ?Invoice
    {
        $id = (int) ($payload['invoice_id'] ?? 0);

        $query = Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::PENDING->value);

        if ($id > 0) {
            $query->where('id', $id);
        }

        return $query->latest('id')->first();
    }

    /**
     * Salin berkas dari Telegram ke disk `public`.
     *
     * Kegagalannya dikembalikan sebagai null, bukan dilempar. `file_id`-nya
     * sudah tersimpan di baris transaksi apa pun yang terjadi di sini, jadi
     * bukti yang gagal diunduh masih bisa ditarik ulang belakangan — dan
     * membatalkan seluruh penerimaan bukti karena unduhan gagal berarti
     * pengguna disuruh mengirim ulang sesuatu yang sebenarnya sudah sampai.
     */
    private function unduh(string $fileId, string $invoiceNumber): ?string
    {
        try {
            $berkas = $this->telegram->getFile($fileId);

            $filePath = (string) $berkas->get('file_path', '');

            if ($filePath === '') {
                return null;
            }

            if ((int) $berkas->get('file_size', 0) > self::MAX_BYTES) {

                Log::warning('payment.proof.too_large', [
                    'invoice' => $invoiceNumber,
                    'bytes'   => $berkas->get('file_size'),
                ]);

                return null;
            }

            $respon = Http::timeout(30)->get($this->telegram->downloadUrl($filePath));

            if (! $respon->successful()) {
                return null;
            }

            // Ekstensi diambil dari path Telegram, bukan dari nama yang
            // dikirim klien: yang pertama sudah ditentukan server Telegram,
            // yang kedua datang dari perangkat pengguna.
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg');

            $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';

            $tujuan = 'payment/proof/'.now()->format('Ym').'/'.Str::uuid()->toString().'.'.$ext;

            Storage::disk('public')->put($tujuan, $respon->body());

            return $tujuan;

        } catch (Throwable $e) {

            Log::warning('payment.proof.download_failed', [
                'invoice' => $invoiceNumber,
                'sebab'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Beri tahu admin bahwa ada yang menunggu di-ACC.
     *
     * Lewat `AlertService` yang sama dengan peringatan lain, jadi penahan
     * pengulangannya berlaku: sepuluh orang yang membayar dalam satu menit
     * tidak menghasilkan sepuluh notifikasi beruntun.
     */
    private function beritahuAdmin(Invoice $invoice, User $user): void
    {
        try {
            $this->alerts->send(
                'payment-proof-'.$invoice->id,
                'Bukti bayar masuk',
                sprintf(
                    "Tagihan %s (%s) — Rp %s\nPengguna: %s (ID %d%s)\n\n"
                    ."Cocokkan dengan mutasi, lalu ACC di panel: menu ACC Manual.",
                    $invoice->number,
                    $invoice->plan_name,
                    number_format((float) $invoice->total, 0, ',', '.'),
                    $user->name,
                    $user->id,
                    $user->telegram_username ? ', @'.$user->telegram_username : ''
                ),
                ['invoice' => $invoice->number, 'user_id' => $user->id]
            );

        } catch (Throwable $e) {

            // Notifikasi gagal tidak boleh membatalkan penerimaan bukti.
            // Buktinya sudah tersimpan; yang hilang cuma dentingnya.
            Log::warning('payment.proof.alert_failed', [
                'invoice' => $invoice->number,
                'sebab'   => $e->getMessage(),
            ]);
        }
    }
}
