<?php

namespace App\Services\Telegram\Contracts;

use App\Services\Telegram\TelegramResponse;
use SplFileInfo;

/**
 * Telegram Core Service — pusat seluruh komunikasi DramaVerse dengan
 * Telegram Bot API.
 *
 * Aturannya satu dan tidak ada pengecualian: **controller tidak boleh
 * memanggil Telegram API secara langsung.** Controller, job, handler, dan
 * command memanggil kontrak ini. Tidak ada satu pun `Http::` ke
 * api.telegram.org di luar TelegramClient.
 *
 * Bentuknya sengaja dibuat sama dengan StorageEngineInterface, dengan alasan
 * yang sama: selama pengetahuan tentang cara memanggil Telegram tersebar di
 * belasan berkas, mengubah satu hal — timeout, parse mode, penanganan batas
 * laju — berarti menyunting semuanya, dan yang terlewat baru ketahuan saat
 * pesan berhenti terkirim.
 *
 * ## Kegagalan dilempar, bukan dikembalikan
 *
 * Setiap method mengembalikan TelegramResponse yang sudah pasti berhasil,
 * atau melempar TelegramException. Tidak ada jalan tengah, dan tidak ada
 * nilai balik yang perlu diperiksa dulu.
 *
 * Ini perubahan yang disengaja dari keadaan sebelumnya, ketika semua method
 * mengembalikan array mentah: sepuluh dari sebelas pemanggil tidak pernah
 * memeriksa `ok`, sehingga pesan yang gagal terkirim tidak meninggalkan
 * jejak sama sekali. Kegagalan yang wajar terjadi — pengguna memblokir bot —
 * ditanyakan pada exception-nya:
 *
 * ```php
 * try {
 *     $telegram->sendMessage($chatId, $teks);
 * } catch (TelegramException $e) {
 *     if ($e->isBlockedByUser()) {
 *         // berhenti mengirim ke chat ini
 *     }
 * }
 * ```
 *
 * ## Berkas
 *
 * sendPhoto, sendVideo, dan sendDocument menerima tiga bentuk pada argumen
 * berkasnya:
 *
 * - `string` berupa URL — Telegram yang mengunduhnya
 * - `string` berupa file_id — berkas yang sudah pernah dikirim ke Telegram
 * - `SplFileInfo` — berkas di disk server, diunggah multipart
 *
 * Menyimpan `file_id` untuk dipakai ulang BUKAN bagian sprint ini.
 */
interface TelegramServiceInterface
{
    /*
    |--------------------------------------------------------------------------
    | Pesan
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string,mixed>  $options  parameter Bot API tambahan,
     *                                        misalnya reply_markup atau
     *                                        disable_notification
     *
     * @throws TelegramException
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): TelegramResponse;

    /**
     * @param  string|SplFileInfo  $photo  URL, file_id, atau berkas di disk
     *
     * @throws TelegramException
     */
    public function sendPhoto(int|string $chatId, string|SplFileInfo $photo, string $caption = '', array $options = []): TelegramResponse;

    /**
     * @param  string|SplFileInfo  $video  URL, file_id, atau berkas di disk
     *
     * @throws TelegramException
     */
    public function sendVideo(int|string $chatId, string|SplFileInfo $video, string $caption = '', array $options = []): TelegramResponse;

    /**
     * @param  string|SplFileInfo  $document  URL, file_id, atau berkas di disk
     *
     * @throws TelegramException
     */
    public function sendDocument(int|string $chatId, string|SplFileInfo $document, string $caption = '', array $options = []): TelegramResponse;

    /**
     * Ubah teks pesan yang sudah terkirim.
     *
     * @throws TelegramException
     */
    public function editMessage(int|string $chatId, int $messageId, string $text, array $options = []): TelegramResponse;

