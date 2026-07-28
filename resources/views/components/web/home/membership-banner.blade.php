@props([
    'membership' => null,
])

<section class="section section-pad">

    <div class="membership-banner">

        <div class="membership-content">

            @if(!$membership)

                <span class="membership-label">

                    PREMIUM ACCESS

                </span>

                <h2>

                    Nikmati Semua Episode Tanpa Batas

                </h2>

                <p>

                    Berlangganan DramaVerse Premium untuk membuka seluruh
                    episode, kualitas video terbaik, tanpa iklan,
                    dan update episode lebih cepat.

                </p>

                <div class="membership-actions">

                    <a
                        href="{{ route('web.membership') }}"
                        class="btn btn-primary">

                        Upgrade Membership

                    </a>

                </div>

            @elseif($membership->isExpired())

                <span class="membership-label danger">

                    MEMBERSHIP BERAKHIR

                </span>

                <h2>

                    Membership Kamu Sudah Berakhir

                </h2>

                <p>

                    Perpanjang membership untuk kembali menikmati
                    seluruh drama premium.

                </p>

                <a
                    href="{{ route('web.membership') }}"
                    class="btn btn-primary">

                    Perpanjang Sekarang

                </a>

            @elseif($membership->remaining_days <= 7)

                <span class="membership-label warning">

                    AKAN BERAKHIR

                </span>

                <h2>

                    Tinggal {{ $membership->remaining_days }} Hari Lagi

                </h2>

                <p>

                    Jangan sampai akses premium terputus.

                </p>

                <a
                    href="{{ route('web.membership') }}"
                    class="btn btn-primary">

                    Perpanjang

                </a>

            @else

                <span class="membership-label success">

                    PREMIUM AKTIF

                </span>

                <h2>

                    Membership Premium Aktif

                </h2>

                <p>

                    Berlaku sampai

                    {{ $membership->expired_at->format('d M Y') }}

                </p>

                <a
                    href="{{ route('web.account') }}"
                    class="btn btn-ghost">

                    Kelola Akun

                </a>

            @endif

        </div>

    </div>

</section>