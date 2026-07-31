<?php

namespace App\Services\Payments\Exceptions;

/**
 * Webhook uji coba dari dashboard provider — bukan pembayaran sungguhan.
 *
 * ## Kenapa ini exception, bukan nilai balik
 *
 * `parseCallback()` mengembalikan `PaymentResult` yang selalu berarti "ada
 * pembayaran". Payload uji bukan pembayaran, jadi memaksakannya ke bentuk itu
 * berarti setiap pemanggil harus memeriksa penanda tambahan — dan yang lupa
 * memeriksanya akan mengaktifkan membership dari tombol Test.
 *
 * Dijadikan turunan `PaymentException` supaya jalur `catch` yang sudah ada
 * tetap menangkapnya. Yang membedakan hanya `PaymentCallbackController`, yang
 * menangkapnya LEBIH DULU dan menjawab **200** — karena dari sudut pandang
 * dashboard provider, uji coba yang tokennya cocok memang berhasil.
 *
 * Menjawab 400 di situ membuat tombol "Send Webhook Test" selalu tampak gagal
 * meski seluruh pemasangannya sudah benar, dan itu menyesatkan justru pada
 * saat orang paling butuh kepastian.
 */
class WebhookTestException extends PaymentException
{
    public static function berhasil(string $provider): self
    {
        return new self(
            "Webhook `{$provider}` tersambung dan tokennya cocok. Ini payload uji "
            .'coba, jadi tidak ada tagihan yang diproses. Pembayaran sungguhan '
            .'akan diproses selama nomor tagihan ikut di kolom pesan.'
        );
    }
}
