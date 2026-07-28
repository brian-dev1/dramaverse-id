<section class="drama-section">

    <div class="section-title">

        <h2>

            Tentang Drama

        </h2>

    </div>

    <div class="drama-description">

        @if($drama->description)

            {!! nl2br(e($drama->description)) !!}

        @else

            <p>

                Belum ada sinopsis untuk drama ini.

            </p>

        @endif

    </div>

    <div class="drama-information">

        <div class="info-card">

            <span>Negara</span>

            <strong>

                {{ optional($drama->country)->name ?? '-' }}

            </strong>

        </div>

        <div class="info-card">

            <span>Genre</span>

            <strong>

                {{ optional($drama->genre)->name ?? '-' }}

            </strong>

        </div>

        <div class="info-card">

            <span>Tahun</span>

            <strong>

                {{ $drama->release_year ?? '-' }}

            </strong>

        </div>

        <div class="info-card">

            <span>Status</span>

            <strong>

                {{ $drama->status }}

            </strong>

        </div>

        <div class="info-card">

            <span>Total Episode</span>

            <strong>

                {{ $drama->total_episode }}

            </strong>

        </div>

    </div>

</section>