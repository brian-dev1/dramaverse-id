<?php

namespace App\Telegram\Handlers;

use App\Models\ReferralCommission;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Support\Telegram\Notice;
use App\Support\Uang;
use App\Support\Waktu;
use Illuminate\Support\Facades\DB;

/**
 * Pemantau Program Affiliate lewat bot, khusus admin.
 *
 * ## Kenapa ada, padahal panel admin sudah punya halamannya
 *
 * Kecurangan afiliasi tidak muncul di jam kerja. Ia muncul saat seseorang
 * memperhatikan angka yang janggal — biasanya di malam hari, dari ponsel,
 * beberapa detik setelah melihat notifikasi komisi masuk. Membuka laptop,
 * masuk ke panel, dan mencari orangnya adalah tiga langkah yang cukup untuk
 * membuat pemeriksaan itu tidak pernah terjadi.
 *
 * Handler ini memindahkan pemeriksaan cepatnya ke tempat kecurigaan itu
 * timbul. Yang TIDAK dipindahkan: membatalkan komisi dan memproses penarikan
 * tetap di panel. Melihat boleh dari mana saja; mengubah uang orang tidak.
 *
 * ## Izinnya menumpang panel, bukan aturan sendiri
 *
 * Gerbangnya `payment.manage` — izin yang sama persis dengan halaman
 * Affiliate di panel admin. Itu disengaja: dua daftar izin yang mengatur data
 * yang sama akan berbeda cepat atau lambat, dan yang lupa diperbarui selalu
 * yang lebih longgar.
 *
 * Konsekuensinya menyenangkan: memberi akses cukup dilakukan sekali di panel,
 * dan bot langsung mengikutinya tanpa deploy.
 *
 * ## Pemakaian
 *
 *     /afiliasi                    ringkasan program + afiliasi teratas
 *     /afiliasi budi               cari berdasarkan nama
 *     /afiliasi @nebulahaze1       cari berdasarkan username Telegram
 *     /afiliasi ref1423400091236e  cari berdasarkan kode referral
 *     /afiliasi 8947692769         cari berdasarkan id Telegram atau id user
 */
class AffiliateAdminHandler
{
    /** Izin yang sama dengan halaman Affiliate di panel admin. */
    public const IZIN = 'payment.manage';

    /**
     * Batas hasil pencarian yang ditampilkan sekaligus.
     *
     * Delapan cukup untuk melihat pola tanpa membuat pesan bergulir panjang
     * di ponsel. Sisanya disebutkan jumlahnya, supaya admin tahu pencariannya
     * masih terlalu luas alih-alih mengira hanya itu yang ada.
     */
    private const BATAS = 8;

