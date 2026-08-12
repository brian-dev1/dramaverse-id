@php
    /*
    | Empat tujuan, sama untuk semua orang.
    |
    | "Cari" tidak ada di sini: kolom pencarian sudah tetap di bilah atas.
    |
    | Susunannya TIDAK lagi dibedakan antara tamu dan pengguna yang sudah
    | masuk. Dulu dibedakan karena Riwayat dan Profil ber-middleware `auth`
    | dan tautannya akan buntu; sejak `redirectGuestsTo()` di bootstrap/app.php
    | mengarahkan tamu ke beranda dengan `?masuk=1`, keduanya aman ditampilkan
    | kepada siapa saja. Dan itu memang yang harus terjadi: bilah bawah yang
    | isinya berubah-ubah membuat orang kehilangan letak tombol yang kemarin
    | ada di situ.
    |
    | VIP dipasang di tengah dan diberi gaya sendiri (`is-vip`) — lihat
    | vip-nav di miniapp-polish.css.
    */
    $items = [
        ['route' => 'web.home',       'icon' => 'home',  'label' => 'Beranda'],
        ['route' => 'web.history',    'icon' => 'clock', 'label' => 'Riwayat'],
        ['route' => 'web.membership', 'icon' => 'crown', 'label' => 'VIP', 'vip' => true],
        ['route' => 'web.profile',    'icon' => 'user',  'label' => 'Profil'],
    ];
@endphp

<nav class="mobile-nav" aria-label="Navigasi utama">
    @foreach ($items as $item)
        {{--
            Label tetap ditulis, tapi disembunyikan secara visual (.sr-only).
            Bilahnya memang hanya ikon — itu bentuk yang diminta — namun ikon
            telanjang tidak menyebutkan namanya kepada pembaca layar, dan
            `aria-label` saja akan hilang bersama teksnya bila CSS gagal
            dimuat. Cara ini menyimpan keduanya.
        --}}
        <a href="{{ route($item['route']) }}"
           class="{{ request()->routeIs($item['route']) ? 'active' : '' }} {{ ($item['vip'] ?? false) ? 'is-vip' : '' }}"
           @if (request()->routeIs($item['route'])) aria-current="page" @endif>
            <x-web.home.icon :name="$item['icon']" :size="21" />
            <span class="sr-only">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
