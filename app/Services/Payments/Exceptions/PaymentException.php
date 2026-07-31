<?php

namespace App\Services\Payments\Exceptions;

use RuntimeException;

/**
 * Satu-satunya kegagalan yang dilempar lapisan pembayaran.
 *
 * Pola yang sama dengan `TelegramException` (8.1) dan `StorageEngineException`
 * (7.4): satu jenis untuk ditangkap, dengan pertanyaan siap pakai alih-alih
 * pencocokan potongan kata di dalam pesan.
 *
 * Setiap pesan menyebut apa yang salah DAN langkah berikutnya. Di sistem
 * pembayaran ini lebih penting daripada di tempat lain: yang membacanya sering
 * kali pengguna yang uangnya sudah keluar.
 */
class PaymentException extends RuntimeException
{
    /** Aman ditampilkan ke pengguna akhir. */
    public bool $userSafe = false;

    public static function noProvider(): self
    {
        return new self(
            'Belum ada metode pembayaran yang aktif. Hubungi admin — ini bukan '
            .'kesalahan Anda.'
        );
    }

    public static function providerUnusable(string $nama, string $alasan): self
    {
        return new self("Metode pembayaran `{$nama}` tidak bisa dipakai: {$alasan}");
    }

    public static function driverNotImplemented(string $driver): self
    {
        return new self(
            "Driver pembayaran `{$driver}` masih kerangka. Alur callback dan "
            .'verifikasi tanda tangannya belum pernah diuji dengan akun '
            .'sungguhan, jadi ia sengaja menolak dipakai daripada gagal di '
            .'tengah checkout.'
        );
    }

    public static function planUnavailable(): self
    {
        $e = new self('Paket yang Anda pilih sudah tidak tersedia.');

        $e->userSafe = true;

        return $e;
    }

    public static function alreadyPaid(string $number): self
    {
        $e = new self("Tagihan {$number} sudah lunas.");

        $e->userSafe = true;

        return $e;
    }

    public static function notPayable(string $number): self
    {
        $e = new self(
            "Tagihan {$number} sudah tidak bisa dibayar — kemungkinan sudah "
            .'lewat jatuh tempo atau dibatalkan. Buat pesanan baru.'
        );

        $e->userSafe = true;

        return $e;
    }

    public static function invalidSignature(string $provider): self
    {
        return new self(
            "Tanda tangan callback dari `{$provider}` tidak cocok. Permintaan "
            .'ditolak dan TIDAK diproses.'
        );
    }

    public static function unknownReference(string $reference): self
    {
        return new self("Callback menyebut referensi `{$reference}` yang tidak dikenal.");
    }

    public static function amountMismatch(float $ditagih, float $dibayar): self
    {
        return new self(sprintf(
            'Nominal callback (%s) tidak sama dengan yang ditagih (%s). '
            .'Transaksi tidak diaktifkan dan perlu diperiksa manual.',
            number_format($dibayar, 2),
            number_format($ditagih, 2)
        ));
    }

    public static function illegalTransition(string $dari, string $ke): self
    {
        return new self(
            "Perpindahan status dari `{$dari}` ke `{$ke}` ditolak. Callback yang "
            .'datang terlambat tidak boleh membatalkan pembayaran yang sudah lunas.'
        );
    }

    public static function gatewayFailed(string $provider, string $sebab): self
    {
        return new self("Gateway `{$provider}` menolak permintaan: {$sebab}");
    }

    /** Pesan yang aman ditampilkan ke pengguna akhir. */
    public function forUser(): string
    {
        return $this->userSafe
            ? $this->getMessage()
            : 'Pembayaran tidak bisa diproses saat ini. Coba lagi beberapa saat '
                .'lagi, atau pilih metode pembayaran lain.';
    }
}
