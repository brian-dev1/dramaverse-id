<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\PaymentCallbackService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mengirim callback tiruan ke jalur callback yang sungguhan.
 *
 * ## Kenapa perintah ini ada
 *
 * Webhook pembayaran adalah jalur yang paling sulit diuji dan paling mahal
 * bila salah. Menguji dengan pembayaran sungguhan berarti mengeluarkan uang
 * setiap kali ingin tahu apakah satu perubahan bekerja, dan menunggu Trakteer
 * mengirim webhook untuk melihat hasilnya.
 *
 * Perintah ini memotong itu: payload disusun, lalu diserahkan ke
 * `PaymentCallbackService` **yang sama persis** dengan yang dipakai callback
 * sungguhan. Verifikasi tanda tangan, penjagaan nominal, penjagaan
 * perpindahan status, idempotensi, dan aktivasi membership semuanya berjalan.
 *
 * Yang TIDAK dilewati: satu-satunya bagian yang tidak diuji adalah lapisan
 * HTTP — routing, CSRF, dan rate limit. Untuk itu pakai `curl`; perintahnya
 * dicetak di akhir.
 *
 * ```
 * php artisan payment:webhook-test INV-20260801-AB12CD
 * php artisan payment:webhook-test INV-20260801-AB12CD --amount=50000
 * php artisan payment:webhook-test INV-20260801-AB12CD --bad-signature
 * ```
 */
class PaymentWebhookTest extends Command
{
    protected $signature = 'payment:webhook-test
                            {invoice : Nomor tagihan, misalnya INV-20260801-AB12CD}
                            {--provider= : Slug provider. Bawaannya provider tagihan itu}
                            {--amount= : Nominal yang dibayar. Bawaannya total tagihan}
                            {--message= : Isi pesan pendukung. Bawaannya nomor tagihan}
                            {--bad-signature : Kirim token salah, untuk menguji penolakannya}
                            {--dry : Hanya tampilkan payload, jangan proses}';

    protected $description = 'Uji jalur callback pembayaran tanpa membayar sungguhan';

    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PaymentCallbackService $callbacks
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $invoice = Invoice::where('number', $this->argument('invoice'))->first();

        if ($invoice === null) {
            $this->components->error('Tagihan '.$this->argument('invoice').' tidak ditemukan.');

            return self::FAILURE;
        }

        $transaction = $invoice->latestTransaction()->first();

        $slug = $this->option('provider')
            ?: $transaction?->provider?->slug;

        if (blank($slug)) {
            $this->components->error(
                'Tagihan ini belum punya transaksi, jadi providernya tidak diketahui. '
                .'Sebutkan dengan --provider=<slug>.'
            );

            return self::FAILURE;
        }

        try {
            $provider = $this->gateways->find($slug);
        } catch (PaymentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $amount = $this->option('amount') !== null
            ? (float) $this->option('amount')
            : (float) $invoice->total;

        $payload = $this->payload($provider->driver->value, $invoice->number, $amount);

        $headers = $this->headers($provider->driver->value, $provider->credential('webhook_token'));

        /*
        |----------------------------------------------------------------------
        | Tampilkan apa yang akan dikirim
        |----------------------------------------------------------------------
        */

        $this->components->twoColumnDetail('Provider', $provider->name.' ('.$provider->driver->value.')');
        $this->components->twoColumnDetail('Tagihan', $invoice->number);
        $this->components->twoColumnDetail('Status sekarang', $invoice->status->label());
        $this->components->twoColumnDetail('Ditagih', 'Rp '.number_format((float) $invoice->total, 0, ',', '.'));
        $this->components->twoColumnDetail('Dibayar (tiruan)', 'Rp '.number_format($amount, 0, ',', '.'));

        $this->newLine();
        $this->line('  <fg=gray>'.json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).'</>');
        $this->newLine();

        if ($this->option('dry')) {
            $this->curlHint($provider->slug, $payload, $headers);

            return self::SUCCESS;
        }

        /*
        |----------------------------------------------------------------------
        | Proses lewat jalur yang sungguhan
        |----------------------------------------------------------------------
        */

