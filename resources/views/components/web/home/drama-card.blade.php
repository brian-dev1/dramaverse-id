@props([
    'drama',
    'type' => 'default',
    'rank' => null,
    'progress' => null,
])

<a
    href="{{ route('web.detail', $drama->slug) }}"
    class="drama-card {{ $drama->gradient ?? 'g1' }}">

    <div class="drama-poster">

        @if(!empty($drama->poster_url))
            <img
                src="{{ $drama->poster_url }}"
                alt="{{ $drama->title }}">
        @endif

        <div class="drama-overlay"></div>

        {{-- TOP --}}
        <div class="card-top">

            @if($type == 'continue')

                <span class="episode-badge">
                    EP {{ str_pad($drama->last_episode ?? 1,2,'0',STR_PAD_LEFT) }}
                </span>

            @elseif($type == 'latest')

                <span class="episode-badge">
                    BARU
                </span>

            @elseif($type == 'toprated')

                <span class="vip-badge">
                    ★ {{ number_format($drama->rating,1) }}
                </span>

            @elseif($rank)

                <span class="vip-badge">
                    #{{ str_pad($rank,2,'0',STR_PAD_LEFT) }}
                </span>

            @endif

        </div>

        <div class="drama-content">

            <div class="drama-title">

                {{ $drama->title }}

            </div>

            @switch($type)

                @case('continue')

                    <div class="drama-info">

                        <span>{{ $progress ?? 0 }}% selesai</span>

                    </div>

                    <div class="card-progress">

                        <i style="width:{{ $progress ?? 0 }}%"></i>

                    </div>

                @break

                @case('latest')

                    <div class="drama-info">

                        <span>
                            EP {{ $drama->episodes }}
                        </span>

                        <span>
                            {{ $drama->country }}
                        </span>

                    </div>

                @break

                @case('toprated')

                    <div class="drama-info">

                        <span>

                            {{ $drama->genre }}

                        </span>

                        <span>

                            ★ {{ number_format($drama->rating,1) }}

                        </span>

                    </div>

                @break

                @default

                    <div class="drama-info">

                        <span>

                            ★ {{ number_format($drama->rating,1) }}

                        </span>

                        <span>

                            {{ $drama->country }}

                        </span>

                    </div>

            @endswitch

            <div class="card-actions">

                <span class="card-btn">

                    Tonton Sekarang

                </span>

            </div>

        </div>

    </div>

</a>