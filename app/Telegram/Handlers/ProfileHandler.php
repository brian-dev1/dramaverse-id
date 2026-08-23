<?php

namespace App\Telegram\Handlers;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FavoriteService;
use App\Services\Membership\MembershipService;
use App\Services\ReferralService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\WatchHistoryService;
use App\Support\Telegram\Notice;
use App\Support\TelegramDeepLink;
use App\Support\Waktu;
use App\Telegram\Keyboards\EpisodeKeyboard;
use App\Support\Uang;

/**
 * Halaman profil pengguna di dalam bot.
 *
 * ## Kenapa isinya berubah total
 *
 * Sebelumnya handler ini mengirim satu kalimat tetap: "Fitur profil akan
 * segera tersedia." Tombolnya ada di menu sejak awal, ditekan orang, dan
 * tidak pernah menampilkan apa pun.
 *
 * Itu bukan sekadar fitur yang belum jadi. Profil adalah satu-satunya tempat
 * pengguna bisa memeriksa **apakah pembayarannya sudah masuk** — dan tanpa
 * itu, setiap pertanyaan "sudah aktif belum?" berakhir di admin.
 *
 * ## Kenapa Program Affiliate ikut di sini
 *
 * Angka referral sebelumnya hanya ada di halaman web. Yang mengundang orang
 * paling banyak justru yang paling jarang membuka website: ia menyebarkan
 * tautannya dari dalam Telegram, dan untuk memeriksa hasilnya harus keluar
 * dari aplikasi. Karena itu ringkasannya — persentase, jumlah undangan,
 * transaksi, saldo — dan tautan referralnya sendiri dipasang di halaman ini.
 *
 * Yang TIDAK dipindahkan ke sini: penarikan saldo. Penarikan butuh nomor
 * rekening, nama pemilik, dan konfirmasi; percakapan bot bukan bentuk yang
 * tepat untuk itu, dan tombol di bawah pesan ini mengantar ke halamannya.
 *
 * ## Dibaca dari service yang sama dengan website
 *
 * `MembershipService`, `WatchHistoryService`, `FavoriteService`,
 * `ReferralService`. Tidak ada satu pun query yang ditulis ulang di sini, dan
 * tidak ada aturan membership maupun aturan komisi yang disalin — angka yang
 * tampil di bot selalu sama dengan yang tampil di halaman profil dan halaman
 * affiliate website, karena keduanya bertanya ke tempat yang sama.
 */
