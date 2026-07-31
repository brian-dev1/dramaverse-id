<?php

namespace App\Services\Telegram\Contracts;

use App\Services\Telegram\TelegramResponse;

/**
 * Lapisan angkut ke Bot API — dan satu-satunya tempat di seluruh proyek
 * yang boleh membuka koneksi HTTP ke Telegram.
 *
 * Tanggung jawabnya sengaja sempit: menyusun URL, mengirim, mengulang bila
 * pantas diulang, mencatat, dan mengubah kegagalan jadi TelegramException.
 * Ia tidak tahu apa itu `sendMessage`, tidak tahu apa itu chat, dan tidak
 * punya pendapat soal parse mode.
 *
 * Pemisahan ini bukan kerapian belaka. Sebelum Sprint 8.1 ada TIGA jalur
 * HTTP ke Telegram di dalam proyek ini — dua service dan satu repository,
 * masing-masing dengan token, timeout, dan penanganan galat sendiri; salah
 * satunya bahkan membaca kunci config yang berbeda. Ketiganya harus
 * disunting setiap kali ada yang perlu diubah, dan yang terlewat baru
 * ketahuan saat pesan berhenti terkirim.
 *
 * ## Yang memakainya
 *
 * Hanya TelegramServiceInterface. Modul lain — controller, job, handler —
 * memanggil service, bukan client ini. Aturannya sama persis dengan
 * StorageEngineInterface terhadap Storage.
 */
interface TelegramClientInterface
{
    /**
     * Panggil satu method Bot API lewat POST.
     *
     * @param  string  $method  nama method Bot API, misalnya `sendMessage`
     * @param  array<string,mixed>  $parameters  parameter method
     * @param  array<string,SplFileInfo>  $files  berkas yang diunggah;
     *                                            keberadaannya mengubah
     *                                            permintaan jadi multipart
     *
     * @throws \App\Services\Telegram\Exceptions\TelegramException
     */
    public function call(string $method, array $parameters = [], array $files = []): TelegramResponse;

    /**
     * Panggil method Bot API lewat GET.
     *
     * Dipakai untuk method yang hanya membaca (getMe, getFile,
     * getWebhookInfo). Bedanya bukan gaya: GET tidak pernah mengubah apa pun,
     * jadi mengulangnya setelah koneksi putus tidak berisiko menduakan
     * tindakan.
     *
     * @param  array<string,mixed>  $query
     *
     * @throws \App\Services\Telegram\Exceptions\TelegramException
     */
    public function get(string $method, array $query = []): TelegramResponse;

    /**
     * Apakah token bot sudah terisi.
     *
     * Tidak menyentuh jaringan. Untuk memastikan tokennya benar-benar
     * diterima Telegram, panggil getMe().
     */
    public function isConfigured(): bool;

    /**
     * Salinan client dengan batas waktu berbeda, dalam detik.
     *
     * Client aslinya tidak berubah — ia singleton dan dipakai bersama.
     * Dipakai untuk pengiriman berkas besar, yang butuh waktu jauh lebih
     * panjang daripada sendMessage tanpa harus melonggarkan batas untuk
     * semua permintaan lain.
     */
    public function withTimeout(int $seconds): static;

    /**
     * Salinan client dengan jumlah percobaan berbeda. 1 berarti tanpa ulang.
     *
     * Dipakai oleh jalur yang sedang ditunggu orang: pada halaman admin,
     * mengulang hanya melipatgandakan waktu tunggu sebelum kegagalan yang
     * sama tetap muncul.
     */
    public function withRetries(int $times): static;

    /**
     * URL unduh untuk `file_path` yang didapat dari getFile().
     *
     * PERHATIAN: URL ini memuat token bot. Ia tidak boleh dikirim ke
     * peramban, ditulis ke log, atau disimpan di database. Pakai di sisi
     * server saja, lalu buang.
     */
    public function downloadUrl(string $filePath): string;
}
