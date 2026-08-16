<?php

namespace App\Services\Telegram;

use App\Models\ChannelAnnouncement;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengirim pengumuman bebas ke channel Telegram.
 *
 * Berbeda dari `ChannelPostService` yang menyusun katalog drama: di sini
 * seluruh isinya diketik admin. Yang dikerjakan kelas ini cuma tiga hal —
 * memilih bentuk pesannya (foto bercaption atau teks biasa), memasang tombol
 * tautan, dan mencatat hasilnya.
 *
 * ## Batas panjang menentukan bentuk pesan
 *
 * Caption foto maksimal 1024 karakter terlihat, pesan teks 4096. Pengumuman
 * bergambar yang tulisannya panjang karena itu tidak bisa jadi satu pesan.
 * Yang dilakukan: fotonya dikirim sendirian, tulisannya menyusul sebagai
 * pesan teks — bukan captionnya dipotong diam-diam, yang menghasilkan
 * pengumuman terpenggal di tengah kalimat tanpa ada yang tahu.
 *
 * Perhitungan panjangnya meminjam `ChannelPostService::panjangTelegram()`,
 * bukan `mb_strlen()`. Telegram menghitung teks yang TERLIHAT — tag HTML dan
 * URL di dalam href tidak ikut — dan selisihnya besar untuk tulisan yang
 * banyak tautannya.
 *
 * ## Tombol selalu menempel pada pesan yang berisi tulisannya
 *
 * Bukan pada fotonya. Pada pengumuman yang terpecah dua, tombol di bawah
 * foto berarti tombol yang muncul sebelum kalimat yang menjelaskannya.
 */
