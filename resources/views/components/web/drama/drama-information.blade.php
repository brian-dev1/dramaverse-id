@props([
    'drama',
])

<section class="web-drama-information">

    <div class="container">

        <div class="web-drama-information-grid">

            <div class="web-drama-information-left">

                <div class="web-information-card">

                    <h3 class="web-information-title">

                        Informasi Drama

                    </h3>

                    <div class="web-information-list">

                        <div class="web-information-item">
                            <span>Judul</span>
                            <strong>{{ $drama->title }}</strong>
                        </div>

                        @if(!empty($drama->original_title))
                        <div class="web-information-item">
                            <span>Judul Asli</span>
                            <strong>{{ $drama->original_title }}</strong>
                        </div>
                        @endif

                        @if($drama->country)
                        <div class="web-information-item">
                            <span>Negara</span>
                            <strong>{{ $drama->country->name }}</strong>
                        </div>
                        @endif

                        @if($drama->genre)
                        <div class="web-information-item">
                            <span>Genre</span>
                            <strong>{{ $drama->genre->name }}</strong>
                        </div>
                        @endif

                        @if($drama->release_year)
                        <div class="web-information-item">
                            <span>Tahun</span>
                            <strong>{{ $drama->release_year }}</strong>
                        </div>
                        @endif

                        <div class="web-information-item">
                            <span>Status</span>
                            <strong>{{ $drama->status }}</strong>
                        </div>

                        <div class="web-information-item">
                            <span>Total Episode</span>
                            <strong>{{ $drama->total_episode }}</strong>
                        </div>

                        @if(!empty($drama->duration))
                        <div class="web-information-item">
                            <span>Durasi</span>
                            <strong>{{ $drama->duration }}</strong>
                        </div>
                        @endif

                    </div>

                </div>

            </div>

            <div class="web-drama-information-right">

                <div class="web-information-card">

                    <h3 class="web-information-title">

                        Sinopsis

                    </h3>

                    <p class="web-drama-synopsis">

                        {!! nl2br(e($drama->description ?? 'Belum ada sinopsis untuk drama ini.')) !!}

                    </p>

                </div>

            </div>

        </div>

    </div>

</section>