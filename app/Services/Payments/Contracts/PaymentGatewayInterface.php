<?php

namespace App\Services\Payments\Contracts;

use App\Models\Invoice;
use App\Models\PaymentProvider;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentCharge;
use App\Services\Payments\PaymentResult;

/**
 * Kontrak satu gateway pembayaran.
 *
 * ## Aturan yang menentukan seluruh bentuknya
 *
 * **Business Logic Membership tidak boleh tahu provider mana yang dipakai.**
 * Menambah Stripe atau PayPal harus cukup dengan menulis satu kelas yang
 * memenuhi kontrak ini dan menambah satu case di `PaymentDriver` — tanpa
 * menyentuh `MembershipService`, `InvoiceService`, `PaymentCallbackService`,
 * controller, maupun view mana pun.
 *
 * Itu sebabnya setiap method menerima `PaymentProvider` sebagai argumen
 * alih-alih membacanya dari config: satu driver bisa dipasang dua kali dengan
 * kredensial berbeda — sandbox dan live berdampingan — dan gateway tidak boleh
 * menyimpan keadaan milik salah satunya.
 *
 * Pola yang sama dengan `StorageEngineInterface` (7.4) dan
 * `TelegramServiceInterface` (8.1).
 *
 * ## Kegagalan dilempar
 *
 * Setiap method mengembalikan hasil yang sudah pasti benar, atau melempar
 * `PaymentException`. Tidak ada nilai balik yang perlu diperiksa dulu —
 * pelajaran dari lapisan Telegram, tempat 19 dari 20 pemanggil tidak pernah
 * memeriksa nilai baliknya.
 */
interface PaymentGatewayInterface
{
    /**
     * Buat permintaan pembayaran baru di sisi provider.
     *
     * `$transaction` sudah tersimpan dengan `reference` miliknya sebelum
     * method ini dipanggil. Itu disengaja: referensi harus sudah ada di basis
     * data kita SEBELUM dikirim ke luar, supaya callback yang datang lebih
     * cepat daripada jawaban charge tetap menemukan barisnya.
     *
     * @throws \App\Services\Payments\Exceptions\PaymentException
     */
    public function charge(
        PaymentProvider $provider,
        Invoice $invoice,
        PaymentTransaction $transaction
    ): PaymentCharge;

    /**
     * Tanyakan keadaan sebuah pembayaran langsung ke provider.
     *
     * Ini jaring pengaman untuk callback yang tidak pernah sampai — dan
     * callback yang hilang bukan kelainan langka, melainkan kejadian biasa
     * pada jaringan yang tidak sempurna.
     *
     * @throws \App\Services\Payments\Exceptions\PaymentException
     */
    public function verify(
        PaymentProvider $provider,
        PaymentTransaction $transaction
    ): PaymentResult;

    /**
     * Baca callback jadi bentuk yang seragam.
     *
     * Tanda tangan WAJIB sudah diverifikasi di dalam method ini, dan
     * kegagalannya dilempar — bukan dikembalikan sebagai hasil dengan penanda.
     * Callback yang tidak sah tidak boleh punya jalan apa pun untuk terus
     * diproses.
     *
     * @param  array<string,mixed>  $payload  isi body callback
     * @param  array<string,string>  $headers  header, huruf kecil semua
     *
     * @throws \App\Services\Payments\Exceptions\PaymentException
     */
    public function parseCallback(
        PaymentProvider $provider,
        array $payload,
        array $headers = []
    ): PaymentResult;

    /**
     * Batalkan pembayaran yang belum selesai.
     *
     * @throws \App\Services\Payments\Exceptions\PaymentException
     */
    public function cancel(
        PaymentProvider $provider,
        PaymentTransaction $transaction
    ): PaymentResult;

    /**
     * Kembalikan dana.
     *
     * Hanya dipanggil bila `PaymentDriver::supportsRefund()` true. Struktur
     * datanya sudah lengkap di Phase 10; alurnya belum dijalankan dari panel.
     *
     * @throws \App\Services\Payments\Exceptions\PaymentException
     */
    public function refund(
        PaymentProvider $provider,
        PaymentTransaction $transaction,
        ?float $amount = null
    ): PaymentResult;
}
