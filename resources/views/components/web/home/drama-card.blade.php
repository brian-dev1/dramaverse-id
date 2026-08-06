@props([
    'drama',
    'variant' => 'default',   // default | continue | latest | rank | rated
    'rank'     => null,
    'progress' => null,
    'episode'  => null,
])

<a href="{{ route('web.drama.show', $drama->slug) }}"
   class="drama-card {{ $drama->gradient ?? 'g1' }}"
   aria-label="{{ $drama->title }}">

    @if ($drama->poster_url)
        <img src="{{ $drama->poster_url }}" alt="" loading="lazy" class="drama-poster-img">
    @endif

    <div class="drama-poster">

        <div class="card-top">
            @switch($variant)
                @case('continue')
                    <span class="card-badge">EP {{ str_pad($episode ?? 1, 2, '0', STR_PAD_LEFT) }}</span>
                    @break

                @case('latest')
                    <span class="card-badge">BARU</span>
                    @break

                @case('rated')
                    <span class="card-badge">TOP</span>
                    @break

                @case('rank')
                    <span class="card-rank">{{ str_pad($rank, 2, '0', STR_PAD_LEFT) }}</span>
                    @break
            @endswitch

            @if ($drama->is_vip && $variant !== 'rank')
                <span class="card-badge">VIP</span>
            @endif
        </div>

        <div class="drama-content">

            <div class="card-title">{{ $drama->title }}</div>

            @if ($variant === 'continue')
                <div class="card-sub">{{ $progress ?? 0 }}% selesai</div>
                <div class="card-progress"><i style="width:{{ $progress ?? 0 }}%"></i></div>
            @else
                <div class="card-sub">
                    @if ($drama->relationLoaded('country') && $drama->country)
                        <span>{{ $drama->country->name }}</span>
                    @elseif ($drama->total_episode)
                        <span>{{ $drama->total_episode }} EP</span>
                    @endif
                </div>
            @endif

        </div>
    </div>
</a>
