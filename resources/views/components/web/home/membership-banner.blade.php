<div class="membership">

    <div class="membership-copy">
        <span class="eyebrow">DRAMAVERSE PREMIUM</span>
        <h3>Semua drama, tanpa iklan, tanpa jeda.</h3>
        <p>Buka akses penuh ke seluruh katalog, rilis lebih awal, dan kualitas hingga 4K.</p>
    </div>

    <ul class="membership-perks">
        @foreach (['Tanpa iklan', 'Kualitas 4K', 'Unduh offline', 'Rilis lebih awal'] as $perk)
            <li class="perk">
                <x-web.home.icon name="check" :size="12" />
                {{ $perk }}
            </li>
        @endforeach
    </ul>

    <a href="{{ route('web.membership') }}" class="btn btn-primary">Mulai Membership</a>

</div>
