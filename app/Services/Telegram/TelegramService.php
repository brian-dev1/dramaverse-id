<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Contracts\TelegramClientInterface;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use SplFileInfo;

/**
 * Pembungkus Bot API. Lihat TelegramServiceInterface untuk aturan dan
 * alasannya.
 *
 * Kelas ini sengaja tidak memuat satu pun panggilan HTTP: seluruh angkutan
 * ada di TelegramClient. Yang dikerjakan di sini hanya menyusun parameter
 * yang benar untuk tiap method Bot API.
 */
class TelegramService implements TelegramServiceInterface
{
    public function __construct(
        protected TelegramClientInterface $client
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pesan
    |--------------------------------------------------------------------------
    */

    public function sendMessage(int|string $chatId, string $text, array $options = []): TelegramResponse
    {
        return $this->client->call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $this->parseMode(),
        ], $options));
    }

    public function sendPhoto(int|string $chatId, string|SplFileInfo $photo, string $caption = '', array $options = []): TelegramResponse
    {
        return $this->media('sendPhoto', 'photo', $chatId, $photo, $caption, $options);
    }

    public function sendVideo(int|string $chatId, string|SplFileInfo $video, string $caption = '', array $options = []): TelegramResponse
    {
        return $this->media('sendVideo', 'video', $chatId, $video, $caption, $options);
    }

    public function sendDocument(int|string $chatId, string|SplFileInfo $document, string $caption = '', array $options = []): TelegramResponse
    {
        return $this->media('sendDocument', 'document', $chatId, $document, $caption, $options);
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, array $options = []): TelegramResponse
    {
        return $this->client->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $this->parseMode(),
        ], $options));
    }

    public function deleteMessage(int|string $chatId, int $messageId): TelegramResponse
    {
        return $this->client->call('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', array $options = []): TelegramResponse
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        // Telegram menolak `text` kosong, jadi field-nya tidak disertakan
        // sama sekali kalau memang tidak ada yang mau ditampilkan.
        if ($text !== '') {
            $payload['text'] = $text;
        }

        return $this->client->call('answerCallbackQuery', array_merge($payload, $options));
    }

    /*
    |--------------------------------------------------------------------------
    | Berkas dan bot
    |--------------------------------------------------------------------------
    */

    public function getFile(string $fileId): TelegramResponse
    {
        return $this->client->get('getFile', ['file_id' => $fileId]);
    }

    public function getMe(): TelegramResponse
    {
        return $this->client->get('getMe');
    }

    /*
    |--------------------------------------------------------------------------
    | Serba guna
    |--------------------------------------------------------------------------
    */

    public function call(string $method, array $parameters = [], array $files = []): TelegramResponse
    {
        return $this->client->call($method, $parameters, $files);
    }

    public function query(string $method, array $query = []): TelegramResponse
    {
        return $this->client->get($method, $query);
    }

    /*
    |--------------------------------------------------------------------------
    | Keterangan
    |--------------------------------------------------------------------------
    */

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function botUsername(): ?string
    {
        $username = trim((string) config('telegram.bot_username'));

        return $username === '' ? null : ltrim($username, '@');
    }

    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;

        $clone->client = $this->client->withTimeout($seconds);

        return $clone;
    }

    public function withRetries(int $times): static
    {
        $clone = clone $this;

        $clone->client = $this->client->withRetries($times);

        return $clone;
    }

    public function downloadUrl(string $filePath): string
    {
        return $this->client->downloadUrl($filePath);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Susun panggilan pengiriman berkas.
     *
     * Satu method untuk sendPhoto, sendVideo, dan sendDocument karena
     * bentuknya persis sama dan hanya nama field yang berbeda. Menulisnya
     * tiga kali berarti tiga tempat yang harus diingat saat ada yang berubah.
     *
     * Berkas di disk (SplFileInfo) dikirim multipart; string dikirim apa
     * adanya dan ditafsirkan Telegram sebagai URL atau file_id.
     */
    protected function media(
        string $method,
        string $field,
        int|string $chatId,
        string|SplFileInfo $sumber,
        string $caption,
        array $options
    ): TelegramResponse {

        $payload = ['chat_id' => $chatId];

        // Caption kosong tidak dikirim: Telegram memperlakukan caption ''
        // sebagai caption yang ada tapi kosong, dan sebagian klien
        // menampilkan ruang kosong di bawah gambar karenanya.
        if ($caption !== '') {
            $payload['caption'] = $caption;

            $payload['parse_mode'] = $this->parseMode();
        }

        $files = [];

        if ($sumber instanceof SplFileInfo) {
            $files[$field] = $sumber;
        } else {
            $payload[$field] = $sumber;
        }

        return $this->client->call($method, array_merge($payload, $options), $files);
    }

    protected function parseMode(): string
    {
        return (string) config('telegram.parse_mode', 'HTML');
    }
}
