@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <form method="GET" class="admin-toolbar">
        <input type="search" name="q" value="{{ $keyword }}"
               placeholder="Cari {{ strtolower($title) }}..." class="search-input">
        <button type="submit" class="btn btn-primary">Cari</button>
    </form>

    <table class="data-table">
        <thead>
            <tr>
                @foreach (array_keys($columns) as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    @foreach ($columns as $path)
                        <td>
                            @php
                                $value = data_get($record, $path);

                                if ($value instanceof \Illuminate\Support\Carbon) {
                                    $value = $value->format('d M Y');
                                } elseif (is_bool($value)) {
                                    $value = $value ? 'Ya' : 'Tidak';
                                } elseif ($value === null || $value === '') {
                                    $value = '—';
                                }
                            @endphp
                            {{ $value }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}">Belum ada data.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $records->links() }}</div>

@endsection
