<?php

namespace App\Services\Telegram;

use Illuminate\Support\Arr;

/**
 * Jawaban Bot API yang berhasil.
 *
 * Objek ini hanya pernah dibuat untuk jawaban dengan `ok: true`. Kegagalan
 * tidak pernah muncul sebagai objek ini — ia dilempar sebagai
 * TelegramException. Karena itu pemanggil tidak perlu memeriksa apa pun
 * sebelum membaca hasilnya.
 *
 * Sebelum sprint ini setiap pemanggil menerima array mentah dan bebas
 * mengabaikan `ok`. Sepuluh dari sebelas handler memang mengabaikannya:
 * pesan yang tidak terkirim tidak meninggalkan jejak apa pun.
 */
final class TelegramResponse
{
    public function __construct(
        /** Nama method Bot API, misalnya `sendMessage`. */
        public readonly string $method,

        /** Isi field `result`. Bentuknya berbeda-beda tiap method. */
        public readonly mixed $result,

        /** Kode status HTTP. Selalu 200 untuk jawaban yang berhasil. */
        public readonly int $httpStatus,

        /** Lama permintaan dalam milidetik, dibulatkan. */
        public readonly int $durationMs,

        /** Percobaan ke berapa yang akhirnya berhasil. */
        public readonly int $attempts,
    ) {
    }

    /** Hasil sebagai array. Array kosong bila `result` bukan array. */
    public function array(): array
    {
        return is_array($this->result) ? $this->result : [];
    }

    /** Ambil satu field dari hasil, mendukung notasi titik. */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->array(), $key, $default);
    }

    /**
     * id pesan yang baru dikirim atau disunting.
     *
     * Null untuk method yang tidak mengembalikan pesan (deleteMessage,
     * answerCallbackQuery), dan juga untuk editMessage pada pesan inline —
     * Telegram menjawabnya dengan `true`, bukan objek pesan.
     */
    public function messageId(): ?int
    {
        $id = $this->get('message_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Bentuk lama `['ok' => true, 'result' => ...]`. */
    public function toArray(): array
    {
        return [
            'ok'     => true,
            'result' => $this->result,
        ];
    }

    /** Konteks siap-log. Tidak memuat token maupun isi pesan. */
    public function logContext(): array
    {
        return [
            'method'      => $this->method,
            'http_status' => $this->httpStatus,
            'duration_ms' => $this->durationMs,
            'attempts'    => $this->attempts,
        ];
    }
}
