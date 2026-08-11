@props([
    'routeKey',
    'record',
    'mode',
    'multipart' => false,
])

@php
    /*
    | Alamat kembali yang dititipkan daftar lewat ?kembali=. Dibawa ulang
    | sebagai input tersembunyi supaya redirect setelah simpan mendarat di
    | halaman, pencarian, dan filter yang sedang dipakai admin — bukan di
    | halaman 1 daftar. Nilainya sudah disaring: hanya alamat internal
    | /admin yang lolos.
    */
    $kembali = \App\Support\AdminReturnUrl::current();
    $batal = $kembali ?? route('admin.'.$routeKey.'.index');
@endphp

<form method="POST"
      action="{{ $mode === 'create'
          ? route('admin.'.$routeKey.'.store')
          : route('admin.'.$routeKey.'.update', $record->id) }}"
      @if ($multipart) enctype="multipart/form-data" @endif
      class="admin-form">
    @csrf
    @if ($mode === 'edit')
        @method('PUT')
    @endif

    @if ($kembali)
        <input type="hidden" name="{{ \App\Support\AdminReturnUrl::KEY }}" value="{{ $kembali }}">
    @endif

    {{ $slot }}

    <div class="form-actions">
        <a href="{{ $batal }}" class="btn btn-ghost">Batal</a>
        <button type="submit" class="btn btn-primary">
            {{ $mode === 'create' ? 'Simpan' : 'Perbarui' }}
        </button>
    </div>
</form>
