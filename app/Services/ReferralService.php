<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ReferralCommission;
use App\Models\ReferralTier;
use App\Models\ReferralVisit;
use App\Models\ReferralWithdrawal;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Program Affiliate.
 *
 * Semua aturan uang ada di kelas ini — controller hanya memanggil. Tiga
 * jaminan yang membuat angkanya tidak bisa dicurangi:
 *
 * 1. Ikatan referral ditulis sekali. `referred_by_id` tidak pernah ditimpa,
 *    jadi orang tidak bisa berpindah-pindah upline mengejar bonus.
 * 2. Komisi lahir HANYA dari invoice berstatus lunas, dan `invoice_id` di
 *    tabel komisi punya unique index. Callback pembayaran yang datang lima
 *    kali tetap menghasilkan satu komisi.
 * 3. Saldo tidak disimpan sebagai kolom yang bisa di-update. Saldo selalu
 *    dihitung ulang: komisi tersedia dikurangi penarikan yang belum ditolak.
 */
class ReferralService
{
    private const SETTING_KEYS = [
        'referral_enabled', 'referral_min_withdraw', 'referral_fee_percent',
        'referral_cookie_days', 'referral_hold_days', 'referral_base', 'referral_ewallets',
    ];

    private const DEFAULTS = [
        'referral_enabled'      => '1',
        'referral_min_withdraw' => '30000',
        'referral_fee_percent'  => '0',
        'referral_cookie_days'  => '30',
        'referral_hold_days'    => '0',
        'referral_base'         => 'subtotal',
        'referral_ewallets'     => 'DANA,OVO,GoPay,ShopeePay',
    ];

    /* ---------------------------------------------------------------- */
    /* Pengaturan                                                        */
    /* ---------------------------------------------------------------- */

