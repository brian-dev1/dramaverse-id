<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Admin\ActivityLogger;
use App\Services\Membership\MembershipService;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentAlertService;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentResult;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACC pembayaran manual, dicari lewat ID pengguna.
 *
 * ## Kenapa halaman terpisah dari daftar Tagihan
 *
 * Keduanya bekerja pada data yang sama, dan menggabungkannya sempat terlihat
 * benar. Yang membedakan bukan datanya, melainkan pertanyaan yang dibawa admin
 * saat membuka halaman.
 *
 * Halaman Tagihan menjawab "apa yang terjadi di sistem pembayaran" — daftar,
 * statistik, ekspor, filter status. Halaman ini menjawab satu pertanyaan yang
 * jauh lebih sempit dan jauh lebih sering: **"orang ini bilang sudah bayar,
 * benar tidak?"**
 *
 * Untuk pertanyaan itu, admin memegang satu hal: identitas si penanya — ID
 * pengguna, username Telegram, atau nama. Bukan nomor tagihan. Memaksanya
 * lewat daftar tagihan berarti mencari orang di halaman yang disusun
 * berdasarkan transaksi, dan itu berarti menyaring 25 baris per halaman
 * sambil menebak yang mana.
 *
 * ## Yang TIDAK ditulis ulang di sini
 *
 * Pelunasannya tetap `PaymentCallbackService::apply()`, jalur yang sama persis
 * dengan callback gateway sungguhan dan dengan tombol Verifikasi Manual di
 * halaman Tagihan. Idempotensi, penjagaan perpindahan status, dan aktivasi
 * membership karena itu berlaku sama.
 *
 * Menulis aktivasi sendiri di sini akan melahirkan jalur keempat yang bisa
 * menghidupkan membership tanpa melewati penjagaan yang sama — dan jalur
 * keempat itu adalah yang akan dipakai paling sering, karena inilah halaman
 * yang dibuka admin setiap hari.
 */
class ManualApprovalController extends Controller
{
    public function __construct(
        protected PaymentCallbackService $callbacks,
        protected MembershipService $membership,
        protected PaymentAlertService $alerts
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pencarian
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        [$user, $kandidat] = $q === '' ? [null, collect()] : $this->cari($q);

        return view('web.pages.admin.manual-approval', [
            'q'        => $q,
            'user'     => $user,
            'kandidat' => $kandidat,
            'status'   => $user !== null ? $this->membership->status($user) : null,
            'invoices' => $user !== null ? $this->tagihan($user) : collect(),
            'antre'    => $this->antrean(),
        ]);
    }

    /**
     * Satu pengguna dari apa pun yang diketik admin.
     *
     * Empat cara dicoba berurutan, dari yang paling pasti ke yang paling
     * longgar. Angka dicoba sebagai `users.id` DULU, baru sebagai
     * `telegram_id` — keduanya angka, dan keduanya dipakai admin, tetapi id
     * internal jauh lebih pendek sehingga tabrakannya sangat jarang.
     *
     * Kalau yang cocok lebih dari satu di pencarian longgar, TIDAK ada yang
     * dipilih otomatis: menebak orang mana yang dimaksud, di halaman yang
     * tugasnya menghidupkan membership, adalah tebakan yang salahnya paling
     * mahal. Kandidatnya dikembalikan untuk dipilih admin sendiri.
     *
     * @return array{0: ?User, 1: \Illuminate\Support\Collection}
     */
    private function cari(string $q): array
    {
        $q = ltrim($q, '@#');

        if (ctype_digit($q)) {

            if ($user = User::find((int) $q)) {
                return [$user, collect()];
            }

            if ($user = User::where('telegram_id', $q)->first()) {
                return [$user, collect()];
            }
        }

        if ($user = User::where('telegram_username', $q)->first()) {
            return [$user, collect()];
        }

        $cocok = User::query()
            ->where(fn (Builder $b) => $b
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('telegram_username', 'like', "%{$q}%"))
            ->limit(20)
            ->get();

        return $cocok->count() === 1
            ? [$cocok->first(), collect()]
            : [null, $cocok];
    }

    /** Tagihan milik satu pengguna, terbaru lebih dulu. */
    private function tagihan(User $user)
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->with(['transactions.provider'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PaymentStatus::PENDING->value])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * Yang sudah mengirim bukti dan belum di-ACC.
     *
     * Ditampilkan tanpa perlu mencari apa pun. Ini daftar pekerjaan yang
     * sesungguhnya: orang yang sudah membayar, sudah melapor, dan sedang
     * menunggu. Menyembunyikannya di balik kotak pencarian berarti admin
     * hanya menemukan mereka kalau ada yang mengeluh lebih dulu.
     */
    private function antrean()
    {
        return PaymentTransaction::query()
            ->whereNotNull('proof_uploaded_at')
            ->where('status', PaymentStatus::PENDING->value)
            ->with(['invoice.user', 'provider'])
            ->orderBy('proof_uploaded_at')
            ->limit(50)
            ->get()
            ->filter(fn (PaymentTransaction $tx) => $tx->invoice !== null
                && $tx->invoice->status === PaymentStatus::PENDING);
    }

    /*
    |--------------------------------------------------------------------------
    | ACC
    |--------------------------------------------------------------------------
    */

