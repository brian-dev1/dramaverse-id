<section class="web-drama-rating">

    <div class="container">

        <div class="web-rating-card">

            <div class="web-rating-score">

                <h2>

                    9.8

                </h2>

                <span>

                    Excellent

                </span>

            </div>

            <div class="web-rating-progress">

                @for($i=5;$i>=1;$i--)

                    <div class="web-rating-row">

                        <span>

                            {{ $i }} ★

                        </span>

                        <div class="web-rating-bar">

                            <div
                                class="web-rating-fill"
                                style="width:{{ 100-($i*10) }}%;">

                            </div>

                        </div>

                    </div>

                @endfor

            </div>

        </div>

    </div>

</section>