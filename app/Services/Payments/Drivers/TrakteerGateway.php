<?php

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\Exceptions\WebhookTestException;
use App\Services\Payments\PaymentCharge;
use App\Services\Payments\PaymentResult;

/**
 * Trakteer.
 *
 * Trakteer tidak punya API pembuatan transaksi. Yang ada adalah halaman
 * dukungan publik dan webhook yang memberi tahu setiap kali ada yang
 * mengirim. Karena itu:
 *
 * - `charge()` tidak memanggil apa pun. Ia menyusun URL halaman beserta
 *   nomor invoice sebagai pesan bawaan; nomor itulah yang menyambungkan
 *   pembayaran ke tagihannya.
 * - `parseCallback()` mengenali invoice dari payload webhook, lalu
 *   mencocokkan nominalnya.
 *
 * ## Bentuk payload
 *
 * Trakteer mengirim JSON kira-kira begini:
 *
 * ```json
 * {
 *   "type": "payment",
 *   "supporter_name": "Seseorang",
 *   "supporter_message": "INV-20260801-AB12CD",
 *   "quantity": 5,
 *   "price": 5000,
 *   "net_amount": 23750,
 *   "unit_name": "Cendol",
 *   "transaction_id": "TRK-xxxx"
 * }
 * ```
 *
 * Nama fieldnya berbeda antar versi dashboard, dan sebagian akun mengirim
 * `amount` alih-alih `price` dikali `quantity`. Karena itu pembacaannya
 * mencoba beberapa nama — bukan karena malas memastikan, tetapi karena
 * memilih satu nama berarti webhook berhenti bekerja pada akun yang
 * mengirim nama lain, tanpa ada yang tahu sampai ada yang mengeluh.
 *
 * ## Pengakuan yang harus dibaca sebelum memakainya
 *
 * Penyambungan lewat pesan bebas **tidak sekuat** referensi yang dijamin
 * gateway. Pendukung yang salah ketik nomor invoice menghasilkan pembayaran
 * yang tidak tersambung ke tagihan mana pun. Itu bukan kegagalan yang bisa
 * dihilangkan kode — Trakteer memang tidak menyediakan tempat lain untuk
 * menaruhnya.
 *
 * Yang dilakukan sebagai gantinya, dan ini yang membuat tidak ada uang
 * hilang tanpa jejak:
 *
 * 1. Nomor invoice dicari dengan pola yang toleran terhadap spasi, tanda
 *    hubung yang hilang, dan huruf kecil.
 * 2. Bila tetap tidak ketemu, seluruh payload dicatat sebagai
 *    `payment.callback.unmatched` beserta nama pendukung dan nominalnya,
 *    supaya admin bisa mencocokkannya manual dari `/admin/payment/log`.
 * 3. Nominal yang kurang tetap dilaporkan ke `PaymentCallbackService`, yang
 *    menolak mengaktifkan membership dan menandainya perlu diperiksa —
 *    bukan menolak diam-diam.
 */
class TrakteerGateway extends AbstractGateway
{
    /**
     * Jenis webhook yang berarti "ada uang masuk".
     *
     * Trakteer juga mengirim webhook untuk hal lain. Memproses semuanya
     * sebagai pembayaran berarti mengaktifkan membership dari peristiwa yang
     * bukan pembayaran.
     */
    private const JENIS_PEMBAYARAN = ['payment', 'donation', 'support', 'tip'];

    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {

        $url = rtrim($this->credential($provider, 'page_url'), '/');

        // Dibaca supaya kekurangannya ketahuan SEKARANG, bukan nanti saat
        // webhook pertama datang dan ditolak karena tokennya belum diisi.
        $this->credential($provider, 'webhook_token');

        $saranUnit = $provider->unitSuggestions((float) $invoice->total)[0] ?? null;

        $jumlahUnit = max(1, (int) ($saranUnit['jumlah'] ?? 1));

        // Payment flexible unit quantity: enabled.
        // Nomor invoice ikut sebagai pesan bawaan. Ini satu-satunya jalan
        // menyambungkan pembayaran ke tagihannya di Trakteer.
        //
        // Bila admin mengisi kredensial units, contoh:
        // Cendol=1500
        //
        // invoice Rp 3.000 akan membuka Trakteer dengan quantity=2.
        // Kalau nominal tidak habis dibagi harga unit, jumlah dibulatkan ke atas
        // supaya tagihan tidak kurang bayar.
        $checkout = $url.'?'.http_build_query([
            'quantity' => $jumlahUnit,
            'message'  => $invoice->number,
        ]);

        $this->log('info', 'charge.trakteer', [
            'invoice'   => $invoice->number,
            'reference' => $transaction->reference,
            'total'      => (float) $invoice->total,
            'quantity'   => $jumlahUnit,
            'unit_name'  => $saranUnit['nama'] ?? null,
            'unit_price' => $saranUnit['harga'] ?? null,
            'unit_total' => $saranUnit['total'] ?? null,
            'unit_exact' => $saranUnit['pas'] ?? null,
        ]);

        return new PaymentCharge(
            externalId: null,
            checkoutUrl: $checkout,
            status: PaymentStatus::PENDING,
            raw: [
                'page_url'      => $url,
                'quantity'      => $jumlahUnit,
                'unit_name'     => $saranUnit['nama'] ?? null,
                'unit_price'    => $saranUnit['harga'] ?? null,
                'unit_total'    => $saranUnit['total'] ?? null,
                'unit_exact'    => $saranUnit['pas'] ?? null,
            ],
            expiresAt: $invoice->due_at,
            method: 'trakteer',
        );
    }

