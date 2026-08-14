<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Telegram\Notice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Penjaga "gabung channel dulu".
 *
 * ## Kenapa penjagaannya memihak pengguna saat ragu
 *
 * `getChatMember` menjawab tiga hal yang berbeda, dan hanya dua di antaranya
 * tentang penggunanya:
 *
 * 1. dia anggota — boleh lewat;
 * 2. dia bukan anggota — ditahan;
 * 3. **pertanyaannya sendiri tidak bisa dijawab** — bot dicopot dari admin
 *    channel, channel diganti nama, id-nya salah ketik, Telegram sedang
 *    bermasalah.
 *
 * Keadaan ketiga adalah kegagalan KITA, bukan pelanggaran pengguna.
 * Memperlakukannya sama dengan "bukan anggota" berarti satu kesalahan
 * konfigurasi mengunci seluruh pengguna sekaligus — termasuk yang sudah
 * membayar, dan termasuk yang benar-benar sudah bergabung. Yang terlihat di
 * layar mereka adalah tuduhan bahwa mereka belum gabung, padahal sudah.
 *
 * Jadi keadaan ketiga dibiarkan lewat, dan dicatat sebagai peringatan supaya
 * ada yang memperbaikinya. Penjagaan ini alat pemasaran, bukan pengaman: yang
 * dijaganya adalah jumlah anggota channel, bukan uang atau data. Salah tolak
 * jauh lebih mahal daripada salah loloskan.
 *
 * ## Kenapa hasilnya di-cache
 *
 * Tanpa cache, setiap ketukan tombol tonton berarti satu panggilan Bot API
 * tambahan sebelum apa pun terjadi — dan itu terasa sebagai bot yang lambat
 * pada tindakan yang paling sering dilakukan. Cache-nya dibuang begitu
 * pengguna menekan "Saya sudah gabung", jadi yang baru bergabung tidak
 * menunggu masa berlakunya habis.
 */
class ChannelGate
{
    /** Callback tombol "Saya sudah gabung". */
    public const RECHECK = 'joined';

    /**
     * Status yang dihitung sebagai anggota.
     *
     * `restricted` masuk hanya bila `is_member` benar — orang yang dibisukan
     * di channel tetap anggota. `left` dan `kicked` di luar daftar.
     */
    private const ANGGOTA = ['creator', 'administrator', 'member'];

    public function __construct(
        protected TelegramServiceInterface $telegram
    ) {
    }

    /** Penjagaan ini dinyalakan di config. */
    public function aktif(): bool
    {
        return filled($this->channel());
    }

    /**
     * Pengguna boleh melanjutkan.
     *
     * True juga ketika penjagaannya mati, ketika penggunanya belum dikenali,
     * dan ketika pemeriksaannya gagal — lihat docblock kelas.
     */
    public function lolos(?User $user): bool
    {
        if (! $this->aktif() || $user === null || blank($user->telegram_id)) {
            return true;
        }

        return Cache::remember(
            $this->kunci($user),
            max(30, (int) config('telegram.required_channel_ttl', 300)),
            fn () => $this->periksa($user)
        );
    }

    /** Buang hasil pemeriksaan yang tersimpan untuk satu pengguna. */
    public function lupakan(?User $user): void
    {
        if ($user !== null) {
            Cache::forget($this->kunci($user));
        }
    }

    /**
     * Pesan penahan beserta tombolnya.
     *
     * Dua tombol dan keduanya perlu: yang pertama membawa orang ke channel,
     * yang kedua memberinya cara kembali tanpa mengulang dari awal. Tanpa
     * tombol kedua, satu-satunya jalan adalah menekan ulang tombol tonton
     * yang sudah tergulir jauh ke atas — dan sebagian orang berhenti di situ.
     *
     * @return array{0:string,1:array}
     */
    public function penahan(string $tindakan = 'melanjutkan'): array
    {
        $pesan = Notice::make('📢', 'Gabung channel dulu')
            ->lead('Channel kami adalah tempat pengumuman rilis baru dan pemberitahuan '
                .'kalau bot sedang bermasalah.')
            ->text('Gabung sekali, lalu tekan tombol di bawahnya. Setelah itu Anda bisa '
                .'langsung '.$tindakan.'.')
            ->note('Sudah gabung tapi masih tertahan? Tunggu sebentar lalu tekan '
                .'"Saya sudah gabung" sekali lagi.')
            ->render();

        $tombol = [];

        if (filled($url = $this->url())) {
            $tombol[] = [['text' => '📢 Gabung Channel', 'url' => $url]];
        }

        $tombol[] = [['text' => '✅ Saya sudah gabung', 'callback_data' => self::RECHECK]];

        return [$pesan, ['reply_markup' => ['inline_keyboard' => $tombol]]];
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /** Satu panggilan Bot API. Tidak pernah melempar. */
    private function periksa(User $user): bool
    {
        try {
            $status = (string) $this->telegram
                ->withRetries(1)
                ->getChatMember($this->channel(), $user->telegram_id)
                ->get('status', '');

            if ($status === 'restricted') {
                return (bool) $this->telegram
                    ->withRetries(1)
                    ->getChatMember($this->channel(), $user->telegram_id)
                    ->get('is_member', false);
            }

            return in_array($status, self::ANGGOTA, true);

        } catch (Throwable $e) {

            // Pengguna dibiarkan lewat. Yang salah ada di sisi kita, dan
            // menutup akses karenanya menghukum orang yang tidak melakukan
            // apa-apa.
            Log::warning('telegram.channel_gate.check_failed', [
                'channel'  => $this->channel(),
                'user_id'  => $user->id,
                'sebab'    => $e->getMessage(),
                'petunjuk' => 'Pastikan bot menjadi ADMIN di channel dan '
                    .'TELEGRAM_REQUIRED_CHANNEL benar.',
            ]);

            return true;
        }
    }

    private function channel(): string
    {
        return trim((string) config('telegram.required_channel'));
    }

    /**
     * Alamat channel untuk tombolnya.
     *
     * `channel_url` didahulukan karena ia memang alamat siap pakai. Bila
     * kosong tetapi channelnya publik, alamatnya bisa disusun dari
     * username-nya — jadi satu pengaturan yang terlewat tidak menghasilkan
     * pesan penahan tanpa jalan keluar.
     */
    private function url(): ?string
    {
        if (filled($url = trim((string) config('telegram.channel_url')))) {
            return $url;
        }

        $channel = $this->channel();

        return str_starts_with($channel, '@')
            ? 'https://t.me/'.ltrim($channel, '@')
            : null;
    }

    private function kunci(User $user): string
    {
        return 'tg:channel-member:'.md5($this->channel()).':'.$user->telegram_id;
    }
}
