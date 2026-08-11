@extends('web.layouts.app')

@section('title', 'Request Drama')
@section('description', 'Minta drama yang belum ada di katalog DramaVerse ID.')

@section('content')

    <section class="page-head section-pad">
        <h1 class="page-title">Request Drama</h1>
        <p class="page-subtitle">
            Drama yang Anda cari belum ada? Kirim judulnya di sini. Kami beri tahu
            lewat Telegram begitu dramanya tersedia.
        </p>
    </section>

    <section class="section section-pad">

        @if (session('status'))
            <div class="toast toast-success" role="status" data-toast>{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="toast toast-error" role="alert" data-toast>{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('web.request.store') }}" class="request-form">
            @csrf

            <div class="request-field">
                <label for="req-title">Judul drama <span aria-hidden="true">*</span></label>
                {{-- Judul diisi apa adanya. Yang diketik pengguna bisa judul
                     Inggris, judul aslinya, atau terjemahan bebas — tidak ada
                     gunanya memaksanya cocok dengan katalog, karena kalau
                     sudah cocok ia tidak akan memintanya. --}}
                <input type="text" id="req-title" name="title" required maxlength="200"
                       value="{{ old('title', request('q')) }}"
                       placeholder="Contoh: Reply 1988" class="control">
                @error('title')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="request-field">
                <label for="req-year">Tahun tayang <span class="field-optional">opsional</span></label>
                <input type="text" id="req-year" name="year" maxlength="10"
                       value="{{ old('year') }}" placeholder="2015" class="control">
                @error('year')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="request-field">
                <label for="req-note">Catatan <span class="field-optional">opsional</span></label>
                <textarea id="req-note" name="note" rows="3" maxlength="500"
                          placeholder="Pemain utamanya, negara asal, atau apa pun yang membantu kami menemukannya."
                          class="control">{{ old('note') }}</textarea>
                @error('note')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <button type="submit" class="btn btn-primary" @disabled($terbuka >= $batas)>
                <x-web.home.icon name="send" :size="15" />
                Kirim Permintaan
            </button>

            @if ($terbuka >= $batas)
                <p class="field-error">
                    Anda punya {{ $terbuka }} permintaan yang masih diproses. Tunggu
                    sebagian selesai dulu sebelum menambah yang baru.
                </p>
            @endif
        </form>
    </section>

    <section class="section section-pad">

        <x-web.home.section-header title="Permintaan Saya"
            :count="$requests->count().' permintaan'" />

        @if ($requests->isEmpty())
            <p class="page-subtitle">Belum ada permintaan. Kirim yang pertama lewat form di atas.</p>
        @else
            <div class="request-list">
                @foreach ($requests as $r)
                    <article class="request-item">

                        <div class="request-main">
                            <h3 class="request-title">
                                {{ $r->title }}@if ($r->year) <span class="request-year">({{ $r->year }})</span>@endif
                            </h3>

                            <p class="request-status">
                                <span class="badge {{ $r->status->badge() }}">{{ $r->status->label() }}</span>
                                <span class="request-desc">{{ $r->status->keterangan() }}</span>
                            </p>

                            @if ($r->admin_note)
                                <p class="request-note">Catatan admin: {{ $r->admin_note }}</p>
                            @endif

                            <p class="request-meta">Dikirim {{ \App\Support\Waktu::relatif($r->created_at) }}</p>
                        </div>

                        <div class="request-actions">
                            {{-- Dramanya sudah ada: yang dibutuhkan sekarang
                                 tautannya, bukan keterangan status. --}}
                            @if ($r->drama)
                                <a href="{{ route('web.drama.show', $r->drama->slug) }}" class="btn btn-primary btn-sm">
                                    <x-web.home.icon name="play" :size="14" /> Tonton
                                </a>
                            @endif

                            @unless ($r->status->selesai())
                                <form method="POST" action="{{ route('web.request.destroy', $r->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm">Batalkan</button>
                                </form>
                            @endunless
                        </div>

                    </article>
                @endforeach
            </div>
        @endif
    </section>

@endsection
