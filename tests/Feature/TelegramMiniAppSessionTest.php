<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi Mini App harus mengikuti akun yang sedang membuka, bukan cookie.
 *
 * ## Kenapa tes ini ada
 *
 * Telegram memakai satu webview untuk SEMUA akun yang masuk di perangkat
 * itu, dan cookie sesi ikut dipakai bersama. Akun B yang membuka Mini App
 * karena itu datang membawa cookie milik akun A. Versi lama berhenti di
 * `if (Auth::check())` dan menjawab "sudah login" tanpa pernah melihat
 * initData — sehingga B melihat riwayat tontonan, status VIP, dan saldo
 * referral milik A.
 *
 * Bug itu tidak terlihat dari kode yang sepintas benar: pemeriksaan
 * `Auth::check()` di awal sebuah endpoint login adalah pola yang lazim dan
 * biasanya memang penghematan. Yang membuatnya salah cuma satu keadaan —
 * cookie dan identitas bisa berbeda pemilik — dan keadaan itu tidak pernah
 * muncul di aplikasi web biasa. Karena itu ia dikunci di sini, bukan cukup
 * dijelaskan lewat komentar di controller.
 *
 * ## Yang TIDAK diuji di sini
 *
 * Bahwa halaman mengirim initData-nya. Itu urusan
 * `resources/views/web/partials/miniapp.blade.php` dan hanya bisa dibuktikan
 * di webview Telegram sungguhan.
 */
class TelegramMiniAppSessionTest extends TestCase
{
    use RefreshDatabase;

    /** Token bot palsu. Yang penting sama antara penanda tangan dan penguji. */
    private const TOKEN = '123456:token-uji-coba';

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.bot_token' => self::TOKEN]);
    }

    public function test_tamu_login_memakai_akun_di_dalam_init_data(): void
    {
        $jawaban = $this->postJson(route('web.telegram.miniapp'), [
            'init_data' => $this->initData(['id' => 111, 'first_name' => 'Ani']),
        ]);

        $jawaban->assertOk()->assertJson(['ok' => true, 'switched' => false]);

        $this->assertAuthenticated();

        $this->assertSame(111, (int) auth()->user()->telegram_id);
    }

    /**
     * Inti dari seluruh berkas ini.
     *
     * Sesi milik A, initData milik B. Yang harus menang adalah initData: ia
     * ditandatangani Telegram beberapa detik lalu, sementara cookie bisa
     * berumur berbulan-bulan.
     */
    public function test_sesi_berpindah_ketika_init_data_milik_akun_lain(): void
    {
        $a = User::factory()->create(['telegram_id' => 111]);

        $b = User::factory()->create(['telegram_id' => 222]);

        $jawaban = $this->actingAs($a)->postJson(route('web.telegram.miniapp'), [
            'init_data' => $this->initData(['id' => 222, 'first_name' => 'Budi']),
        ]);

        $jawaban->assertOk()->assertJson(['ok' => true, 'switched' => true]);

        $this->assertAuthenticatedAs($b->fresh());
    }

    /**
     * Sesi yang sudah benar tidak diapa-apakan.
     *
     * Bukan sekadar penghematan: memutar ulang sesi pada setiap pembukaan
     * halaman membuat tab lain yang sedang terbuka ikut kehilangan sesinya.
     */
    public function test_sesi_yang_cocok_dibiarkan_apa_adanya(): void
    {
        $a = User::factory()->create(['telegram_id' => 111]);

        $jawaban = $this->actingAs($a)->postJson(route('web.telegram.miniapp'), [
            'init_data' => $this->initData(['id' => 111, 'first_name' => 'Ani']),
        ]);

        $jawaban->assertOk()->assertJson(['ok' => true, 'already' => true]);

        $this->assertAuthenticatedAs($a);
    }

    /**
     * initData tidak sah TIDAK memutus sesi yang sedang berjalan.
     *
     * Paling sering itu berarti webview dibiarkan terbuka semalaman sampai
     * initData-nya basi — bukan berarti orangnya bukan pemilik sesi.
     * Melempar keluar setiap kali data itu kedaluwarsa berarti pengguna yang
     * benar kehilangan sesinya tanpa sebab.
     */
    public function test_init_data_palsu_tidak_menendang_sesi_yang_ada(): void
    {
        $a = User::factory()->create(['telegram_id' => 111]);

        $jawaban = $this->actingAs($a)->postJson(route('web.telegram.miniapp'), [
            'init_data' => $this->initData(['id' => 222], token: 'token-yang-salah'),
        ]);

        $jawaban->assertStatus(403);

        $this->assertAuthenticatedAs($a);
    }

    /**
     * Akun baru yang diblokir tetap harus mengusir sesi akun lama.
     *
     * Kalau pemeriksaan blokir dikerjakan sebelum sesi lama dibuang, yang
     * terjadi adalah orang yang aksesnya ditolak tetap duduk di dalam sesi
     * milik akun lain — persis kebocoran yang hendak ditutup berkas ini.
     */
    public function test_akun_baru_yang_diblokir_tidak_mewarisi_sesi_lama(): void
    {
        $a = User::factory()->create(['telegram_id' => 111]);

        User::factory()->create(['telegram_id' => 222, 'is_banned' => true]);

        $jawaban = $this->actingAs($a)->postJson(route('web.telegram.miniapp'), [
            'init_data' => $this->initData(['id' => 222, 'first_name' => 'Budi']),
        ]);

        $jawaban->assertStatus(403)->assertJson(['ok' => false, 'reset' => true]);

        $this->assertGuest();
    }

    /**
     * Susun initData bertanda tangan, seperti yang dikirim Telegram.
     *
     * Ditulis di sini apa adanya — bukan memanggil TelegramInitData — supaya
     * tesnya benar-benar menguji verifikasinya. Penanda tangan yang memakai
     * kode yang sama dengan pengujinya akan selalu cocok, termasuk ketika
     * keduanya sama-sama salah.
     */
    private function initData(array $user, ?int $authDate = null, string $token = self::TOKEN): string
    {
        $isi = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id'  => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user'      => json_encode($user, JSON_UNESCAPED_UNICODE),
        ];

        ksort($isi);

        $pasangan = [];

        foreach ($isi as $kunci => $nilai) {
            $pasangan[] = $kunci.'='.$nilai;
        }

        $rahasia = hash_hmac('sha256', $token, 'WebAppData', true);

        $hash = hash_hmac('sha256', implode("\n", $pasangan), $rahasia);

        $bagian = [];

        foreach ($isi as $kunci => $nilai) {
            $bagian[] = rawurlencode($kunci).'='.rawurlencode($nilai);
        }

        $bagian[] = 'hash='.$hash;

        return implode('&', $bagian);
    }
}