        try {
            $hasil = $this->callbacks->handle($provider, $payload, $headers);

        } catch (PaymentException $e) {

            // Penolakan bisa jadi memang yang diharapkan — itu gunanya
            // --bad-signature dan --amount yang sengaja salah.
            $this->components->error('Ditolak: '.$e->getMessage());

            $this->line('        Itu BENAR bila Anda memang sedang menguji penolakannya.');

            return self::FAILURE;

        } catch (Throwable $e) {
            $this->components->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $invoice->refresh();

        $this->components->info('Callback diproses.');

        $this->components->twoColumnDetail('Transaksi', $hasil->reference.' — '.$hasil->status->label());
        $this->components->twoColumnDetail('Tagihan', $invoice->status->label());

        $langganan = $invoice->subscription()->first();

        $this->components->twoColumnDetail(
            'Langganan',
            $langganan === null
                ? 'tidak ada'
                : $langganan->status.' sampai '.($langganan->expired_at?->format('d M Y') ?? '-')
        );

        $this->newLine();
        $this->curlHint($provider->slug, $payload, $headers);

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Penyusun payload
    |--------------------------------------------------------------------------
    */

    /**
     * Payload tiruan yang bentuknya mengikuti provider aslinya.
     *
     * Untuk driver yang belum diketahui bentuknya, dipakai bentuk paling
     * umum. Itu tidak akan cocok dengan gateway sungguhan — dan memang tidak
     * dimaksudkan cocok; driver yang belum selesai menolak dipakai jauh
     * sebelum sampai ke sini.
     */
    private function payload(string $driver, string $nomor, float $amount): array
    {
        $pesan = $this->option('message') ?? $nomor;

        return match ($driver) {

            'trakteer' => [
                'type'              => 'payment',
                'supporter_name'    => 'Uji Coba',
                'supporter_message' => $pesan,
                'quantity'          => 1,
                'price'             => $amount,
                'net_amount'        => round($amount * 0.95, 2),
                'unit_name'         => 'Cendol',
                'transaction_id'    => 'UJI-'.now()->format('YmdHis'),
                'created_at'        => now()->toDateTimeString(),
            ],

            default => [
                'reference' => $nomor,
                'status'    => 'PAID',
                'amount'    => $amount,
            ],
        };
    }

    /**
     * Header tiruan, termasuk tanda tangannya.
     *
     * `--bad-signature` mengirim token yang pasti salah. Itu bukan sekadar
     * pelengkap: memastikan callback yang tidak sah DITOLAK sama pentingnya
     * dengan memastikan yang sah diterima, dan yang kedua saja bisa lolos
     * meski verifikasi tanda tangannya tidak pernah berjalan.
     */
    private function headers(string $driver, ?string $token): array
    {
        $dipakai = $this->option('bad-signature')
            ? 'token-yang-pasti-salah'
            : (string) $token;

        return match ($driver) {
            'trakteer' => ['x-webhook-token' => $dipakai],
            default    => ['x-callback-token' => $dipakai],
        };
    }

    /**
     * Perintah curl untuk menguji lapisan HTTP-nya juga.
     *
     * Yang di atas melewati routing, CSRF, dan rate limit. Ketiganya pernah
     * jadi penyebab kegagalan yang tidak terlihat sama sekali dari sisi
     * aplikasi — callback pembayaran sempat dijawab 419 oleh CSRF sebelum
     * satu baris kode pun berjalan.
     */
    private function curlHint(string $slug, array $payload, array $headers): void
    {
        $header = '';

        foreach ($headers as $nama => $nilai) {
            $header .= " \\\n  -H '".$nama.': '.$nilai."'";
        }

        $this->components->info('Untuk menguji lapisan HTTP-nya (routing, CSRF, rate limit):');

        $this->line("\n  curl -i -X POST ".url('/payment/callback/'.$slug)." \\\n"
            ."  -H 'Content-Type: application/json'".$header." \\\n"
            .'  -d '.escapeshellarg(json_encode($payload))."\n");
    }
}
