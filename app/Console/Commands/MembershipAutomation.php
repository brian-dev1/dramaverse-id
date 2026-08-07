<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Membership\MembershipExpiryNotifier;
use App\Services\Membership\MembershipService;
use App\Services\Telegram\TelegramRetentionService;
use App\Support\Waktu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Otomatisasi siklus hidup membership.
 *
 * Dijalankan scheduler. Bentuknya satu perintah dengan beberapa aksi — sama
 * seperti `telegram:auto` dan `payment:auto` — supaya baris cron dan halaman
 * monitoring tidak bertambah satu per satu setiap ada pekerjaan berkala baru.
 *
 * ## Aksi
 *
 * - `expire` : tandai langganan yang lewat tanggalnya sebagai EXPIRED dan
 *              samakan `users.is_premium`. Ini yang MENGUNCI fitur.
 * - `notify` : kirim pemberitahuan "paket berakhir" + tarik video premium.
 * - `purge`  : jalankan penghapusan video yang masa hidupnya habis (TTL).
 * - `sweep`  : ketiganya berurutan. Ini yang dipakai scheduler.
 *
 * Urutan di `sweep` tidak boleh ditukar: mengunci dulu, memberi tahu
 * kemudian. Kebalikannya berarti ada jendela waktu di mana pengguna sudah
 * menerima pesan "akses Anda dicabut" tetapi masih bisa membuka episode VIP.
 */
class MembershipAutomation extends Command
{
    protected $signature = 'membership:auto
                            {action=sweep : expire|notify|purge|sweep}
                            {--limit=500 : Batas baris per jalan}';

    protected $description = 'Kedaluwarsakan langganan, beri tahu pengguna, tarik video premium';

    public function __construct(
        protected MembershipService $membership,
        protected MembershipExpiryNotifier $notifier,
        protected TelegramRetentionService $retention
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $aksi = (string) $this->argument('action');

        return match ($aksi) {
            'expire' => $this->expire(),
            'notify' => $this->notify(),
            'purge'  => $this->purge(),
            'sweep'  => $this->sweep(),
            default  => $this->salahAksi($aksi),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Aksi
    |--------------------------------------------------------------------------
    */

    private function sweep(): int
    {
        $this->expire();
        $this->notify();
        $this->purge();

        return self::SUCCESS;
    }

    /** Kunci akses: status jadi EXPIRED, `users.is_premium` jadi false. */
    private function expire(): int
    {
        $jumlah = $this->membership->expireDue((int) $this->option('limit'));

        $this->info("Langganan dikedaluwarsakan: {$jumlah}");

        return self::SUCCESS;
    }

    /**
     * Kirim pemberitahuan untuk langganan yang sudah expired tapi belum
     * diberi tahu.
     *
     * Dibatasi 24 jam ke belakang supaya menyalakan fitur ini di sistem yang
     * sudah berjalan tidak mengirim ribuan pesan "paket Anda berakhir" untuk
     * langganan yang habis berbulan-bulan lalu. Itu satu-satunya kesalahan di
     * sini yang tidak bisa ditarik kembali.
     */
    private function notify(): int
    {
        $baris = Subscription::query()
            ->with(['user', 'plan'])
            ->where('status', SubscriptionStatus::EXPIRED->value)
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>=', now()->subDay())
            ->orderBy('expired_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $terkirim = 0;

        foreach ($baris as $satu) {
            if ($this->notifier->tangani($satu)) {
                $terkirim++;
            }
        }

        // Langganan lama yang tidak jadi dikirimi pesan tetap ditandai, supaya
        // tidak terus-menerus diperiksa setiap sepuluh menit selamanya.
        $ditandai = Subscription::query()
            ->where('status', SubscriptionStatus::EXPIRED->value)
            ->whereNull('expiry_notified_at')
            ->where(function ($q) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '<', now()->subDay());
            })
            ->update(['expiry_notified_at' => now()]);

        $this->info("Pemberitahuan terkirim: {$terkirim} (lama ditandai: {$ditandai})");

        return self::SUCCESS;
    }

    /** Penghapusan terjadwal + penandaan yang sudah lewat 48 jam. */
    private function purge(): int
    {
        $hasil = $this->retention->jalankanTerjadwal();

        $tua = $this->retention->tandaiTerlaluTua();

        $this->info(sprintf(
            'Video: %d dihapus, %d gagal, %d ditandai lewat 48 jam.',
            $hasil['dihapus'],
            $hasil['gagal'],
            $hasil['terlalu_tua'] + $tua
        ));

        return self::SUCCESS;
    }

    private function salahAksi(string $aksi): int
    {
        $this->error("Aksi tidak dikenal: {$aksi}. Pakai expire|notify|purge|sweep.");

        Log::warning('membership.auto.unknown_action', [
            'aksi'  => $aksi,
            'waktu' => Waktu::presisi(now()),
        ]);

        return self::FAILURE;
    }
}