class ProfileHandler
{
    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected MembershipService $membership,
        protected WatchHistoryService $history,
        protected FavoriteService $favorites,
        protected ReferralService $referral
    ) {
    }

    public function handle(array $callback, ?User $user = null): void
    {
        $chatId = $callback['message']['chat']['id'];

        if ($user === null) {
            $this->telegram->sendMessage(
                $chatId,
                'Kirim /start dulu supaya akun Anda dikenali.'
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            $this->teks($user),
            [
                'reply_markup' => ['inline_keyboard' => $this->tombol($user)],
                'disable_web_page_preview' => true,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Isi
    |--------------------------------------------------------------------------
    */

    private function teks(User $user): string
    {
        $status = $this->membership->status($user);

        $aktif = $this->membership->active($user);

        $pesan = Notice::make('👤', 'Profil Anda');

        $pesan->section('🪪', 'Detail akun')->rows([
            'Nama'            => $user->name,
            'Username'        => filled($user->telegram_username)
                ? '@'.$user->telegram_username
                : null,
            'Status akun'     => $aktif !== null ? $status['label'] : 'Member',
            'Bergabung sejak' => Waktu::lengkap($user->created_at, '-'),
        ]);

        /*
        |----------------------------------------------------------------------
        | Langganan
        |----------------------------------------------------------------------
        */

        $pesan->section('💎', 'Langganan');

        if ($aktif !== null) {

            $sisa = $aktif->expired_at !== null
                ? (int) ceil(now()->floatDiffInDays($aktif->expired_at, false))
                : null;

            $pesan->rows([
                'Status'         => $status['label'],
                'Paket'          => $aktif->plan?->name ?? '-',
                // Tanggal DAN jam: pertanyaan "jam berapa berhentinya" selalu
                // datang di hari terakhir, saat sudah terlambat menjawabnya.
                'Berlaku sampai' => $aktif->expired_at !== null
                    ? Waktu::lengkap($aktif->expired_at)
                    : 'tanpa batas waktu',
                'Sisa'           => $sisa === null
                    ? null
                    : max(0, $sisa).' hari',
            ]);

            // Sisa hari yang tinggal sedikit ditandai. Itu satu-satunya
            // pemberitahuan yang diterima pengguna sebelum aksesnya berhenti,
            // karena pengingat otomatis belum ada.
            if ($sisa !== null && $sisa <= 3) {
                $pesan->note('⚠️ Tinggal '.max(0, $sisa).' hari lagi — perpanjang '
                    .'sebelum part premium terkunci.');
            }

        } else {

            $pesan->rows(['Status' => $status['label']]);

            $pesan->text($status['status'] === 'expired'
                ? 'Langganan Anda sudah berakhir. Perpanjang untuk membuka '
                    .'part premium lagi.'
                : 'Anda belum berlangganan. Part premium masih terkunci.');
        }

        /*
        |----------------------------------------------------------------------
        | Tagihan yang menunggu
        |----------------------------------------------------------------------
        |
        | Inilah bagian yang membuat halaman ini ada. Pengguna yang baru
        | membayar sebagian lewat Trakteer melihat sisanya di sini, bukan
        | menebak-nebak atau bertanya ke admin.
        |
        */

        $tagihan = Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::PENDING->value)
            ->latest('id')
            ->first();

        if ($tagihan !== null) {

            $adaCicilan = (float) $tagihan->paid_amount > 0;

            $pesan->section('🧾', 'Tagihan menunggu pembayaran')->rows([
                'Nomor'       => $tagihan->number,
                'Paket'       => $tagihan->plan_name,
                'Total'       => Uang::invoice($tagihan),
                'Sudah masuk' => $adaCicilan
                    ? Uang::invoice($tagihan, 'paid_amount')
                        .' ('.$tagihan->paidPercent().'%)'
                    : null,
                'Kurang'      => $adaCicilan
                    ? Uang::format($tagihan->outstanding(), $tagihan->currency)
                    : null,
                'Jatuh tempo' => $tagihan->due_at !== null
                    ? Waktu::lengkapRelatif($tagihan->due_at)
                    : null,
            ]);
        }

        /*
        |----------------------------------------------------------------------
        | Aktivitas
        |----------------------------------------------------------------------
        */

        $terakhir = $this->history->latest($user, 1)->first()?->episode;

        $pesan->section('📊', 'Statistik pribadi')->rows([
            'Jumlah transaksi'  => $this->jumlahTransaksi($user).' kali',
            'Favorit'           => $this->favorites->all($user)->count().' drama',
            'Terakhir ditonton' => $terakhir === null
                ? 'belum ada'
                : ($terakhir->drama?->title ?? 'Drama')
                    .' — part '.$terakhir->episode_number,
        ]);

        $this->referralSection($pesan, $user);

        return $pesan->render();
    }

    /**
     * Berapa kali pengguna ini benar-benar membayar.
     *
     * Yang dihitung hanya tagihan berstatus lunas. Tagihan yang dibatalkan,
     * kedaluwarsa, atau masih menunggu bukan transaksi — memasukkannya akan
     * membuat orang yang belum pernah membayar sekalipun melihat angka di
     * atas nol dan mengira uangnya sudah masuk.
     */
    private function jumlahTransaksi(User $user): int
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::PAID->value)
            ->count();
    }

    /**
     * Ringkasan Program Affiliate beserta tautan referralnya.
     *
     * Dilewati sama sekali bila programnya dimatikan admin. Menampilkan
     * bagian ini dengan angka nol saat program tidak berjalan hanya
     * memunculkan pertanyaan "kenapa komisi saya tidak bertambah".
     */
    private function referralSection(Notice $pesan, User $user): void
    {
        if (! $this->referral->enabled()) {
            return;
        }

        $data = $this->referral->summary($user);

        $pesan->section('🤝', 'Program Affiliate')->rows([
            'Persentase komisi' => rtrim(rtrim(number_format((float) $data['rate'], 2, ',', '.'), '0'), ',').'%',
            'Level'             => 'Level '.$data['level'],
            'Mengundang'        => (int) $data['total_referrals'].' orang',
            'Transaksi masuk'   => (int) $data['transactions'].' kali',
            'Total komisi'      => Uang::format($data['commission']),
            'Saldo tersedia'    => Uang::format($data['balance']),
        ]);

        $pesan->text('Tautan referral Anda:');

        $pesan->code((string) $data['link']);

        $pesan->note('Bagikan tautan di atas — setiap pembelian VIP dari orang '
            .'yang masuk lewat sana memberi Anda komisi, dan pemberitahuannya '
            .'langsung dikirim ke chat ini.');
    }

    /**
     * Tombol yang menyesuaikan keadaan.
     *
     * Yang punya riwayat mendapat jalan langsung melanjutkan tontonannya.
     * Menampilkan semua tombol kepada semua orang membuat yang paling
     * berguna tenggelam.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function tombol(User $user): array
    {
        $tombol = [];

        $terakhir = $this->history->latest($user, 1)->first()?->episode;

        if ($terakhir !== null) {
            $tombol[] = [[
                'text'          => '▶️ Lanjutkan part '.$terakhir->episode_number,
                'callback_data' => EpisodeKeyboard::WATCH.':'.$terakhir->id,
            ]];
        }

        if ($this->referral->enabled()) {

            $baris = [];

            $affiliate = $this->tombolAffiliate();

            if ($affiliate !== null) {
                $baris[] = $affiliate;
            }

            $bagikan = $this->tombolBagikan($user);

            if ($bagikan !== null) {
                $baris[] = $bagikan;
            }

            if ($baris !== []) {
                $tombol[] = $baris;
            }
        }

        $tombol[] = [[
            'text'          => '💎 Premium',
            'callback_data' => 'premium',
        ]];

        $tombol[] = [[
            'text'          => '🌐 Buka Website',
            'callback_data' => 'website',
        ]];

        return $tombol;
    }

    /**
     * Tombol menuju halaman Program Affiliate — tempat saldo ditarik.
     *
     * Dibuka sebagai Mini App bila alamatnya sudah HTTPS: pengguna tetap di
     * dalam Telegram dan sudah dalam keadaan masuk. Kalau belum HTTPS —
     * biasanya saat pengembangan lokal — Telegram menolak tombol `web_app`
     * dan MENOLAK SELURUH KEYBOARD bersamanya, bukan hanya tombol itu. Karena
     * itu jatuhannya adalah tautan `startapp` biasa, dan bila username botnya
     * pun belum diisi, tombolnya tidak dirender sama sekali.
     *
     * @return array<string,mixed>|null
     */
    private function tombolAffiliate(): ?array
    {
        $base = rtrim((string) (config('telegram.miniapp_url') ?: config('app.url')), '/');

        if (str_starts_with($base, 'https://')) {
            return [
                'text'    => '💰 Saldo & Penarikan',
                'web_app' => ['url' => $base.'/affiliate'],
            ];
        }

        $url = TelegramDeepLink::app(TelegramDeepLink::APP_AFFILIATE);

        return $url === null ? null : [
            'text' => '💰 Saldo & Penarikan',
            'url'  => $url,
        ];
    }

    /**
     * Tombol bagikan: membuka pemilih chat Telegram dengan tautan sudah terisi.
     *
     * Menyalin tautan dari dalam blok kode lalu menempelkannya ke chat lain
     * adalah tiga langkah di ponsel. Ini satu.
     *
     * @return array<string,mixed>|null
     */
    private function tombolBagikan(User $user): ?array
    {
        $link = TelegramDeepLink::referral($this->referral->codeFor($user));

        if ($link === null) {
            return null;
        }

        $teks = 'Nonton drama pendek subtitle Indonesia di DramaVerse ID — '
            .'ribuan judul, part baru tiap hari.';

        return [
            'text' => '🔗 Bagikan tautan',
            'url'  => 'https://t.me/share/url?url='.rawurlencode($link)
                .'&text='.rawurlencode($teks),
        ];
    }
}