    /**
     * Telegram hanya mengizinkan penghapusan pesan bot yang usianya di bawah
     * 48 jam. Lewat dari itu, panggilan ini gagal dan itu bukan kesalahan
     * kode.
     *
     * @throws TelegramException
     */
    public function deleteMessage(int|string $chatId, int $messageId): TelegramResponse;

    /**
     * Hentikan animasi tunggu pada tombol inline.
     *
     * Panggil ini SEGERA setelah callback diterima, sebelum pekerjaan yang
     * lama. Telegram menampilkan tombol dalam keadaan berputar sampai
     * panggilan ini datang, dan menyerah setelah beberapa detik.
     *
     * @throws TelegramException
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', array $options = []): TelegramResponse;

    /*
    |--------------------------------------------------------------------------
    | Berkas dan bot
    |--------------------------------------------------------------------------
    */

    /**
     * Metadata berkas di server Telegram, termasuk `file_path`.
     *
     * `file_path` hanya berlaku sekitar satu jam. Jangan disimpan di
     * database — ambil ulang saat dibutuhkan.
     *
     * @throws TelegramException
     */
    public function getFile(string $fileId): TelegramResponse;

    /**
     * Keanggotaan seseorang di satu chat.
     *
     * Bot harus admin di chat itu. Bila tidak, ini MELEMPAR — bukan
     * mengembalikan "bukan anggota". Pemanggil wajib membedakan keduanya;
     * lihat ChannelGate.
     *
     * @throws TelegramException
     */
    public function getChatMember(int|string $chatId, int|string $userId): TelegramResponse;

    /**
     * Identitas bot. Panggilan paling murah untuk membuktikan token benar
     * dan jaringan tersambung.
     *
     * @throws TelegramException
     */
    public function getMe(): TelegramResponse;

    /*
    |--------------------------------------------------------------------------
    | Serba guna
    |--------------------------------------------------------------------------
    */

    /**
     * Panggil method Bot API apa pun yang belum punya pembungkus sendiri.
     *
     * Ini yang membuat pembungkus di atas tetap ramping tanpa memaksa
     * pemanggil kembali menyentuh HTTP. Bot API punya lebih dari seratus
     * method dan menulis pembungkus untuk semuanya lebih dulu berarti
     * merawat kode yang tidak pernah dipakai.
     *
     * @param  array<string,mixed>  $parameters
     * @param  array<string,SplFileInfo>  $files
     *
     * @throws TelegramException
     */
    public function call(string $method, array $parameters = [], array $files = []): TelegramResponse;

    /**
     * Method Bot API yang hanya membaca, lewat GET.
     *
     * @param  array<string,mixed>  $query
     *
     * @throws TelegramException
     */
    public function query(string $method, array $query = []): TelegramResponse;

    /*
    |--------------------------------------------------------------------------
    | Keterangan
    |--------------------------------------------------------------------------
    */

    /** Apakah token sudah terisi. Tidak menyentuh jaringan. */
    public function isConfigured(): bool;

    /** Username bot tanpa tanda @, atau null bila belum diisi. */
    public function botUsername(): ?string;

    /**
     * Salinan service dengan batas waktu berbeda, dalam detik. Dipakai untuk
     * pengiriman berkas besar.
     */
    public function withTimeout(int $seconds): static;

    /**
     * Salinan service dengan jumlah percobaan berbeda. 1 berarti tanpa ulang.
     *
     * Dipakai jalur yang sedang ditunggu orang, misalnya halaman admin.
     */
    public function withRetries(int $times): static;

    /**
     * URL unduh untuk `file_path` dari getFile().
     *
     * PERHATIAN: memuat token bot. Jangan dikirim ke peramban, ditulis ke
     * log, atau disimpan di database.
     */
    public function downloadUrl(string $filePath): string;

    /**
     * Letak berkas di disk mesin ini, bila Local Bot API Server menyimpannya
     * di sini. Null berarti harus diunduh lewat downloadUrl().
     *
     * Berbeda dengan downloadUrl(), nilai ini aman dicatat di log.
     */
    public function localPath(string $filePath): ?string;
}
