@extends('web.layouts.app')

@section('title', 'Riwayat Menonton')

@section('content')

<div class="container py-5">

    <h2 class="mb-4">
        Riwayat Menonton
    </h2>

    @forelse($histories as $history)

        <div class="card mb-3">
            <div class="card-body">

                <h5>
                    {{ $history->drama->title }}
                </h5>

                <p class="mb-1">
                    Episode {{ $history->episode->episode_number }}
                </p>

                <small>
                    Progress {{ $history->progress }}%
                </small>

            </div>
        </div>

    @empty

        <p>Belum ada riwayat menonton.</p>

    @endforelse

    {{ $histories->links() }}

</div>

@endsection