    /**
     * Setujui satu transaksi: tagihan lunas, membership aktif.
     *
     * Nominalnya diambil dari baris transaksi, TIDAK diisi admin. Membiarkan
     * admin mengetik angkanya berarti penjagaan pencocokan nominal bisa
     * dilewati dengan salah ketik — dan salah ketik pada halaman yang dipakai
     * setiap hari bukan kemungkinan teoretis.
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $tx = PaymentTransaction::with(['invoice.user', 'provider'])->findOrFail($id);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($tx->invoice === null) {
            return back()->with('error', 'Transaksi ini tidak menunjuk tagihan mana pun.');
        }

        try {
            $this->callbacks->apply(
                $tx,
                new PaymentResult(
                    status: PaymentStatus::PAID,
                    reference: $tx->reference,
                    externalId: $tx->external_id,
                    amount: (float) $tx->amount,
                    method: $tx->method ?? 'manual',
                    raw: [
                        'verified_by' => $request->user()->name,
                        'note'        => $data['note'] ?? null,
                        'via'         => 'acc-manual',
                    ],
                ),
                'manual'
            );

        } catch (PaymentException $e) {

            return back()->with('error', $e->getMessage());
        }

        app(ActivityLogger::class)->log('approve', 'invoice', $tx->invoice, [
            'reference' => $tx->reference,
            'user_id'   => $tx->invoice->user_id,
        ]);

        $this->alerts->manualActivation($tx->invoice, $request->user()->name);

        $this->kabariPengguna($tx->invoice->fresh(['user']));

        return back()->with('status',
            'Tagihan '.$tx->invoice->number.' di-ACC. Membership aktif dan pengguna diberi tahu.');
    }

    /**
     * Kabari pengguna lewat bot bahwa membership-nya sudah hidup.
     *
     * Kegagalannya ditelan. Pengguna yang tidak menerima pesan tetap bisa
     * menonton — aktivasinya sudah tersimpan sebelum baris ini dijalankan —
     * dan membatalkan ACC yang sudah berhasil karena Telegram sedang lambat
     * akan menghasilkan keadaan yang jauh lebih buruk daripada pesan yang
     * tidak sampai.
     */
    private function kabariPengguna(?Invoice $invoice): void
    {
        $chatId = $invoice?->telegram_chat_id ?: $invoice?->user?->telegram_id;

        if ($invoice === null || blank($chatId)) {
            return;
        }

        $aktif = $invoice->user !== null ? $this->membership->active($invoice->user) : null;

        try {
            app(TelegramServiceInterface::class)->sendMessage(
                $chatId,
                implode("\n", array_filter([
                    '🎉 <b>Pembayaran diterima — VIP aktif!</b>',
                    '',
                    'Tagihan: <code>'.e($invoice->number).'</code>',
                    'Paket: '.e($invoice->plan_name),
                    $aktif?->expired_at
                        ? 'Berlaku sampai: <b>'.$aktif->expired_at->format('d M Y').'</b>'
                        : null,
                    '',
                    'Semua episode premium sudah terbuka. Selamat menonton!',
                ])),
                ['reply_markup' => ['inline_keyboard' => [
                    [['text' => '🔥 Lihat yang terbaru', 'callback_data' => 'latest']],
                    [['text' => '👤 Profil', 'callback_data' => 'profile']],
                ]]]
            );

        } catch (Throwable $e) {

            Log::warning('payment.approval.notify_failed', [
                'invoice' => $invoice->number,
                'sebab'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tolak bukti yang tidak cocok dengan mutasi.
     *
     * Buktinya dilepas, tagihannya TETAP menunggu. Membatalkan tagihan di sini
     * akan memaksa pengguna mengulang seluruh alur hanya karena ia salah
     * mengirim tangkapan layar — padahal yang perlu diulang cuma satu foto.
     */
    public function rejectProof(Request $request, int $id): RedirectResponse
    {
        $tx = PaymentTransaction::with('invoice.user')->findOrFail($id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $alasan = trim((string) ($data['reason'] ?? '')) ?: 'bukti tidak cocok dengan mutasi';

        $tx->forceFill([
            'proof_path'        => null,
            'proof_file_id'     => null,
            'proof_uploaded_at' => null,
            'proof_note'        => 'Ditolak: '.$alasan,
        ])->save();

        app(ActivityLogger::class)->log('reject-proof', 'invoice', $tx->invoice, [
            'reference' => $tx->reference,
            'alasan'    => $alasan,
        ]);

        $chatId = $tx->invoice?->telegram_chat_id ?: $tx->invoice?->user?->telegram_id;

        if (filled($chatId)) {

            try {
                app(TelegramServiceInterface::class)->sendMessage(
                    $chatId,
                    implode("\n", [
                        '⚠️ <b>Bukti bayar belum bisa diterima</b>',
                        '',
                        'Tagihan: <code>'.e($tx->invoice->number).'</code>',
                        'Alasan: '.e($alasan),
                        '',
                        'Tagihannya <b>masih berlaku</b> — tidak perlu mengulang dari awal. '
                        .'Tekan /vip, lalu kirim ulang bukti yang benar.',
                    ])
                );

            } catch (Throwable $e) {

                Log::warning('payment.proof.reject_notify_failed', [
                    'reference' => $tx->reference,
                    'sebab'     => $e->getMessage(),
                ]);
            }
        }

        return back()->with('status', 'Bukti ditolak. Tagihan tetap menunggu pembayaran.');
    }
}
