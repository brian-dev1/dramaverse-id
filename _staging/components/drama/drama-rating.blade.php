@props([
    'drama',
])

@php

$rating = $drama->rating ?? 9.8;

$totalVote = $drama->rating_count ?? rand(1000,8000);

@endphp

<div class="web-rating-card">

    <div class="web-rating-header">

        <h3>

            Rating

        </h3>

    </div>

    <div class="web-rating-score">

        {{ number_format($rating,1) }}

    </div>

    <div class="web-rating-stars">

        @for($i=1;$i<=5;$i++)

            ⭐

        @endfor

    </div>

    <div class="web-rating-vote">

        {{ number_format($totalVote) }}

        Pengguna

    </div>

    <div class="web-rating-progress">

        <div class="web-progress-item">

            <span>5 ⭐</span>

            <div class="web-progress">

                <div
                    class="fill"
                    style="width:88%"></div>

            </div>

        </div>

        <div class="web-progress-item">

            <span>4 ⭐</span>

            <div class="web-progress">

                <div
                    class="fill"
                    style="width:72%"></div>

            </div>

        </div>

        <div class="web-progress-item">

            <span>3 ⭐</span>

            <div class="web-progress">

                <div
                    class="fill"
                    style="width:31%"></div>

            </div>

        </div>

        <div class="web-progress-item">

            <span>2 ⭐</span>

            <div class="web-progress">

                <div
                    class="fill"
                    style="width:12%"></div>

            </div>

        </div>

        <div class="web-progress-item">

            <span>1 ⭐</span>

            <div class="web-progress">

                <div
                    class="fill"
                    style="width:4%"></div>

            </div>

        </div>

    </div>

</div>