<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\LogFileReader;
use Illuminate\Console\Command;

/**
 * Menjawab satu pertanyaan: kenapa pembayaran ini belum aktif.
 *
 * ## Kenapa perintah ini ada
 *
 * "Sudah bayar tapi status tidak berubah" punya lima penyebab yang gejalanya
 * identik dari luar:
 *
 * 1. Webhook tidak pernah sampai — toggle di Trakteer masih mati, URL salah,
 *    atau CSRF menolaknya sebelum kode dijalankan.
 * 2. Tokennya tidak cocok — callback ditolak sebagai tanda tangan tidak sah.
 * 3. Nomor tagihan tidak ada di pesan pendukung — pembayarannya masuk tetapi
 *    tidak tersambung ke tagihan mana pun.
 * 4. Nominalnya kurang atau lebih.
 * 5. Semuanya benar tetapi baru sebagian dibayar.
 *
 * Menebaknya dari halaman admin berarti membuka empat tempat berbeda.
 * Perintah ini membaca kelimanya sekaligus dan menyebutkan langkah
 * berikutnya.
 *
 *     php artisan payment:diagnose INV-20260801-AB12CD
 *     php artisan payment:diagnose --last
 */
class PaymentDiagnose extends Command
{
    protected $signature = 'payment:diagnose
                            {invoice? : Nomor tagihan}
                            {--last : Periksa tagihan terakhir yang dibuat}';

    protected $description = 'Telusuri kenapa sebuah pembayaran belum aktif';

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected LogFileReader $log
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $invoice = $this->cariInvoice();

        if ($invoice === null) {
            $this->components->error('Tagihan tidak ditemukan.');

            return self::FAILURE;
        }

        $this->tampilkanInvoice($invoice);
        $this->tampilkanTransaksi($invoice);
        $this->tampilkanProvider();
        $this->tampilkanLog($invoice);
        $this->simpulkan($invoice);

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian-bagiannya
    |--------------------------------------------------------------------------
    */

    private function cariInvoice(): ?Invoice
    {
        $query = Invoice::query()->with(['user', 'transactions.provider', 'subscription']);

        if ($this->option('last')) {
            return $query->latest('id')->first();
        }

        $nomor = $this->argument('invoice');

        if (blank($nomor)) {
            $this->components->error('Sebutkan nomor tagihan, atau pakai --last.');

            return null;
        }

        return $query->where('number', strtoupper(trim($nomor)))->first();
    }

    private function tampilkanInvoice(Invoice $invoice): void
    {
        $this->newLine();
        $this->components->info('Tagihan');

        $this->components->twoColumnDetail('Nomor', $invoice->number);
        $this->components->twoColumnDetail('Pengguna', $invoice->user?->name ?? '-');
        $this->components->twoColumnDetail('Paket', $invoice->plan_name);
        $this->components->twoColumnDetail('Status', $invoice->status->label());
        $this->components->twoColumnDetail('Total', 'Rp '.number_format((float) $invoice->total, 0, ',', '.'));
        $this->components->twoColumnDetail('Sudah masuk', 'Rp '.number_format((float) $invoice->paid_amount, 0, ',', '.').' ('.$invoice->paidPercent().'%)');
        $this->components->twoColumnDetail('Kurang', 'Rp '.number_format($invoice->outstanding(), 0, ',', '.'));

        $langganan = $invoice->subscription()->first();

        $this->components->twoColumnDetail(
            'Langganan',
            $langganan === null
                ? '<fg=red>tidak ada</>'
                : $langganan->status.' — '.($langganan->expired_at?->format('d M Y') ?? 'tanpa batas')
        );
    }

    private function tampilkanTransaksi(Invoice $invoice): void
    {
        $this->newLine();
        $this->components->info('Percobaan pembayaran');

        if ($invoice->transactions->isEmpty()) {
            $this->components->warn('Tidak ada satu pun transaksi. Tagihan ini belum pernah sampai ke provider.');

            return;
        }

        foreach ($invoice->transactions->sortBy('id') as $tx) {

            $this->components->twoColumnDetail(
                $tx->reference,
                $tx->status->label().' — '.($tx->provider?->name ?? 'provider hilang')
            );

            if (filled($tx->last_error)) {
                $this->line('        <fg=red>'.$tx->last_error.'</>');
            }
        }
    }

