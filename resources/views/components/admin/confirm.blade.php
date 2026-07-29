@props([
    'action',
    'title'   => 'Hapus data ini?',
    'message' => 'Tindakan ini tidak dapat dibatalkan.',
    'label'   => 'Hapus',
    'method'  => 'DELETE',
])

{{-- Form penghapusan dengan konfirmasi. Dialog ditangani JS admin.js. --}}
<form method="POST" action="{{ $action }}" class="inline-form"
      data-confirm
      data-confirm-title="{{ $title }}"
      data-confirm-message="{{ $message }}">
    @csrf
    @method($method)
    <button type="submit" class="btn-icon btn-danger" title="{{ $label }}" aria-label="{{ $label }}">
        <x-web.home.icon name="trash" :size="15" />
    </button>
</form>
