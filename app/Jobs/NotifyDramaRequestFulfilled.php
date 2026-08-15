<?php

namespace App\Jobs;

use App\Models\DramaRequest;
use App\Models\Notification;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Telegram\Notice;
use App\Support\TelegramDeepLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Beri tahu SATU orang bahwa drama yang ia minta sudah tersedia.
 *
 * Bukan broadcast. Yang menerima hanya orang yang memintanya, dan itu
 * pembedaan yang menentukan: pesan dari bot yang tidak diminta membuat orang
 * memblokir botnya, sementara pesan yang menjawab permintaannya sendiri
 * justru ditunggu.
 *
 * ## Dua saluran sekaligus
 *
 * Notifikasi dalam aplikasi dibuat lebih dulu, baru pesan Telegram. Urutannya
 * disengaja: notifikasi in-app adalah catatan yang tidak bisa gagal, sedangkan
 * Telegram bisa menolak karena pengguna memblokir bot atau menghapus chatnya.
 * Kalau Telegram gagal, pemberitahuannya tetap ada saat ia membuka situs.
 *
 * ## Penandaan sekali kirim
 *
 * `notified_at` diisi setelah kedua saluran dicoba, dan diperiksa lagi di awal
 * job. Admin yang bolak-balik mengubah status — tersedia, lalu diproses lagi,
 * lalu tersedia — tidak boleh menghasilkan tiga pesan untuk satu kabar.
 */
class NotifyDramaRequestFulfilled implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $requestId
    ) {
    }

    public function handle(TelegramServiceInterface $telegram): void
    {
        $permintaan = DramaRequest::with(['user', 'drama'])->find($this->requestId);

        if ($permintaan === null || ! $permintaan->bolehDiberiTahu()) {
            return;
        }

        $user = $permintaan->user;

        if ($user === null) {
            return;
        }

        $judul = $permintaan->drama?->title ?? $permintaan->title;

        // --- Notifikasi dalam aplikasi ---
        Notification::create([
            'user_id' => $user->id,
            'title'   => 'Drama yang Anda minta sudah ada',
            'message' => '"'.$judul.'" sekarang bisa ditonton.',
            'type'    => 'drama_request',
            'data'    => [
                'request_id' => $permintaan->id,
                'drama_id'   => $permintaan->drama_id,
                'slug'       => $permintaan->drama?->slug,
            ],
        ]);

        // --- Pesan Telegram ---
        if ($user->telegram_id) {
            $this->kirimTelegram($telegram, $user->telegram_id, $permintaan, $judul);
        }

        $permintaan->forceFill(['notified_at' => now()])->save();
    }

    private function kirimTelegram(
        TelegramServiceInterface $telegram,
        int|string $chatId,
        DramaRequest $permintaan,
        string $judul
    ): void {

        $pesan = Notice::make('🎉', 'Drama yang Anda minta sudah ada')
            ->lead('Permintaan Anda sudah kami penuhi.')
            ->rows([
                'Yang Anda minta' => $permintaan->title,
                'Judul di katalog' => $permintaan->drama?->title !== $permintaan->title
                    ? $permintaan->drama?->title
                    : null,
                'Total part' => $permintaan->drama?->total_episode ?: null,
            ]);

        if (filled($permintaan->admin_note)) {
            $pesan->note($permintaan->admin_note);
        }

        $tombol = [];

        // Tautan ke bot, bukan ke website: yang menerima pesan ini sedang
        // berada di dalam Telegram, dan memindahkannya ke peramban hanya
        // untuk menekan satu tombol lagi adalah langkah yang tidak perlu.
        if ($permintaan->drama !== null && ($url = TelegramDeepLink::drama($permintaan->drama))) {
            $tombol[] = [['text' => '▶️ Tonton '.$judul, 'url' => $url]];
        }

        try {
            $telegram->sendMessage($chatId, $pesan->render(), $tombol === []
                ? []
                : ['reply_markup' => ['inline_keyboard' => $tombol]]);

        } catch (Throwable $e) {

            // Kegagalan Telegram TIDAK membatalkan job. Notifikasi in-app
            // sudah dibuat, dan mengulang seluruh job berarti membuat
            // notifikasi kedua yang isinya sama.
            Log::warning('drama_request.telegram_gagal', [
                'request_id' => $permintaan->id,
                'user_id'    => $permintaan->user_id,
                'sebab'      => $e->getMessage(),
            ]);
        }
    }
}