    public function config(): array
    {
        return Cache::rememberForever('referral.config', function () {
            $rows = Setting::whereIn('key', self::SETTING_KEYS)->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, array_filter($rows, fn ($v) => $v !== null));
        });
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config()[$key] ?? $default;
    }

    public function saveConfig(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::SETTING_KEYS, true)) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value, 'group' => 'referral', 'type' => 'text']
            );
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget('referral.config');
        Cache::forget('referral.tiers');
    }

    public function enabled(): bool
    {
        return (string) $this->setting('referral_enabled', '1') === '1';
    }

    /** @return \Illuminate\Support\Collection<int,ReferralTier> */
    public function tiers()
    {
        return Cache::rememberForever(
            'referral.tiers',
            fn () => ReferralTier::where('is_active', true)->orderBy('min_referrals')->get()
        );
    }

    /* ---------------------------------------------------------------- */
    /* Kode & ikatan referral                                            */
    /* ---------------------------------------------------------------- */

    /** Kode referral pengguna; dibuat saat pertama kali dibutuhkan. */
    public function codeFor(User $user): string
    {
        if (filled($user->referral_code)) {
            return $user->referral_code;
        }

        $code = $this->generateCode();

        $user->forceFill(['referral_code' => $code])->saveQuietly();

        return $code;
    }

    /**
     * Kode referral baru: panjang dan acak.
     *
     * Kode pendek bisa ditebak — orang tinggal mencoba ribuan kombinasi
     * sampai menemukan kode milik orang lain. 24 karakter dari 32 huruf
     * aman (tanpa 0/o/1/l/i yang mudah tertukar) memberi ruang tebakan yang
     * tidak masuk akal untuk dijelajahi, dan tetap jauh di bawah batas 64
     * karakter parameter `start` Telegram.
     */
    public function generateCode(): string
    {
        $alfabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        do {
            $acak = '';

            for ($i = 0; $i < 24; $i++) {
                $acak .= $alfabet[random_int(0, strlen($alfabet) - 1)];
            }

            $code = 'dv'.$acak;
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    /** Ganti kode seorang pengguna dengan yang baru (tautan lama mati). */
    public function rotateCode(User $user): string
    {
        $code = $this->generateCode();

        $user->forceFill(['referral_code' => $code])->saveQuietly();

        return $code;
    }

    public function findByCode(?string $code): ?User
    {
        if (blank($code)) {
            return null;
        }

        return User::where('referral_code', trim($code))
            ->where('is_active', true)
            ->where('is_banned', false)
            ->first();
    }

    /** Catat kunjungan lewat tautan referral (untuk statistik klik). */
    public function recordVisit(User $referrer, ?string $ip, ?string $agent): void
    {
        $fingerprint = hash('sha256', ($ip ?? '').'|'.($agent ?? ''));

        // Satu sidik jari dihitung sekali per hari — refresh berulang tidak
        // menggelembungkan statistik.
        $sudah = ReferralVisit::where('referrer_id', $referrer->id)
            ->where('fingerprint', $fingerprint)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if ($sudah) {
            return;
        }

        ReferralVisit::create([
            'referrer_id' => $referrer->id,
            'ip'          => $ip,
            'user_agent'  => Str::limit((string) $agent, 240, ''),
            'fingerprint' => $fingerprint,
            'created_at'  => now(),
        ]);
    }

    /**
     * Ikat pengguna baru ke pengundangnya.
     *
     * Mengembalikan true hanya bila ikatan benar-benar baru dibuat.
     */
    public function attach(User $user, ?string $code): bool
    {
        if (! $this->enabled() || blank($code)) {
            return false;
        }

        // Sudah punya upline → tidak pernah ditimpa.
        if ($user->referred_by_id !== null) {
            return false;
        }

        $referrer = $this->findByCode($code);

        if (! $referrer || $referrer->id === $user->id) {
            return false;
        }

        // Cegah lingkaran: A mengundang B, B tidak boleh mengundang A.
        if ((int) $referrer->referred_by_id === (int) $user->id) {
            return false;
        }

        $user->forceFill([
            'referred_by_id' => $referrer->id,
            'referred_at'    => now(),
        ])->saveQuietly();

        Log::info('referral.attached', ['user' => $user->id, 'referrer' => $referrer->id]);

        return true;
    }

    /* ---------------------------------------------------------------- */
    /* Komisi                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Kabari pengundang lewat bot bahwa komisinya bertambah.
     *
     * Dibungkus try/catch dan tidak pernah melempar: pembayaran yang sah
     * tidak boleh gagal hanya karena seseorang memblokir bot.
     */
    private function notifyCommission(User $referrer, float $amount): void
    {
        if (! $referrer->telegram_id) {
            return;
        }

        try {
            app(\App\Services\Telegram\Contracts\TelegramServiceInterface::class)->sendMessage(
                $referrer->telegram_id,
                "<b>Komisi masuk</b>\n\n"
                .'Rp '.number_format($amount, 0, ',', '.').' dari transaksi referral Anda. '
                .'Saldo bisa dicek di halaman Program Affiliate.'
            );
        } catch (\Throwable $e) {
            Log::warning('referral.notify.failed', ['user' => $referrer->id, 'error' => $e->getMessage()]);
        }
    }

    /** Rate (persen) yang berlaku untuk seorang referrer saat ini. */
    public function rateFor(User $user): array
    {
        $jumlah = $this->totalReferrals($user);
        $level  = 1;
        $rate   = 0.0;

        foreach ($this->tiers() as $tier) {
            if ($jumlah >= $tier->min_referrals) {
                $level = $tier->level;
                $rate  = (float) $tier->rate;
            }
        }

        return ['level' => $level, 'rate' => $rate];
    }

    public function totalReferrals(User $user): int
    {
        return User::where('referred_by_id', $user->id)->count();
    }

    /**
     * Buat komisi dari sebuah invoice yang baru lunas.
     *
     * Dipanggil dari InvoiceService::markPaid(). Aman dipanggil berkali-kali:
     * unique index pada invoice_id yang menjaga, bukan pemeriksaan di sini.
     */
    public function awardFor(Invoice $invoice): ?ReferralCommission
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($invoice) {

                $pembeli = $invoice->user;

                if (! $pembeli || ! $pembeli->referred_by_id) {
                    return null;
                }

                if (ReferralCommission::where('invoice_id', $invoice->id)->exists()) {
                    return null;
                }

                $referrer = User::find($pembeli->referred_by_id);

                if (! $referrer || $referrer->is_banned || ! $referrer->is_active) {
                    return null;
                }

                // Penjagaan terakhir terhadap akun kembar: pengundang dan
                // yang diundang tidak boleh orang yang sama.
                if ($referrer->id === $pembeli->id) {
                    return null;
                }

                $base = $this->setting('referral_base') === 'total'
                    ? (float) $invoice->total
                    : (float) $invoice->subtotal;

                if ($base <= 0) {
                    return null;
                }

                ['level' => $level, 'rate' => $rate] = $this->rateFor($referrer);

                if ($rate <= 0) {
                    return null;
                }

                $hold   = (int) $this->setting('referral_hold_days', 0);
                $amount = round($base * $rate / 100, 2);

                $komisi = ReferralCommission::create([
                    'referrer_id'      => $referrer->id,
                    'referred_user_id' => $pembeli->id,
                    'invoice_id'       => $invoice->id,
                    'base_amount'      => $base,
                    'rate'             => $rate,
                    'amount'           => $amount,
                    'level'            => $level,
                    'status'           => $hold > 0 ? 'pending' : 'available',
                    'available_at'     => now()->addDays($hold),
                ]);

                Log::info('referral.commission', [
                    'invoice'  => $invoice->number,
                    'referrer' => $referrer->id,
                    'amount'   => $amount,
                ]);

                $this->notifyCommission($referrer, $amount);

                return $komisi;
            });
        } catch (\Throwable $e) {
            // Komisi gagal tidak boleh membatalkan pembayaran yang sah.
            Log::error('referral.commission.failed', [
                'invoice' => $invoice->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Lepaskan komisi yang masa tahannya sudah lewat. Dipanggil scheduler. */
    public function releaseHeld(): int
    {
        return ReferralCommission::where('status', 'pending')
            ->where('available_at', '<=', now())
            ->update(['status' => 'available']);
    }

    /* ---------------------------------------------------------------- */
    /* Saldo & penarikan                                                 */
    /* ---------------------------------------------------------------- */

    /** Saldo yang benar-benar bisa ditarik. Selalu dihitung, tidak disimpan. */
    public function balance(User $user): float
    {
        $komisi = (float) ReferralCommission::where('referrer_id', $user->id)
            ->whereIn('status', ['available', 'paid'])
            ->sum('amount');

        // Penarikan yang ditolak mengembalikan saldo; selain itu menahan.
        $tertarik = (float) ReferralWithdrawal::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');

        return max(0, round($komisi - $tertarik, 2));
    }

    /** Ringkasan untuk kartu di halaman affiliate (dipakai juga oleh polling). */
    public function summary(User $user): array
    {
        $code  = $this->codeFor($user);
        $tier  = $this->rateFor($user);
        $total = $this->totalReferrals($user);

        return [
            'code'            => $code,
            // Tautan utama mengarah ke bot: di sanalah akun dibuat dan
            // transaksi terjadi, jadi di sanalah referral bisa dipastikan.
            // Tautan web hanya cadangan bila username bot belum diisi.
            'link'            => \App\Support\TelegramDeepLink::referral($code) ?? url('/?ref='.$code),
            'web_link'        => url('/?ref='.$code),
            'channel'         => trim((string) config('telegram.channel_url')) ?: null,
            'commission'      => (float) ReferralCommission::where('referrer_id', $user->id)
                                    ->where('status', '!=', 'void')->sum('amount'),
            'balance'         => $this->balance($user),
            'rate'            => $tier['rate'],
            'level'           => $tier['level'],
            'transactions'    => ReferralCommission::where('referrer_id', $user->id)
                                    ->where('status', '!=', 'void')->count(),
            'total_referrals' => $total,
            'visits'          => ReferralVisit::where('referrer_id', $user->id)->count(),
            'min_withdraw'    => (float) $this->setting('referral_min_withdraw'),
            'fee_percent'     => (float) $this->setting('referral_fee_percent'),
            'updated_at'      => now()->toIso8601String(),
        ];
    }

    /**
     * Deret harian komisi & transaksi untuk grafik.
     *
     * @return array{labels:string[],commission:float[],transactions:int[]}
     */
    public function series(User $user, int $days = 7): array
    {
        $mulai = now()->subDays($days - 1)->startOfDay();

        $rows = ReferralCommission::where('referrer_id', $user->id)
            ->where('status', '!=', 'void')
            ->where('created_at', '>=', $mulai)
            ->selectRaw('DATE(created_at) as d, SUM(amount) as jumlah, COUNT(*) as trx')
            ->groupBy('d')
            ->pluck('jumlah', 'd')
            ->all();

        $trx = ReferralCommission::where('referrer_id', $user->id)
            ->where('status', '!=', 'void')
            ->where('created_at', '>=', $mulai)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        $labels = $komisi = $transaksi = [];

        for ($i = 0; $i < $days; $i++) {
            $tgl      = $mulai->copy()->addDays($i);
            $kunci    = $tgl->toDateString();
            $labels[] = $tgl->translatedFormat('d M');

            $komisi[]    = (float) ($rows[$kunci] ?? 0);
            $transaksi[] = (int) ($trx[$kunci] ?? 0);
        }

        return ['labels' => $labels, 'commission' => $komisi, 'transactions' => $transaksi];
    }

    /**
     * Ajukan penarikan sejumlah tertentu.
     *
     * Dulu selalu menarik SELURUH saldo. Yang hilang di sana adalah pilihan:
     * orang yang saldonya Rp 500.000 dan cuma butuh Rp 100.000 terpaksa
     * menarik semuanya, dan sisanya keluar dari sistem — beserta seluruh
     * alasannya untuk kembali mengumpulkan.
     *
     * Batas minimalnya tetap berlaku, tapi sekarang berlaku pada JUMLAH YANG
     * DITARIK, bukan pada saldo. Bedanya nyata: saldo Rp 500.000 dengan
     * minimal Rp 50.000 boleh ditarik Rp 50.000, tidak boleh Rp 20.000.
     *
     * Jumlahnya diperiksa ulang di dalam transaksi ini, bukan cukup di
     * controller. Antara halaman dirender dan tombol ditekan, saldonya bisa
     * berubah — komisi yang dibatalkan admin, atau penarikan dari tab lain —
     * dan angka `max` di HTML tidak mengikat siapa pun yang mengirim
     * formulirnya sendiri.
     *
     * @throws \RuntimeException bila jumlahnya di luar batas atau masih ada
     *                           pengajuan yang menunggu.
     */
    public function requestWithdrawal(
        User $user,
        float $amount,
        string $method,
        string $number,
        string $name
    ): ReferralWithdrawal {

        return DB::transaction(function () use ($user, $amount, $method, $number, $name) {

            // Kunci baris pengguna: dua permintaan tarik yang tiba bersamaan
            // tidak boleh sama-sama lolos pemeriksaan saldo.
            User::whereKey($user->id)->lockForUpdate()->first();

            if (ReferralWithdrawal::where('user_id', $user->id)->where('status', 'pending')->exists()) {
                throw new \RuntimeException('Masih ada penarikan yang menunggu diproses.');
            }

            $saldo = $this->balance($user);
            $min   = (float) $this->setting('referral_min_withdraw');

            $amount = round($amount, 2);

            if ($saldo < $min) {
                throw new \RuntimeException(
                    'Saldo belum cukup. Minimal penarikan Rp '.number_format($min, 0, ',', '.').'.'
                );
            }

            if ($amount < $min) {
                throw new \RuntimeException(
                    'Jumlah penarikan minimal Rp '.number_format($min, 0, ',', '.').'.'
                );
            }

            if ($amount > $saldo) {
                throw new \RuntimeException(
                    'Jumlahnya melebihi saldo tersedia (Rp '.number_format($saldo, 0, ',', '.').').'
                );
            }

            /*
            | Sisa yang tertinggal boleh berapa pun, termasuk di bawah minimal.
            |
            | Sempat terpikir memaksa sisanya tetap di atas minimal — supaya
            | tidak ada saldo kecil yang mengendap selamanya. Tapi aturan itu
            | melarang orang menarik Rp 90.000 dari saldo Rp 100.000 dengan
            | alasan yang tidak akan pernah masuk akal baginya, dan saldo
            | mengendap itu toh tetap miliknya: ia tinggal mengumpulkan lagi
            | sampai lewat minimal.
            */

            $fee = round($amount * (float) $this->setting('referral_fee_percent') / 100, 2);

            return ReferralWithdrawal::create([
                'user_id'        => $user->id,
                'amount'         => $amount,
                'fee'            => $fee,
                'net_amount'     => round($amount - $fee, 2),
                'method'         => $method,
                'account_number' => $number,
                'account_name'   => $name,
                'status'         => 'pending',
            ]);
        });
    }

    public function ewallets(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->setting('referral_ewallets'))
        )));
    }
}