    /**
     * Trakteer tidak punya endpoint status.
     *
     * Yang berlaku adalah keadaan yang sudah tersimpan dari callback. Ini
     * disebut apa adanya, bukan disamarkan jadi pemanggilan yang selalu
     * mengembalikan PENDING.
     */
    public function verify(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        return new PaymentResult(
            status: $transaction->status,
            reference: $transaction->reference,
            amount: (float) $transaction->amount,
            method: $transaction->method,
            message: 'Trakteer tidak menyediakan pemeriksaan status; '
                .'yang berlaku adalah callback terakhir yang diterima.'
        );
    }

    /**
     * @throws PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = [],
        ?string $rawBody = null
    ): PaymentResult {

        $this->assertSignature($provider, $headers);

        /*
        |----------------------------------------------------------------------
        | Jenis peristiwa
        |----------------------------------------------------------------------
        |
        | Bila field `type` ada, ia harus salah satu jenis pembayaran. Bila
        | tidak ada sama sekali — sebagian akun tidak mengirimnya — payload
        | tetap diproses, karena menolaknya berarti webhook tidak pernah
        | bekerja di akun itu.
        |
        */

        $jenis = strtolower(trim((string) ($payload['type'] ?? '')));

        /*
        |----------------------------------------------------------------------
        | Tombol "Send Webhook Test" di dashboard Trakteer
        |----------------------------------------------------------------------
        |
        | Payloadnya bukan pembayaran dan tidak memuat nomor tagihan. Dijawab
        | berhasil supaya tombolnya bisa dipakai memastikan URL dan token sudah
        | benar — itu satu-satunya cara memeriksanya tanpa mengeluarkan uang.
        |
        | Tandanya diperiksa longgar: Trakteer belum menjamin bentuk payload
        | ujinya, dan yang penting di sini adalah tidak pernah salah mengira
        | pembayaran sungguhan sebagai uji coba. Karena itu syaratnya berlapis
        | — harus bertanda uji DAN tidak memuat nomor tagihan.
        |
        */

        if ($this->looksLikeTest($jenis, $payload)) {

            $this->log('info', 'callback.test', ['provider' => $provider->slug]);

            throw WebhookTestException::berhasil($provider->slug);
        }

        if ($jenis !== '' && ! in_array($jenis, self::JENIS_PEMBAYARAN, true)) {

            $this->log('info', 'callback.ignored', [
                'provider' => $provider->slug,
                'jenis'    => $jenis,
            ]);

            throw PaymentException::unknownReference("jenis webhook `{$jenis}` bukan pembayaran");
        }

        /*
        |----------------------------------------------------------------------
        | Sambungkan ke invoice
        |----------------------------------------------------------------------
        */

        $pesan = $this->messageFrom($payload);

        $nomor = $this->extractInvoiceNumber($pesan);

        if ($nomor === null) {

            // Dicatat LENGKAP supaya bisa dicocokkan manual. Uang yang masuk
            // tanpa keterangan tidak boleh hilang tanpa jejak.
            $this->log('warning', 'callback.unmatched', [
                'provider'  => $provider->slug,
                'pendukung' => $payload['supporter_name'] ?? null,
                'nominal'   => $this->amountFrom($payload),
                'pesan'     => $pesan,
                'payload'   => $payload,
            ]);

            throw PaymentException::unknownReference(
                'pesan pendukung tidak memuat nomor invoice yang dikenali'
            );
        }

