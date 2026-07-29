@props([
    'routeKey',
    'record',
    'mode',
    'multipart' => false,
])

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

    {{ $slot }}

    <div class="form-actions">
        <a href="{{ route('admin.'.$routeKey.'.index') }}" class="btn btn-ghost">Batal</a>
        <button type="submit" class="btn btn-primary">
            {{ $mode === 'create' ? 'Simpan' : 'Perbarui' }}
        </button>
    </div>
</form>
