@props([
    'membership' => null,
])

<section class="section section-pad">

    <div class="membership-banner">

        <div class="membership-glow glow-1"></div>

        <div class="membership-glow glow-2"></div>

        <div class="membership-pattern"></div>

        <div class="membership-content">

            <div class="membership-left">

                @if(!$membership)

                    <span class="membership-label">

                        ✨ PREMIUM ACCESS

                    </span>

                    <h2>

                        Nikmati Semua Episode Tanpa Batas

                    </h2>

                    <p>

                        Berlangganan DramaVerse Premium untuk membuka seluruh episode,
                        streaming Full HD tanpa iklan, download offline,
                        serta akses episode terbaru lebih cepat.

                    </p>

                    <div class="membership-feature-list">

                        <div>
                            ✔ Tanpa Iklan
                        </div>

                        <div>
                            ✔ Full HD 1080P
                        </div>

                        <div>
                            ✔ Download Offline
                        </div>

                        <div>
                            ✔ Early Access
                        </div>

                    </div>

                    <div class="membership-actions">

                        <a
                            href="{{ route('web.membership') }}"
                            class="btn btn-primary">

                            Upgrade Membership

                        </a>

                    </div>

                @elseif($membership->isExpired())

                    <span class="membership-label danger">

                        🔴 MEMBERSHIP BERAKHIR

                    </span>

                    <h2>

                        Membership Kamu Sudah Berakhir

                    </h2>

                    <p>

                        Perpanjang membership agar kembali menikmati seluruh
                        drama premium tanpa batas.

                    </p>

                    <div class="membership-feature-list">

                        <div>
                            ✔ Aktifkan Kembali
                        </div>

                        <div>
                            ✔ Semua Episode
                        </div>

                        <div>
                            ✔ Full HD
                        </div>

                    </div>

                    <div class="membership-actions">

                        <a
                            href="{{ route('web.membership') }}"
                            class="btn btn-primary">

                            Perpanjang Sekarang

                        </a>

                    </div>

                @elseif($membership->remaining_days <= 7)

                    <span class="membership-label warning">

                        🟠 AKAN BERAKHIR

                    </span>

                    <h2>

                        Tinggal {{ $membership->remaining_days }} Hari Lagi

                    </h2>

                    <p>

                        Jangan sampai akses premium kamu terputus.
                        Perpanjang sekarang agar tetap menikmati seluruh fitur.

                    </p>

                    <div class="membership-feature-list">

                        <div>
                            ✔ Semua Episode
                        </div>

                        <div>
                            ✔ Tanpa Iklan
                        </div>

                        <div>
                            ✔ Full HD
                        </div>

                    </div>

                    <div class="membership-actions">

                        <a
                            href="{{ route('web.membership') }}"
                            class="btn btn-primary">

                            Perpanjang Membership

                        </a>

                    </div>

                @else

                    <span class="membership-label success">

                        🟢 PREMIUM AKTIF

                    </span>

                    <h2>

                        Membership Premium Aktif

                    </h2>

                    <p>

                        Berlaku sampai

                        <strong>

                            {{ $membership->expired_at->format('d M Y') }}

                        </strong>

                    </p>

                    <div class="membership-feature-list">

                        <div>
                            ✔ Tanpa Iklan
                        </div>

                        <div>
                            ✔ Full HD
                        </div>

                        <div>
                            ✔ Priority Server
                        </div>

                        <div>
                            ✔ Download Offline
                        </div>

                    </div>

                    <div class="membership-actions">

                        <a
                            href="{{ route('web.account') }}"
                            class="btn btn-ghost">

                            Kelola Membership

                        </a>

                    </div>

                @endif

            </div>

            <div class="membership-right">

                <div class="premium-card">

                    <div class="premium-icon">

                        👑

                    </div>

                    <h3>

                        DramaVerse Premium

                    </h3>

                    <p>

                        Streaming lebih nyaman dengan kualitas terbaik,
                        akses lebih cepat, dan pengalaman tanpa iklan.

                    </p>

                    <ul>

                        <li>✔ Unlimited Streaming</li>

                        <li>✔ Full HD Quality</li>

                        <li>✔ Priority Access</li>

                        <li>✔ Download Offline</li>

                    </ul>

                </div>

            </div>

        </div>

    </div>

</section>