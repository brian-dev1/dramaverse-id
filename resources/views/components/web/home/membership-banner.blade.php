<div class="membership">

    <div class="membership-copy">
        <span class="eyebrow">DRAMAVERSE PREMIUM</span>
        <h3>Semua drama, tanpa iklan, tanpa jeda.</h3>
        <p>Buka akses penuh ke seluruh katalog, rilis lebih awal, dan kualitas hingga 4K.</p>
    </div>

    <div class="membership-perks">
        @foreach (['Tanpa iklan', 'Kualitas 4K', 'Unduh offline', 'Rilis lebih awal'] as $perk)
            <span class="perk">{{ $perk }}</span>
        @endforeach
    </div>

    <a href="{{ route('web.membership') }}" class="btn btn-primary">Mulai Membership</a>

</div>
