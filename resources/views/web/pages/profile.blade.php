@extends('web.layouts.app')

@section('title', 'Profil')

@section('content')

    {{--
        Kartu identitas. Menyalin susunan kartu profil di bot: avatar, nama,
        ID yang bisa disalin, lalu lencana VIP di kanan.
    --}}
    <section class="section section-pad">
        <div class="pf-id">
            <div class="pf-id-left">
                <div class="pf-avatar">{{ $user->initial }}</div>
                <div>
                    <h1 class="pf-name">{{ $user->display_name }}</h1>
                    <button type="button" class="pf-uid" data-copy="{{ $user->telegram_id ?? $user->id }}">
                        ID {{ $user->telegram_id ?? $user->id }}
                        <x-web.home.icon name="file" :size="13" />
                    </button>
                </div>
            </div>

            <span class="pf-badge {{ $isVip ? 'on' : '' }}">
                @if ($isVip)<x-web.home.icon name="crown" :size="12" />@endif {{ $isVip ? 'VIP' : 'Gratis' }}
            </span>
        </div>
    </section>

    {{-- Panel keuntungan / ajakan berlangganan --}}
    <section class="section section-pad">
        <div class="pf-vip {{ $isVip ? 'is-vip' : '' }}">
            <div class="pf-vip-head">
                <span class="pf-vip-icon"><x-web.home.icon name="gem" :size="18" /></span>
                <div>
                    <h2 class="pf-vip-title">
                        {{ $isVip ? 'Nikmati Semua Keuntungan Eksklusif sebagai VIP' : 'Jadi VIP dan Buka Semua Keuntungan' }}
                    </h2>
                    <p class="pf-vip-sub">
                        @if ($isVip)
                            Aktif hingga {{ $subscription?->expired_at ? \App\Support\Waktu::ringkas($subscription->expired_at) : 'tanpa batas' }}
                        @else
                            Belum berlangganan
                        @endif
                    </p>
                </div>
            </div>

            <div class="pf-perks">
                <div class="pf-perk">
                    <span class="pf-perk-ico c-orange"><x-web.home.icon name="film" :size="16" /></span>
                    <strong>{{ $perks['katalog'] }}</strong>
                    <span>Koleksi Film</span>
                </div>
                <div class="pf-perk">
                    <span class="pf-perk-ico c-red"><x-web.home.icon name="no-ads" :size="16" /></span>
                    <strong>Bebas Iklan</strong>
                    <span>Tanpa Gangguan</span>
                </div>
                <div class="pf-perk">
                    <span class="pf-perk-ico c-blue"><x-web.home.icon name="download" :size="16" /></span>
                    <strong>Akses Mudah</strong>
                    <span>Web &amp; Telegram</span>
                </div>
                <div class="pf-perk">
                    <span class="pf-perk-ico c-green"><x-web.home.icon name="monitor" :size="16" /></span>
                    <strong>Kualitas</strong>
                    <span>1080p HD</span>
                </div>
            </div>

            <a href="{{ route('web.membership') }}" class="pf-cta">
                <x-web.home.icon name="crown" :size="15" /> {{ $isVip ? 'Perpanjang' : 'Berlangganan' }}
            </a>
        </div>
    </section>

    {{-- Menu utama, meniru daftar tombol besar di bot --}}
    <section class="section section-pad">
        <div class="pf-menu">
            @if ($affiliateEnabled)
                <a href="{{ route('web.affiliate') }}" class="pf-menu-item">
                    <span class="pf-menu-ico bg-violet"><x-web.home.icon name="users" :size="16" /></span>
                    <span class="pf-menu-label">Program Affiliate</span>
                    @if ($affiliateBalance > 0)
                        <span class="pf-menu-tag">Rp {{ number_format($affiliateBalance, 0, ',', '.') }}</span>
                    @endif
                    <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
                </a>
            @endif

            <a href="{{ route('web.history') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-pink"><x-web.home.icon name="clock" :size="16" /></span>
                <span class="pf-menu-label">Riwayat Tontonan</span>
                <span class="pf-menu-tag">{{ $stats['history'] }}</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>

            <a href="{{ route('web.favorites') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-red"><x-web.home.icon name="star" :size="16" /></span>
                <span class="pf-menu-label">Favorit</span>
                <span class="pf-menu-tag">{{ $stats['favorites'] }}</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>

            <a href="{{ route('web.my-list') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-blue"><x-web.home.icon name="list" :size="16" /></span>
                <span class="pf-menu-label">Daftar Saya</span>
                <span class="pf-menu-tag">{{ $stats['myList'] }}</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>

            {{-- Pintu masuk kedua ke Request Drama. Yang pertama muncul saat
                 pencarian tidak menemukan apa pun, tapi orang yang sudah
                 pernah mengirim permintaan datang ke sini untuk mengecek
                 statusnya — bukan mengulang pencarian yang gagal. --}}
            <a href="{{ route('web.request.index') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-blue"><x-web.home.icon name="inbox" :size="16" /></span>
                <span class="pf-menu-label">Request Drama</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>

            <a href="{{ route('web.notifications') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-green"><x-web.home.icon name="send" :size="16" /></span>
                <span class="pf-menu-label">Notifikasi</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>

            <a href="{{ route('web.settings') }}" class="pf-menu-item">
                <span class="pf-menu-ico bg-slate"><x-web.home.icon name="settings" :size="16" /></span>
                <span class="pf-menu-label">Pengaturan</span>
                <x-web.home.icon name="arrow-right" :size="16" class="pf-menu-arrow" />
            </a>
        </div>
    </section>

    {{-- Status akun ringkas --}}
    <section class="section section-pad">
        <x-web.home.section-header title="Status Akun" />

        <div class="account-panel">
            <div class="account-item">
                <span class="k">Paket</span>
                <span class="v {{ $subscription ? 'on' : 'off' }}">
                    <span class="status-dot"></span>{{ $subscription?->plan?->name ?? 'Gratis' }}
                </span>
            </div>

            <div class="account-item">
                <span class="k">Berlaku Sampai</span>
                <span class="v">
                    {{ $subscription?->expired_at ? \App\Support\Waktu::ringkas($subscription->expired_at) : 'Tanpa Batas' }}
                </span>
            </div>

            <div class="account-item">
                <span class="k">Akun Telegram</span>
                <span class="v">{{ $user->telegram_username ? '@'.$user->telegram_username : 'Tersambung' }}</span>
            </div>

            <div class="account-item">
                <span class="k">Bergabung</span>
                <span class="v">{{ \App\Support\Waktu::tanggal($user->created_at) }}</span>
            </div>

            @if ($user->referrer)
                <div class="account-item">
                    <span class="k">Diundang oleh</span>
                    <span class="v">{{ $user->referrer->display_name }}</span>
                </div>
            @endif
        </div>
    </section>

    @if ($continueWatching->isEmpty() && $stats['history'] === 0)
        <x-web.home.empty-state
            title="Belum ada aktivitas"
            message="Riwayat tontonan Anda akan muncul di sini setelah mulai menonton."
            :href="route('web.trending')"
            action="Jelajahi Katalog" />
    @else
        <x-web.home.continue-watching :histories="$continueWatching" />
    @endif

    <section class="section section-pad">
        <form method="POST" action="{{ route('web.logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">Keluar</button>
        </form>
    </section>

    <script>
        // Salin ID pengguna. Tanpa pustaka apa pun; clipboard API dengan
        // cadangan untuk peramban lama / konteks non-HTTPS.
        document.querySelectorAll('[data-copy]').forEach(function (el) {
            el.addEventListener('click', function () {
                var teks = el.dataset.copy;
                var selesai = function () {
                    el.classList.add('copied');
                    setTimeout(function () { el.classList.remove('copied'); }, 1200);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(teks).then(selesai);
                } else {
                    var t = document.createElement('textarea');
                    t.value = teks;
                    t.style.position = 'fixed';
                    t.style.opacity = '0';
                    document.body.appendChild(t);
                    t.select();
                    try { document.execCommand('copy'); selesai(); } catch (e) {}
                    document.body.removeChild(t);
                }
            });
        });
    </script>

@endsection
