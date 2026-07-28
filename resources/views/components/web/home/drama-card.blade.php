@props([

    'drama',

    'type' => 'default',

    'rank' => null,

    'progress' => null,

])

<a
    href="{{ route('web.detail', $drama->slug) }}"
    class="card {{ $drama->gradient ?? 'g1' }}">

    <div class="card-poster">

        {{-- TOP --}}

        <div class="card-top">

            @if($type == 'continue')

                <span class="card-badge">

                    EP {{ str_pad($drama->last_episode ?? 1,2,'0',STR_PAD_LEFT) }}

                </span>

            @elseif($type == 'latest')

                <span class="card-badge">

                    BARU

                </span>

            @elseif($type == 'toprated')

                <span class="card-badge">

                    ★ {{ number_format($drama->rating,1) }}

                </span>

            @elseif($rank)

                <span class="card-rank">

                    {{ str_pad($rank,2,'0',STR_PAD_LEFT) }}

                </span>

            @endif

        </div>

        {{-- BOTTOM --}}

        <div>

            <div class="card-title">

                {{ $drama->title }}

            </div>

            @switch($type)

                @case('continue')

                    <div class="card-sub">

                        {{ $progress ?? 0 }}% selesai

                    </div>

                    <div class="card-progress">

                        <i style="width: {{ $progress ?? 0 }}%"></i>

                    </div>

                @break

                @case('latest')

                    <div class="card-sub">

                        EP {{ $drama->episodes }}

                        ·

                        {{ $drama->country }}

                    </div>

                @break

                @case('toprated')

                    <div class="card-sub">

                        {{ $drama->genre }}

                        ·

                        {{ $drama->episodes }} EP

                    </div>

                @break

                @default

                    <div class="card-sub">

                        ★ {{ number_format($drama->rating,1) }}

                        ·

                        {{ $drama->country }}

                    </div>

            @endswitch

        </div>

    </div>

</a>