        $jumlah = $this->amountFrom($payload);

        if ($jumlah <= 0) {

            $this->log('warning', 'callback.amount_unreadable', [
                'provider' => $provider->slug,
                'invoice'  => $nomor,
                'payload'  => $payload,
            ]);

            throw new PaymentException(
                'Nominal webhook Trakteer tidak terbaca. Pembayaran tidak diproses otomatis.'
            );
        }

        $this->log('info', 'callback.trakteer', [
            'provider' => $provider->slug,
            'invoice'  => $nomor,
            'nominal'  => $jumlah,
        ]);

        return new PaymentResult(
            status: PaymentStatus::PAID,
            reference: $nomor,
            externalId: $this->externalIdFrom($payload),
            amount: $jumlah,
            method: 'trakteer',
            raw: $payload,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian-bagiannya
    |--------------------------------------------------------------------------
    */

    /**
     * Verifikasi token webhook.
     *
     * Trakteer mengirimnya pada header `X-Webhook-Token`. Sebagian pemasangan
     * di belakang proxy meneruskannya dengan nama lain, jadi beberapa nama
     * dicoba — tetapi **tidak ada** jalan lolos tanpa token yang cocok.
     *
     * Dibandingkan dengan `hash_equals`, bukan `===`: perbandingan biasa
     * berhenti di karakter pertama yang berbeda, dan selisih waktunya bisa
     * dipakai menebak tokennya karakter demi karakter.
     *
     * @throws PaymentException
     */
    private function assertSignature(PaymentProvider $provider, array $headers): void
    {
        $token = $this->credential($provider, 'webhook_token');

        foreach (['x-webhook-token', 'x-trakteer-token', 'webhook-token'] as $nama) {

            if ($this->signatureMatches($token, (string) ($headers[$nama] ?? ''))) {
                return;
            }
        }

        $this->log('warning', 'callback.invalid_signature', [
            'provider' => $provider->slug,
            // Nama header yang diterima dicatat, NILAINYA tidak — supaya
            // salah nama header bisa dibedakan dari salah token, tanpa
            // menuliskan token siapa pun ke log.
            'header'   => array_values(array_filter(
                array_keys($headers),
                fn (string $h) => str_contains($h, 'token') || str_contains($h, 'signature')
            )),
        ]);

        throw PaymentException::invalidSignature($provider->slug);
    }

    /** Pesan dari pendukung, di mana pun Trakteer menaruhnya. */
    private function messageFrom(array $payload): string
    {
        foreach (['supporter_message', 'message', 'support_message', 'note'] as $key) {

            $nilai = trim((string) ($payload[$key] ?? ''));

            if ($nilai !== '') {
                return $nilai;
            }
        }

        return '';
    }

    /**
     * Nominal yang benar-benar dibayar.
     *
     * Urutannya penting. `net_amount` adalah yang diterima setelah potongan
     * Trakteer, dan itu **bukan** yang ditagih ke pengguna — memakainya akan
     * membuat setiap pembayaran tampak kurang bayar, lalu ditolak
     * `PaymentCallbackService` karena nominalnya tidak cocok.
     *
     * Yang dipakai adalah nominal kotor: `amount` bila ada, atau
     * `price` dikali `quantity` yang merupakan bentuk asli Trakteer.
     */
    private function amountFrom(array $payload): float
    {
        foreach (['amount', 'gross_amount', 'total_amount'] as $key) {

            $nilai = $this->money($payload[$key] ?? null);

            if ($nilai > 0) {
                return $nilai;
            }
        }

        $harga = $this->money($payload['price'] ?? null);

        $jumlah = ($payload['quantity'] ?? null) !== null
            ? (int) $this->money($payload['quantity'])
            : 0;

        return $harga > 0 && $jumlah > 0 ? $harga * $jumlah : 0.0;
    }

    /**
     * Id transaksi milik Trakteer.
     *
     * Dipakai `PaymentCallbackService` untuk menolak callback ganda lewat
     * unique index `(payment_provider_id, external_id)`. Null berarti
     * penjagaan itu tidak berlaku dan yang tersisa hanya penjagaan status —
     * masih cukup, tetapi lebih lemah.
     */
    private function externalIdFrom(array $payload): ?string
    {
        foreach (['transaction_id', 'id', 'reference', 'order_id'] as $key) {

            $nilai = trim((string) ($payload[$key] ?? ''));

            if ($nilai !== '') {
                return $nilai;
            }
        }

        /*
        |----------------------------------------------------------------------
        | Fallback idempotensi
        |----------------------------------------------------------------------
        |
        | Bila Trakteer/proxy tidak mengirim id transaksi, callback retry dengan
        | payload yang sama tetap harus dikenali sebagai duplikat. Hash payload
        | bukan identitas bisnis yang sempurna, tetapi jauh lebih aman daripada
        | menghitung payload retry sebagai pembayaran baru.
        |
        */
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : 'trakteer-'.substr(hash('sha256', $json), 0, 40);
    }

    /**
     * Cari nomor invoice di dalam pesan bebas.
     *
     * Sengaja toleran, karena yang mengetiknya pendukung, bukan mesin:
     *
     * - huruf kecil diterima
     * - spasi di antara bagian diterima (`INV 20260801 AB12CD`)
     * - tanda hubung boleh diganti spasi atau garis bawah
     * - kalimat lain di sekitarnya diabaikan
     *
     * Yang TIDAK ditoleransi: karakter yang salah di dalam kodenya sendiri.
     * Menebak-nebak nomor invoice berarti berisiko mengaktifkan membership
     * milik orang lain.
     */
    private function extractInvoiceNumber(string $pesan): ?string
    {
        if ($pesan === '') {
            return null;
        }

        if (preg_match('/\bINV[\s\-_]*([0-9]{8})[\s\-_]*([A-Z0-9]{4,10})\b/i', $pesan, $cocok)) {
            return 'INV-'.$cocok[1].'-'.strtoupper($cocok[2]);
        }

        return null;
    }

    /**
     * Payload ini uji coba, bukan pembayaran.
     *
     * Berlapis dengan sengaja. Salah mengira pembayaran sungguhan sebagai uji
     * coba berarti uang masuk tanpa mengaktifkan apa pun — jauh lebih buruk
     * daripada uji coba yang terlanjur diproses sebagai pembayaran, yang toh
     * akan ditolak karena tidak memuat nomor tagihan.
     */
    private function looksLikeTest(string $jenis, array $payload): bool
    {
        // Nomor tagihan yang sah tidak pernah ada di payload uji.
        if ($this->extractInvoiceNumber($this->messageFrom($payload)) !== null) {
            return false;
        }

        if (in_array($jenis, ['test', 'testing', 'webhook_test'], true)) {
            return true;
        }

        $id = strtolower((string) ($payload['transaction_id'] ?? ''));

        return $id !== '' && str_starts_with($id, 'test');
    }

    /**
     * Ubah nominal dari bentuk Trakteer/manusia menjadi float.
     *
     * Payload nyata sering mengirim angka sebagai `5000`, `5.000`,
     * `Rp 5.000`, atau `5000.00`. `is_numeric()` hanya aman untuk bentuk
     * pertama, jadi parsing dibuat eksplisit.
     */
    private function money(mixed $nilai): float
    {
        if (is_int($nilai) || is_float($nilai)) {
            return (float) $nilai;
        }

        if (! is_string($nilai)) {
            return 0.0;
        }

        $angka = trim(str_ireplace(['rp', 'idr', ' '], '', $nilai));

        if ($angka === '') {
            return 0.0;
        }

        $angka = preg_replace('/[^0-9,.\-]/', '', $angka) ?? '';

        if ($angka === '' || $angka === '-' || $angka === ',' || $angka === '.') {
            return 0.0;
        }

        if (str_contains($angka, ',') && str_contains($angka, '.')) {
            $angka = str_replace('.', '', $angka);
            $angka = str_replace(',', '.', $angka);

            return is_numeric($angka) ? (float) $angka : 0.0;
        }

        if (str_contains($angka, ',')) {
            $bagian = explode(',', $angka);
            $akhir = end($bagian);

            $angka = strlen((string) $akhir) === 3
                ? str_replace(',', '', $angka)
                : str_replace(',', '.', $angka);

            return is_numeric($angka) ? (float) $angka : 0.0;
        }

        if (str_contains($angka, '.')) {
            $bagian = explode('.', $angka);
            $akhir = end($bagian);

            $angka = strlen((string) $akhir) === 3
                ? str_replace('.', '', $angka)
                : $angka;
        }

        return is_numeric($angka) ? (float) $angka : 0.0;
    }
}
