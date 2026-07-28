@props([
    'drama',
    'episodes' => collect(),
])

<section class="web-section">

    <div class="web-container">

        <div class="web-section-header">

            <div>

                <h2 class="web-section-title">

                    Daftar Episode

                </h2>

                <p class="web-section-description">

                    Pilih episode untuk membuka Tele Player.

                </p>

            </div>

            <div class="web-episode-count">

                {{ $episodes->count() }} Episode

            </div>

        </div>

        <div class="web-episode-grid">

            @foreach($episodes as $episode)

                <article class="web-episode-card">

                    <div class="web-episode-left">

                        <div class="web-episode-number">

                            {{ str_pad($episode->episode_number,2,'0',STR_PAD_LEFT) }}

                        </div>

                        <div>

                            <h3>

                                {{ $episode->title }}

                            </h3>

                            <div class="web-episode-meta">

                                @if($episode->duration)

                                    <span>

                                        {{ $episode->duration }}

                                    </span>

                                @endif

                                @if($episode->is_premium)

                                    <span class="web-badge-premium">

                                        PREMIUM

                                    </span>

                                @endif

                            </div>

                        </div>

                    </div>

                    <div class="web-episode-right">

                        <a
                            href="{{ route('web.telegram.redirect',$episode->id) }}"
                            class="web-btn web-btn-primary">

                            Tonton di Telegram

                        </a>

                    </div>

                </article>

            @endforeach

        </div>

    </div>

</section>