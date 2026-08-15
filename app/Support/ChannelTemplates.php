<?php

namespace App\Support;

/**
 * Template caption channel yang sudah jadi, tinggal dipilih.
 *
 * ## Kenapa ini ada
 *
 * Kolom template menerima teks bebas dengan sebelas placeholder dan enam tag
 * HTML. Keluwesan itu berguna sekali seseorang tahu persis apa yang ia mau —
 * dan melumpuhkan sebelum itu. Yang membuka halaman Pengaturan pertama kali
 * berhadapan dengan kotak teks kosong dan daftar placeholder, lalu menutup
 * halamannya lagi.
 *
 * Pilihan jadi menghilangkan langkah itu: klik satu kartu, kolomnya terisi,
 * sunting seperlunya. Susunan bebas tetap ada bagi yang membutuhkannya.
 *
 * ## Setiap pilihan membawa format barisnya sendiri
 *
 * Template dan format baris episode saling menentukan. Susunan bergaris tebal
 * dengan judul <b> akan terlihat berantakan bila barisnya polos, dan
 * sebaliknya. Memisahkan keduanya berarti admin harus menebak pasangan yang
 * cocok — jadi keduanya dipaketkan.
 */
class ChannelTemplates
{
    /**
     * @return array<int,array{
     *     kunci:string, nama:string, ringkas:string, template:string,
     *     baris:string, gratis:string, vip:string
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'kunci'   => 'lengkap',
                'nama'    => 'Lengkap',
                'ringkas' => 'Poster, info, sinopsis, daftar part, plus tautan cari dan request. '
                    .'Paling cocok untuk channel utama.',
                'template' => <<<'T'
                    🎬 <b>{judul}</b>
                    {negara} • {total_episode} Part • {genre}

                    <blockquote>{sinopsis}</blockquote>

                    ━━━━━━━━━━━━━━━
                    {daftar}
                    ━━━━━━━━━━━━━━━

                    🆓 Gratis   💎 Khusus VIP

                    📺 <a href="{tautan_drama}">Semua part drama ini</a>
                    🔍 <a href="{tautan_cari}">Cari judul lain</a>
                    ⭐ <a href="{tautan_vip}">Buka akses VIP</a>

                    ⚠️ <b>Judul yang dicari tidak ada?</b>
                    📝 <a href="{tautan_request}">Kirim request di sini</a> — kami beri tahu lewat bot begitu dramanya tersedia.
                    T,
                'baris'  => '➤ <b>Part {nomor}</b> | {tanda} → {tautan}',
                'gratis' => '🆓',
                'vip'    => '💎',
            ],

            [
                'kunci'   => 'ringkas',
                'nama'    => 'Ringkas',
                'ringkas' => 'Judul dan daftar part saja. Untuk channel yang memposting '
                    .'banyak drama setiap hari, di mana caption panjang justru melelahkan.',
                'template' => <<<'T'
                    『 {judul} 』

                    {daftar}

                    🔍 <a href="{tautan_cari}">Cari judul lain</a> · 📝 <a href="{tautan_request}">Request drama</a>
                    T,
                'baris'  => '➤ Part {nomor} | {tanda} → {tautan}',
                'gratis' => '🆓',
                'vip'    => '💎',
            ],

            [
                'kunci'   => 'promosi',
                'nama'    => 'Promosi VIP',
                'ringkas' => 'Menonjolkan ajakan berlangganan. Dipakai bila sebagian besar '
                    .'partnya VIP dan tujuan postingannya menjual, bukan sekadar mengabarkan.',
                'template' => <<<'T'
                    🔥 <b>{judul}</b>
                    📍 {negara}  •  🎞 {total_episode} Part  •  {genre}

                    <blockquote>{sinopsis}</blockquote>

                    ━━━━━━━━━━━━━━━
                    {daftar}
                    ━━━━━━━━━━━━━━━

                    💎 <b>Buka semua part tanpa batas</b>
                    Sekali langganan, seluruh katalog terbuka — tanpa iklan, kualitas penuh.

                    ⭐ <a href="{tautan_vip}">Langganan VIP sekarang</a>
                    📺 <a href="{tautan_drama}">Lihat daftar part</a>
                    🔍 <a href="{tautan_cari}">Cari judul lain</a>
                    T,
                'baris'  => '➤ <b>Part {nomor}</b> | {tanda} → {tautan}',
                'gratis' => '🎁',
                'vip'    => '💎',
            ],

            [
                'kunci'   => 'bersih',
                'nama'    => 'Bersih tanpa emoji',
                'ringkas' => 'Hanya teks dan garis pemisah. Untuk channel yang ingin terlihat '
                    .'tenang, atau bila emoji tampil berbeda-beda di perangkat pembacanya.',
                'template' => <<<'T'
                    <b>{judul}</b>
                    {negara} | {total_episode} Part | {genre}

                    <blockquote>{sinopsis}</blockquote>

                    ───────────────
                    {daftar}
                    ───────────────

                    Gratis = tanda G, VIP = tanda V

                    <a href="{tautan_drama}">Semua part</a> · <a href="{tautan_cari}">Cari judul</a> · <a href="{tautan_vip}">Akses VIP</a>
                    <a href="{tautan_request}">Request drama yang belum ada</a>
                    T,
                'baris'  => 'Part {nomor} | {tanda} | {tautan}',
                'gratis' => 'G',
                'vip'    => 'V',
            ],
        ];
    }

    /** Satu template berdasarkan kuncinya, atau null. */
    public static function find(string $kunci): ?array
    {
        return collect(self::all())->firstWhere('kunci', $kunci);
    }
}
