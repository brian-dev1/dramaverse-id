<?php

namespace App\Services\Telegram;

use App\Models\Episode;
use App\Models\TelegramDelivery;
use App\Models\User;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mencatat video yang dikirim bot, dan menariknya kembali saat tidak lagi
 * berhak ditonton.
 *
 * ## Kenyataan yang tidak bisa diubah
 *
 * Bot Telegram hanya boleh menghapus pesannya sendiri, dan hanya bila usia
 * pesan itu kurang dari 48 jam. Setelah lewat, `deleteMessage` mengembalikan
 * "message can't be deleted" selamanya. Tidak ada endpoint lain, tidak ada
 * izin admin yang membukanya untuk chat pribadi.
 *
 * Konsekuensinya jujur: penarikan kembali bersifat *best-effort*. Yang
 * membuatnya nyaris selalu berhasil bukan trik penghapusan, melainkan
 * kebijakan masa hidup — `telegram.retention.ttl_hours`. Bila setiap video
 * premium memang sudah dijadwalkan hilang 24 jam setelah dikirim, maka pada
 * saat VIP seseorang berakhir hampir tidak ada lagi yang tertinggal untuk
 * dihapus.
 *
 * ## Kenapa kegagalan tidak disembunyikan
 *
 * Baris yang tidak bisa dihapus ditandai `too_old`, bukan dihapus dari tabel
 * atau di-retry diam-diam. Admin yang melihat "12 video tidak bisa ditarik"
 * bisa mengambil keputusan. Admin yang melihat "pembersihan selesai" padahal
 * ada 12 video premium masih beredar tidak bisa.
 */
class TelegramRetentionService
{
    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Pencatatan
    |--------------------------------------------------------------------------
    */

