<?php

namespace App\Services\Payments\Drivers;

use App\Models\Invoice;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentCharge;
use App\Services\Payments\PaymentResult;

/**
 * Xendit — kerangka.
 *
 * Terdaftar penuh di `PaymentDriver`, punya daftar field kredensialnya
 * sendiri, dan muncul di panel admin. Yang belum ada adalah isinya.
 *
 * ## Kenapa dibiarkan kosong alih-alih ditulis "kira-kira begini"
 *
 * Alur charge, bentuk callback, dan cara menghitung tanda tangan setiap
 * gateway hanya bisa dipastikan dengan akun sungguhan dan dokumentasi yang
 * sedang berlaku. Menulisnya dari ingatan menghasilkan kode yang terlihat
 * selesai, lolos seluruh pemeriksaan statis, lalu gagal pertama kali ada
 * orang yang benar-benar membayar — dan di sistem pembayaran, kegagalan
 * pertama itu terjadi pada uang orang lain.
 *
 * Karena itu ia menolak dengan terang: `PaymentDriver::isImplemented()`
 * mengembalikan false, dan setiap method di sini berhenti dengan pesan yang
 * menyebutkan apa yang kurang. Provider ini tidak akan pernah muncul sebagai
 * pilihan di halaman checkout.
 *
 * ## Yang perlu dikerjakan untuk menyelesaikannya
 *
 * 1. Isi keempat method di bawah memakai `$this->http()` dan
 *    `$this->credential($provider, ...)`.
 * 2. Verifikasi tanda tangan callback dengan `signatureMatches()`, dan
 *    LEMPAR bila tidak cocok — jangan kembalikan hasil dengan penanda.
 * 3. Ubah `isImplemented()` di `PaymentDriver` untuk case ini.
 *
 * Tidak ada berkas lain yang perlu disentuh. Itulah gunanya kontrak ini.
 */
class XenditGateway extends AbstractGateway
{
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge {
        $this->notImplemented($provider);
    }

    public function verify(PaymentProvider $provider, PaymentTransaction $transaction): PaymentResult
    {
        $this->notImplemented($provider);
    }

    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = [],
        ?string $rawBody = null
    ): PaymentResult {
        $this->notImplemented($provider);
    }
}
