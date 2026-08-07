<?php

namespace Tests\Unit;

use App\Support\Waktu;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Basis data menyimpan UTC; layar wajib menampilkan WIB.
 *
 * Bug yang diuji di sini pernah terjadi sungguhan: waktu dicetak langsung
 * dengan `format()` dari instance UTC, sehingga seluruh jam di panel dan di
 * bot mundur tujuh jam — dan tanggal untuk apa pun yang tersimpan sebelum
 * pukul 07.00 WIB ikut mundur satu hari.
 */
class WaktuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Asia/Jakarta']);
    }

    public function test_utc_digeser_ke_wib_beserta_labelnya(): void
    {
        $utc = Carbon::parse('2026-08-07 12:46:00', 'UTC');

        // Nama bulan sengaja tidak dipatok: itu milik locale, dan mengunci
        // "Agt" di sini membuat tes patah begitu locale diganti — padahal
        // yang sedang diuji zona waktunya, bukan ejaan bulannya.
        $this->assertStringContainsString('2026, 19.46 WIB', Waktu::ringkas($utc));
        $this->assertStringContainsString('pukul 19.46 WIB', Waktu::lengkap($utc));
    }

    /** Jam 00.30 WIB masih tanggal 6 menurut UTC. Yang benar tanggal 7. */
    public function test_tanggal_tidak_mundur_sehari_untuk_dini_hari(): void
    {
        $utc = Carbon::parse('2026-08-06 17:30:00', 'UTC');

        $this->assertStringStartsWith('07 ', Waktu::tanggal($utc));
    }

    public function test_presisi_menyertakan_offset(): void
    {
        $utc = Carbon::parse('2026-08-07 12:46:00', 'UTC');

        $this->assertSame('2026-08-07 19:46:00 +07:00', Waktu::presisi($utc));
    }

    public function test_null_jadi_penanda_kosong(): void
    {
        $this->assertSame('—', Waktu::ringkas(null));
        $this->assertSame('-', Waktu::tanggal(null, '-'));
        $this->assertSame('', Waktu::presisi(null));
    }
}