    private function tampilkanProvider(): void
    {
        $this->newLine();
        $this->components->info('Provider');

        $usable = $this->gateways->usable();

        if ($usable->isEmpty()) {
            $this->components->error('Tidak ada provider yang siap dipakai.');

            return;
        }

        foreach ($usable as $p) {
            $this->components->twoColumnDetail(
                $p->name.' ('.$p->driver->value.')',
                $p->mode.($p->is_default ? ' · default' : '')
            );

            $this->line('        callback: '.url('/payment/callback/'.$p->slug));
        }
    }

    /**
     * Baris log yang menyebut tagihan ini, ditambah kegagalan callback
     * terakhir yang tidak menyebut nomor mana pun.
     *
     * Yang kedua penting: pembayaran yang nomornya salah ketik tidak akan
     * pernah menyebut tagihan ini, dan justru itulah yang sedang dicari.
     */
    private function tampilkanLog(Invoice $invoice): void
    {
        $this->newLine();
        $this->components->info('Jejak log');

        $baris = $this->log->tail('payment.', 8000);

        $terkait = array_values(array_filter(
            $baris,
            fn (array $b) => str_contains($b['pesan'], $invoice->number)
                || str_contains($b['event'] ?? '', 'unmatched')
                || str_contains($b['event'] ?? '', 'invalid_signature')
        ));

        if ($terkait === []) {
            $this->components->warn(
                'Tidak ada satu pun baris log pembayaran yang menyebut tagihan ini '
                .'maupun callback yang ditolak.'
            );

            $this->line('        Artinya webhook kemungkinan besar TIDAK PERNAH SAMPAI.');

            return;
        }

        foreach (array_slice($terkait, 0, 12) as $b) {
            $this->line(sprintf(
                '  <fg=gray>%s</> [%s] %s',
                $b['waktu'],
                $b['level'],
                mb_substr($b['pesan'], 0, 150)
            ));
        }
    }

    /**
     * Simpulkan, dan sebutkan langkah berikutnya.
     *
     * Kesimpulan tanpa langkah berikutnya hanya memindahkan kebingungan.
     */
    private function simpulkan(Invoice $invoice): void
    {
        $this->newLine();
        $this->components->info('Kesimpulan');

        if ($invoice->status === PaymentStatus::PAID) {
            $this->components->twoColumnDetail('Keadaan', '<fg=green>Lunas dan aktif</>');

            return;
        }

        $adaLog = $this->log->tail('payment.', 8000);

        $adaCallback = collect($adaLog)->contains(
            fn (array $b) => str_contains($b['pesan'], 'callback.received')
        );

        if (! $adaCallback) {

            $this->components->error('Belum ada satu pun callback yang pernah diterima.');

            $this->components->bulletList([
                'Pastikan toggle Webhook di dashboard Trakteer sudah AKTIF.',
                'Pastikan URL-nya persis: '.url('/payment/callback/<slug-provider>'),
                'Tekan "Send Webhook Test" di Trakteer — jawabannya harus ok:true.',
                'Kalau jawabannya 419, jalankan: php artisan route:clear',
            ]);

            return;
        }

        if ((float) $invoice->paid_amount > 0 && ! $invoice->isSettled()) {

            $this->components->warn('Pembayaran masuk tetapi belum cukup.');

            $this->line('        Kurang Rp '.number_format($invoice->outstanding(), 0, ',', '.')
                .'. Membership aktif sendiri begitu sisanya masuk.');

            return;
        }

        $this->components->warn(
            'Callback pernah diterima, tetapi tidak ada yang tersambung ke tagihan ini.'
        );

        $this->components->bulletList([
            'Cek `payment.callback.unmatched` di atas — pembayaran yang nomor '
                .'tagihannya tidak terbaca dicatat di sana lengkap dengan payloadnya.',
            'Kalau memang pembayaran ini, aktifkan manual dari /admin/invoice.',
            'Uji jalurnya tanpa uang: php artisan payment:webhook-test '.$invoice->number,
        ]);
    }
}
