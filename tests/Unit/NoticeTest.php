<?php

namespace Tests\Unit;

use App\Support\Telegram\Notice;
use Tests\TestCase;

/**
 * Yang diuji di sini bukan "apakah hurufnya sama", melainkan tiga janji yang
 * kalau dilanggar merusak pesan sungguhan: label yang sejajar, nilai kosong
 * yang tidak dicetak, dan nilai dari basis data yang tidak bisa mematahkan
 * parse HTML Telegram.
 */
class NoticeTest extends TestCase
{
    public function test_label_disejajarkan_dengan_spasi(): void
    {
        $hasil = Notice::make('✅', 'Pembayaran berhasil')
            ->rows(['Paket' => 'VIP', 'Berlaku sampai' => '09 Aug 2026'])
            ->render();

        $this->assertStringContainsString('Paket          : VIP', $hasil);
        $this->assertStringContainsString('Berlaku sampai : 09 Aug 2026', $hasil);
    }

    public function test_nilai_kosong_dibuang(): void
    {
        $hasil = Notice::make('✅', 'Uji')
            ->rows(['Paket' => 'VIP', 'Berlaku sampai' => null, 'Catatan' => ''])
            ->render();

        $this->assertStringNotContainsString('Berlaku sampai', $hasil);
        $this->assertStringNotContainsString('Catatan', $hasil);
    }

    public function test_nilai_di_escape(): void
    {
        $hasil = Notice::make('⚠️', 'Uji')
            ->rows(['Paket' => '<b>nakal</b>'])
            ->render();

        $this->assertStringNotContainsString('<b>nakal</b>', $hasil);
        $this->assertStringContainsString('&lt;b&gt;nakal&lt;/b&gt;', $hasil);
    }

    public function test_judul_selalu_huruf_besar_dan_sub_judul_menempel_isinya(): void
    {
        $hasil = Notice::make('👤', 'Profil')
            ->section('💎', 'Langganan')
            ->rows(['Status' => 'Aktif'])
            ->render();

        $this->assertStringStartsWith('👤 <b>PROFIL</b>', $hasil);
        $this->assertStringContainsString("💎 <b>Langganan</b>\n<pre>Status : Aktif</pre>", $hasil);
    }
}