    /** Banyaknya komisi terakhir yang ditampilkan pada detail satu orang. */
    private const RIWAYAT = 5;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected ReferralService $referral
    ) {
    }

    public function handle(int|string $chatId, ?User $user, string $kueri = ''): void
    {
        /*
        |----------------------------------------------------------------------
        | Gerbang
        |----------------------------------------------------------------------
        |
        | Jawabannya sengaja sama untuk "bukan admin" dan "admin tanpa izin
        | keuangan": menyebutkan bahwa perintahnya ada tetapi izinnya kurang
        | memberi tahu orang luar bahwa ada sesuatu yang layak dicoba.
        |
        | Yang TIDAK disamarkan adalah keberadaan perintahnya sendiri — ia
        | tidak didaftarkan di menu command Telegram, jadi orang biasa tidak
        | akan menemukannya kecuali menebak.
        |
        */
        if ($user === null || ! $user->hasPermission(self::IZIN)) {

            $this->telegram->sendMessage(
                $chatId,
                Notice::make('🔒', 'Khusus admin')
                    ->lead('Perintah ini hanya untuk admin yang memegang izin '
                        .'pengelolaan pembayaran.')
                    ->note('Kalau Anda merasa seharusnya punya akses, minta Super Admin '
                        .'menambahkan izin Kelola Pembayaran pada role Anda.')
                    ->render()
            );

            return;
        }

        $kueri = trim($kueri);

        if ($kueri === '') {
            $this->ringkasan($chatId);

            return;
        }

        $hasil = $this->cari($kueri);

        if ($hasil->isEmpty()) {

            $this->telegram->sendMessage(
                $chatId,
                Notice::make('🔍', 'Tidak ditemukan')
                    ->lead('Tidak ada pengguna yang cocok dengan "'.$kueri.'".')
                    ->note('Coba nama, @username, kode referral, atau id Telegram.')
                    ->render()
            );

            return;
        }

        // Satu hasil berarti tidak ada yang perlu dipilih — langsung
        // tampilkan detailnya. Meminta admin menekan sekali lagi untuk
        // sesuatu yang sudah pasti hanya menambah langkah.
        if ($hasil->count() === 1) {
            $this->telegram->sendMessage($chatId, $this->detail($hasil->first()));

            return;
        }

        $this->telegram->sendMessage($chatId, $this->daftar($hasil, $kueri));
    }

    /*
    |--------------------------------------------------------------------------
    | Pencarian
    |--------------------------------------------------------------------------
    */

    /**
     * Cari pengguna dari satu kata kunci apa pun bentuknya.
     *
     * Admin yang sedang curiga tidak ingat sedang memegang jenis identitas
     * yang mana — kadang nama dari notifikasi, kadang kode dari tautan yang
     * dibagikan orang, kadang id dari panel. Satu kotak pencarian yang
     * menerima semuanya menghilangkan tebakan itu.
     *
     * Kode referral dan id dicocokkan PERSIS, nama dan username sebagian.
     * Kalau kode dicocokkan sebagian, satu huruf yang kebetulan sama membuat
     * orang yang tidak bersalah ikut muncul di layar pemeriksaan kecurangan.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function cari(string $kueri): \Illuminate\Support\Collection
    {
        $bersih = ltrim($kueri, '@');

        return User::query()
            ->where(function ($q) use ($kueri, $bersih) {

                $q->where('referral_code', $bersih)
                    ->orWhere('telegram_username', $bersih)
                    ->orWhere('name', 'like', '%'.$bersih.'%')
                    ->orWhere('telegram_username', 'like', '%'.$bersih.'%');

                // Angka bisa berarti dua hal, dan keduanya diperiksa: id
                // internal yang dipakai panel, dan id Telegram yang muncul di
                // log. Membedakannya lebih dulu berarti admin harus tahu yang
                // mana yang sedang ia pegang.
                if (ctype_digit($bersih)) {
                    $q->orWhere('id', (int) $bersih)
                        ->orWhere('telegram_id', $bersih);
                }
            })
            ->orderByDesc('id')
            ->limit(self::BATAS + 1)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Tampilan
    |--------------------------------------------------------------------------
    */

    /** Ringkasan seluruh program, saat perintah dipanggil tanpa kata kunci. */
    private function ringkasan(int|string $chatId): void
    {
        $pesan = Notice::make('🤝', 'Pemantau Affiliate');

        $komisiSah = ReferralCommission::where('status', '!=', 'void');

        $pesan->branch('📊', 'Program', [
            'Status'          => $this->referral->enabled() ? 'aktif' : 'NONAKTIF',
            'Total komisi'    => Uang::format((float) (clone $komisiSah)->sum('amount')),
            'Jumlah transaksi' => (string) (clone $komisiSah)->count().' kali',
            'Komisi dibatalkan' => (string) ReferralCommission::where('status', 'void')->count().' kali',
            'Afiliasi aktif'  => (string) ReferralCommission::where('status', '!=', 'void')
                ->distinct('referrer_id')->count('referrer_id').' orang',
        ]);

        $pesan->branch('💸', 'Penarikan', [
            'Menunggu diproses' => (string) ReferralWithdrawal::where('status', 'pending')->count().' permintaan',
            'Nilai menunggu'    => Uang::format((float) ReferralWithdrawal::where('status', 'pending')->sum('amount')),
            'Sudah dibayar'     => Uang::format((float) ReferralWithdrawal::where('status', 'paid')->sum('amount')),
        ]);

        /*
        | Lima penghasil komisi terbesar.
        |
        | Ditampilkan lebih dulu karena di sanalah kecurangan paling mahal
        | berada: seseorang yang mengumpulkan komisi kecil dari banyak akun
        | palsu tetap akan naik ke daftar ini begitu jumlahnya berarti.
        */
        $teratas = ReferralCommission::query()
            ->select('referrer_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as jumlah'))
            ->where('status', '!=', 'void')
            ->groupBy('referrer_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($teratas->isNotEmpty()) {

            $baris = [];

            foreach ($teratas as $urutan => $satu) {

                $pemilik = User::find($satu->referrer_id);

                $baris[($urutan + 1).'. '.$this->nama($pemilik)] =
                    Uang::format((float) $satu->total).' / '.$satu->jumlah.' trx';
            }

            $pesan->branch('🏆', 'Komisi terbesar', $baris);
        }

        $pesan->text('Cari satu orang:');

        $pesan->code('/afiliasi <nama, @username, kode referral, atau id>');

        $this->telegram->sendMessage($chatId, $pesan->render());
    }

    /**
     * Daftar ringkas saat pencarian menghasilkan banyak orang.
     *
     * @param  \Illuminate\Support\Collection<int,User>  $hasil
     */
    private function daftar(\Illuminate\Support\Collection $hasil, string $kueri): string
    {
        $lebih = $hasil->count() > self::BATAS;

        $pesan = Notice::make('🔍', 'Hasil pencarian')
            ->lead('Beberapa pengguna cocok dengan "'.$kueri.'".');

        $baris = [];

        foreach ($hasil->take(self::BATAS) as $satu) {

            $baris[$this->nama($satu)] = 'kode '
                .($satu->referral_code ?: '—')
                .' · '.Uang::format($this->referral->balance($satu));
        }

        $pesan->branch('👥', 'Cocok', $baris);

        if ($lebih) {
            $pesan->text('Masih ada yang lain. Persempit pencariannya — '
                .'kode referral dan id selalu menghasilkan satu orang.');
        }

        $pesan->note('Cari ulang dengan kode referral atau id untuk melihat rinciannya.');

        return $pesan->render();
    }

    /**
     * Rincian satu orang, dengan angka yang dipakai memeriksa kewajaran.
     */
    private function detail(User $orang): string
    {
        $data = $this->referral->summary($orang);

        $pesan = Notice::make('🕵️', 'Rincian Affiliate');

        $pesan->branch('🪪', 'Akun', [
            'Nama'        => $orang->name,
            'Username'    => filled($orang->telegram_username) ? '@'.$orang->telegram_username : null,
            'ID pengguna' => (string) $orang->id,
            'ID Telegram' => $orang->telegram_id ? (string) $orang->telegram_id : null,
            'Bergabung'   => Waktu::lengkap($orang->created_at, '-'),
            'Status'      => $orang->is_banned ? '⛔ DIBLOKIR' : ($orang->is_premium ? 'VIP aktif' : 'Member'),
        ]);

        /*
        | Siapa yang mengundang orang ini.
        |
        | Ini kolom terpenting untuk kecurangan berantai: satu orang membuat
        | beberapa akun, saling mengundang, lalu membeli paket termurah
        | berulang kali. Rantainya baru terlihat kalau arah undangannya bisa
        | ditelusuri ke atas.
        */
        $pengundang = $orang->referred_by_id ? User::find($orang->referred_by_id) : null;

        $pesan->branch('🔗', 'Referral', [
            'Kode'          => $orang->referral_code ?: '(belum punya)',
            'Level'         => 'Level '.$data['level'].' — '.$this->persen($data['rate']).'%',
            'Diundang oleh' => $pengundang !== null
                ? $this->nama($pengundang).' (id '.$pengundang->id.')'
                : 'tidak ada',
            'Diundang pada' => $orang->referred_at
                ? Waktu::lengkap($orang->referred_at, '-')
                : null,
        ]);

        $tertahan = (float) ReferralCommission::where('referrer_id', $orang->id)
            ->where('status', 'pending')->sum('amount');

        $dibatalkan = ReferralCommission::where('referrer_id', $orang->id)
            ->where('status', 'void')->count();

        $pesan->branch('📈', 'Angka', [
            'Kunjungan tautan' => (string) $data['visits'].' kali',
            'Mengundang'       => (string) $data['total_referrals'].' orang',
            'Transaksi masuk'  => (string) $data['transactions'].' kali',
            'Total komisi'     => Uang::format((float) $data['commission']),
            'Masih tertahan'   => $tertahan > 0 ? Uang::format($tertahan) : null,
            'Saldo tersedia'   => Uang::format((float) $data['balance']),
            'Komisi dibatalkan' => $dibatalkan > 0 ? $dibatalkan.' kali' : null,
        ]);

        $this->penarikan($pesan, $orang);

        $this->kejanggalan($pesan, $orang, $dibatalkan);

        $this->riwayat($pesan, $orang);

        return $pesan->render();
    }

    /** Penarikan dana orang ini, hanya bila pernah ada. */
    private function penarikan(Notice $pesan, User $orang): void
    {
        $semua = ReferralWithdrawal::where('user_id', $orang->id)->get();

        if ($semua->isEmpty()) {
            return;
        }

        $pesan->branch('💸', 'Penarikan', [
            'Menunggu'  => $this->rupiahBila($semua->where('status', 'pending')->sum('amount')),
            'Disetujui' => $this->rupiahBila($semua->where('status', 'approved')->sum('amount')),
            'Dibayar'   => $this->rupiahBila($semua->where('status', 'paid')->sum('amount')),
            'Ditolak'   => $this->rupiahBila($semua->where('status', 'rejected')->sum('amount')),
        ]);
    }

    /**
     * Tanda-tanda yang pantas diperiksa manusia.
     *
     * ## Kenapa "perlu diperiksa", bukan "curang"
     *
     * Setiap tanda di sini punya penjelasan yang sah. Sepuluh pendaftaran
     * dalam satu jam bisa berarti akun palsu, bisa juga berarti tautannya
     * baru dibagikan di grup yang ramai. Menyebutnya kecurangan berarti
     * menuduh, dan tuduhan yang salah pada afiliasi terbaik jauh lebih mahal
     * daripada kecurangan kecil yang lolos.
     *
     * Jadi yang dilakukan hanya menunjukkan angkanya. Keputusannya tetap pada
     * orang yang membacanya, dengan konteks yang tidak dimiliki kode ini.
     *
     * Bagian ini hilang sepenuhnya bila tidak ada yang janggal — daftar
     * peringatan yang selalu muncul berhenti dibaca.
     */
    private function kejanggalan(Notice $pesan, User $orang, int $dibatalkan): void
    {
        $tanda = [];

        // Mengundang diri sendiri. Seharusnya dicegah saat pemasangan kode,
        // jadi kalau muncul di sini berarti ada jalan yang terlewat.
        if ($orang->referred_by_id === $orang->id) {
            $tanda['Mengundang diri sendiri'] = 'ya — periksa cara kodenya dipasang';
        }

        // Pendaftaran menumpuk di jam yang sama.
        $lonjakan = User::query()
            ->where('referred_by_id', $orang->id)
            ->whereNotNull('referred_at')
            ->selectRaw("DATE_FORMAT(referred_at, '%Y-%m-%d %H:00') as jam, COUNT(*) as jumlah")
            ->groupBy('jam')
            ->orderByDesc('jumlah')
            ->first();

        if ($lonjakan !== null && (int) $lonjakan->jumlah >= 5) {
            $tanda['Undangan terpadat'] = $lonjakan->jumlah.' orang dalam 1 jam ('.$lonjakan->jam.')';
        }

        // Undangan banyak tanpa satu pun kunjungan tautan. Pendaftaran lewat
        // deep link bot memang tidak selalu tercatat sebagai kunjungan, jadi
        // ini petunjuk lemah — tetapi tetap layak dilihat bila jumlahnya besar.
        $undangan = User::where('referred_by_id', $orang->id)->count();

        $kunjungan = \App\Models\ReferralVisit::where('referrer_id', $orang->id)->count();

        if ($undangan >= 10 && $kunjungan === 0) {
            $tanda['Undangan tanpa kunjungan'] = $undangan.' orang, 0 kunjungan tautan';
        }

        if ($dibatalkan >= 3) {
            $tanda['Komisi pernah dibatalkan'] = $dibatalkan.' kali';
        }

        if ($tanda === []) {
            return;
        }

        $pesan->branch('⚠️', 'Perlu diperiksa', $tanda);
    }

    /** Beberapa komisi terakhir, untuk melihat polanya. */
    private function riwayat(Notice $pesan, User $orang): void
    {
        $komisi = ReferralCommission::query()
            ->with(['referredUser', 'invoice'])
            ->where('referrer_id', $orang->id)
            ->latest('id')
            ->limit(self::RIWAYAT)
            ->get();

        if ($komisi->isEmpty()) {
            return;
        }

        $baris = [];

        foreach ($komisi as $satu) {

            $pembeli = $satu->referredUser ?? User::find($satu->referred_user_id);

            $label = Waktu::ringkas($satu->created_at, '-').' · '.$this->nama($pembeli);

            // Kunci array tidak boleh bertabrakan: dua komisi pada menit yang
            // sama dari pembeli yang sama akan saling menimpa dan satu barisnya
            // hilang tanpa jejak. Id komisinya membuat setiap kunci unik.
            $baris[$label.' #'.$satu->id] = Uang::format((float) $satu->amount)
                .' ('.$this->persen((float) $satu->rate).'%)'
                .($satu->status === 'void' ? ' — DIBATALKAN' : '');
        }

        $pesan->branch('🧾', 'Komisi terakhir', $baris);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /** Nama yang bisa dikenali admin, apa pun yang tersedia. */
    private function nama(?User $orang): string
    {
        if ($orang === null) {
            return '(pengguna terhapus)';
        }

        if (filled($orang->telegram_username)) {
            return '@'.$orang->telegram_username;
        }

        return filled($orang->name) ? $orang->name : 'Pengguna #'.$orang->id;
    }

    /** Persen tanpa nol di belakang koma: 25,00 jadi 25. */
    private function persen(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
    }

    /** Rupiah bila nilainya berarti, null bila nol — supaya barisnya hilang. */
    private function rupiahBila(mixed $nilai): ?string
    {
        return (float) $nilai > 0 ? Uang::format((float) $nilai) : null;
    }
}