class ChannelAnnouncementService
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected ChannelPostService $channel
    ) {
    }

    /**
     * Alasan kenapa pengumuman belum bisa dikirim, atau null bila siap.
     *
     * Sengaja TIDAK memakai `ChannelPostService::penghalang()`. Yang di sana
     * ikut mensyaratkan TELEGRAM_BOT_USERNAME karena katalog membutuhkan
     * tautan "Tonton Sekarang" ke bot. Pengumuman tidak membuat tautan apa
     * pun sendiri — tombolnya diketik admin — jadi menolaknya dengan alasan
     * itu berarti menolak sesuatu yang sebenarnya bisa dikirim.
     */
    public function penghalang(): ?string
    {
        if (! $this->telegram->isConfigured()) {
            return 'Token bot belum diisi di .env (TELEGRAM_BOT_TOKEN).';
        }

        if ($this->channel->chatId() === null) {
            return 'Chat ID channel belum diisi di Pengaturan → Channel Telegram.';
        }

        return null;
    }

    public function chatId(): ?string
    {
        return $this->channel->chatId();
    }

    /** Batas caption foto, dipakai form admin untuk memperingatkan lebih awal. */
    public function batasCaption(): int
    {
        return $this->channel->batasCaption();
    }

    public function panjang(string $teks): int
    {
        return $this->channel->panjangTelegram($teks);
    }

    /**
     * Kirim satu pengumuman.
     *
     * Selalu memperbarui barisnya, berhasil maupun gagal. Pengumuman yang
     * gagal tanpa jejak berarti admin menekan Kirim, tidak melihat apa pun di
     * channel, dan tidak punya cara mencari tahu sebabnya.
     */
    public function kirim(ChannelAnnouncement $pengumuman): ChannelAnnouncement
    {
        if ($alasan = $this->penghalang()) {
            return $this->simpan($pengumuman, [
                'status' => ChannelAnnouncement::STATUS_FAILED,
                'error'  => $alasan,
            ]);
        }

        $chatId = $this->chatId();

        $tombol = $this->markup($pengumuman->buttons ?? []);

        $foto = $this->foto($pengumuman);

        try {
            $id = null;

            $muatDiCaption = $this->panjang($pengumuman->body) <= $this->batasCaption();

            if ($foto !== null && $muatDiCaption) {

                $id = $this->telegram
                    ->sendPhoto($chatId, $foto, $pengumuman->body, [
                        'parse_mode' => 'HTML',
                    ] + $tombol)
                    ->messageId();

            } elseif ($foto !== null) {

                // Foto lebih dulu, tanpa caption. Tanpa tombol pula: tombolnya
                // ikut pesan berikutnya, yang berisi tulisannya.
                $this->telegram->sendPhoto($chatId, $foto, '');

                $id = $this->kirimTeks($chatId, $pengumuman->body, $tombol);

            } else {
                $id = $this->kirimTeks($chatId, $pengumuman->body, $tombol);
            }

            return $this->simpan($pengumuman, [
                'status'     => ChannelAnnouncement::STATUS_SENT,
                'chat_id'    => (string) $chatId,
                'message_id' => $id,
                'sent_at'    => now(),
                'error'      => null,
            ]);

        } catch (Throwable $e) {

            Log::error('channel.announcement_failed', [
                'pengumuman_id' => $pengumuman->id,
                'chat_id'       => $chatId,
                'sebab'         => $e->getMessage(),
            ]);

            return $this->simpan($pengumuman, [
                'status'  => ChannelAnnouncement::STATUS_FAILED,
                'chat_id' => (string) $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Pesan teks pengumuman, beserta tombolnya. */
    private function kirimTeks(string $chatId, string $teks, array $tombol): ?int
    {
        return $this->telegram
            ->sendMessage($chatId, $teks, [
                'parse_mode' => 'HTML',

                // Pratinjau tautan mengambil gambar dari URL pertama yang
                // ditemukannya di dalam tulisan, dan hasilnya menempel di
                // bawah pengumuman sebagai kartu yang tidak pernah diminta
                // siapa pun. Pengumuman bergambar bahkan jadi dua gambar.
                'disable_web_page_preview' => true,
            ] + $tombol)
            ->messageId();
    }

    /**
     * Tombol tautan menjadi `reply_markup`, atau array kosong bila tidak ada.
     *
     * Satu tombol satu baris. Telegram memang bisa menaruh beberapa tombol
     * berdampingan, tapi label berbahasa Indonesia cepat terpotong di layar
     * ponsel sempit — dan tombol yang tulisannya "Gabung VIP Sek…" tidak
     * lebih baik daripada tombol yang turun ke bawah.
     *
     * @param  array<int,array{label?:string,url?:string}>  $tombol
     * @return array<string,mixed>
     */
    private function markup(array $tombol): array
    {
        $baris = [];

        foreach ($tombol as $satu) {

            $label = trim((string) ($satu['label'] ?? ''));

            $url = trim((string) ($satu['url'] ?? ''));

            if ($label === '' || $url === '') {
                continue;
            }

            $baris[] = [['text' => $label, 'url' => $url]];
        }

        return $baris === [] ? [] : ['reply_markup' => ['inline_keyboard' => $baris]];
    }

    /**
     * Berkas gambar di disk, atau null.
     *
     * Diunggah langsung, bukan diberikan sebagai URL — alasannya sama dengan
     * poster katalog: Telegram yang mengambil dari URL mensyaratkan servernya
     * bisa menjangkau situs kita, dan itu gagal diam-diam di localhost, di
     * balik Cloudflare, atau ketika APP_URL belum benar.
     *
     * Gambar yang barisnya ada tapi berkasnya hilang TIDAK membatalkan
     * pengumuman. Yang penting sampai adalah tulisannya; kehilangan gambar
     * dicatat di log, bukan diubah jadi kegagalan.
     */
    private function foto(ChannelAnnouncement $pengumuman): ?\SplFileInfo
    {
        if (blank($pengumuman->image)) {
            return null;
        }

        $path = Storage::disk('public')->path((string) $pengumuman->image);

        if (! is_file($path)) {

            Log::warning('channel.announcement_image_missing', [
                'pengumuman_id' => $pengumuman->id,
                'path'          => $pengumuman->image,
            ]);

            return null;
        }

        return new \SplFileInfo($path);
    }

    private function simpan(ChannelAnnouncement $pengumuman, array $nilai): ChannelAnnouncement
    {
        $pengumuman->forceFill($nilai)->save();

        return $pengumuman;
    }
}