    /**
     * Catat satu video yang baru saja terkirim.
     *
     * Dipanggil TEPAT setelah `sendVideo` berhasil. Tidak boleh melempar:
     * pengguna sudah menerima videonya, dan gagalnya pencatatan tidak boleh
     * berubah menjadi pesan galat di layar mereka.
     */
    public function catat(
        int|string $chatId,
        ?int $messageId,
        ?User $user,
        Episode $episode,
        bool $isPremium
    ): ?TelegramDelivery {

        // Telegram sesekali tidak mengembalikan message_id (mis. saat
        // pengiriman diproksikan). Tanpa itu, pesannya memang tidak akan
        // pernah bisa dihapus — dicatat sebagai peringatan supaya terlihat
        // kalau frekuensinya naik.
        if ($messageId === null) {
            Log::warning('telegram.retention.no_message_id', [
                'chat_id'    => $chatId,
                'episode_id' => $episode->id,
            ]);

            return null;
        }

        $ttl = (int) config('telegram.retention.ttl_hours', 0);

        try {
            return TelegramDelivery::updateOrCreate(
                ['chat_id' => (int) $chatId, 'message_id' => $messageId],
                [
                    'user_id'    => $user?->id,
                    'episode_id' => $episode->id,
                    'is_premium' => $isPremium,
                    'sent_at'    => now(),

                    // Hanya video premium yang punya umur. Episode gratis
                    // tidak perlu ditarik dan tidak boleh ikut terhapus —
                    // menghapusnya hanya merusak riwayat chat pengguna tanpa
                    // melindungi apa pun.
                    'delete_after' => ($isPremium && $ttl > 0)
                        ? now()->addHours($ttl)
                        : null,

                    'delete_status' => $isPremium
                        ? TelegramDelivery::PENDING
                        : TelegramDelivery::SKIPPED,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('telegram.retention.record_failed', [
                'chat_id' => $chatId,
                'pesan'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Penarikan kembali
    |--------------------------------------------------------------------------
    */

    /**
     * Tarik seluruh video premium milik satu pengguna.
     *
     * Dipanggil saat langganannya berakhir.
     *
     * @return array{dihapus:int, terlalu_tua:int, gagal:int}
     */
    public function tarikMilikPengguna(int $userId): array
    {
        if (! config('telegram.retention.on_expire', true)) {
            return ['dihapus' => 0, 'terlalu_tua' => 0, 'gagal' => 0];
        }

        $baris = TelegramDelivery::query()
            ->where('user_id', $userId)
            ->where('is_premium', true)
            ->where('delete_status', TelegramDelivery::PENDING)
            ->orderByDesc('sent_at')
            ->limit((int) config('telegram.retention.batch', 200))
            ->get();

        return $this->hapusSemua($baris, 'vip_berakhir');
    }

    /**
     * Jalankan penghapusan terjadwal: baris yang `delete_after`-nya sudah lewat.
     *
     * @return array{dihapus:int, terlalu_tua:int, gagal:int}
     */
    public function jalankanTerjadwal(): array
    {
        $baris = TelegramDelivery::query()
            ->where('delete_status', TelegramDelivery::PENDING)
            ->whereNotNull('delete_after')
            ->where('delete_after', '<=', now())
            ->orderBy('delete_after')
            ->limit((int) config('telegram.retention.batch', 200))
            ->get();

        return $this->hapusSemua($baris, 'kedaluwarsa_ttl');
    }

    /**
     * Tandai baris yang sudah melewati 48 jam tanpa mencoba menghapusnya.
     *
     * Menjaga antrean pending tetap berisi hal-hal yang masih mungkin, supaya
     * angka di panel admin berarti sesuatu dan setiap jalan scheduler tidak
     * membuang panggilan API untuk kegagalan yang sudah pasti.
     */
    public function tandaiTerlaluTua(): int
    {
        return TelegramDelivery::query()
            ->where('delete_status', TelegramDelivery::PENDING)
            ->where('is_premium', true)
            ->where('sent_at', '<', now()->subHours(48))
            ->update([
                'delete_status' => TelegramDelivery::TOO_OLD,
                'delete_error'  => 'Lewat batas 48 jam Telegram',
                'updated_at'    => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * @param  \Illuminate\Support\Collection<int,TelegramDelivery>  $baris
     * @return array{dihapus:int, terlalu_tua:int, gagal:int}
     */
    private function hapusSemua($baris, string $alasan): array
    {
        $hasil = ['dihapus' => 0, 'terlalu_tua' => 0, 'gagal' => 0];

        foreach ($baris as $satu) {

            if (! $satu->bisaDihapus()) {
                $satu->forceFill([
                    'delete_status' => TelegramDelivery::TOO_OLD,
                    'delete_error'  => 'Lewat batas 48 jam Telegram',
                ])->save();

                $hasil['terlalu_tua']++;

                continue;
            }

            try {
                $this->telegram->deleteMessage($satu->chat_id, $satu->message_id);

                $satu->forceFill([
                    'delete_status' => TelegramDelivery::DELETED,
                    'deleted_at'    => now(),
                    'delete_error'  => null,
                ])->save();

                $hasil['dihapus']++;

            } catch (Throwable $e) {

                $pesan = $e->getMessage();

                // "message to delete not found" berarti pengguna sudah
                // menghapusnya lebih dulu. Tujuannya tercapai — menandainya
                // gagal hanya akan membuat scheduler mencobanya selamanya.
                $sudahHilang = str_contains(strtolower($pesan), 'not found');

                $satu->forceFill([
                    'delete_status' => $sudahHilang
                        ? TelegramDelivery::DELETED
                        : TelegramDelivery::FAILED,
                    'deleted_at'   => $sudahHilang ? now() : null,
                    'delete_error' => mb_substr($pesan, 0, 250),
                ])->save();

                $sudahHilang ? $hasil['dihapus']++ : $hasil['gagal']++;
            }
        }

        if ($baris->isNotEmpty()) {
            Log::info('telegram.retention.purge', $hasil + ['alasan' => $alasan]);
        }

        return $hasil;
    }
}
