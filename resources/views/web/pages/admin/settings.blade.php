@extends('web.layouts.admin')

@section('title', 'Pengaturan')

@section('content')

    <form method="POST" action="{{ route('admin.settings.update') }}"
          enctype="multipart/form-data" class="admin-form">
        @csrf
        @method('PUT')

        <div class="form-grid">
            @foreach ($groups as $key => $groupLabel)
                @continue(empty($schema[$key]))

                <section class="form-card">
                    <h2>{{ $groupLabel }}</h2>

                    @foreach ($schema[$key] as $field)
                        @if ($field['type'] === 'image')
                            <x-admin.image-field
                                :name="$field['key']"
                                :label="$field['label']"
                                :current="$values[$field['key']] ?? null"
                                :hint="$field['hint'] ?? 'JPG, PNG, atau WebP. Maksimal 4 MB.'" />
                        @else
                            <x-admin.field
                                :name="$field['key']"
                                :label="$field['label']"
                                :type="$field['type'] === 'boolean' ? 'checkbox' : $field['type']"
                                :value="$values[$field['key']] ?? null"
                                :hint="$field['hint']" />
                        @endif
                    @endforeach
                </section>
            @endforeach
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
        </div>
    </form>

@endsection
