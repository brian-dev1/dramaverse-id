@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid">

            <section class="form-card form-main">
                <h2>Informasi Admin</h2>

                <x-admin.field
                    name="name"
                    label="Nama"
                    :value="$record->name"
                    required
                />

                <x-admin.field
                    name="email"
                    label="Email"
                    type="email"
                    :value="$record->email"
                    required
                    hint="Email ini digunakan untuk login ke panel admin."
                />

                <x-admin.field
                    name="password"
                    label="Password"
                    type="password"
                    :required="! $record->exists"
                    :hint="$record->exists
                        ? 'Kosongkan jika password tidak ingin diubah.'
                        : 'Minimal 8 karakter.'"
                />

                <x-admin.field
                    name="password_confirmation"
                    label="Konfirmasi password"
                    type="password"
                    :required="! $record->exists"
                />
            </section>

            <section class="form-card">
                <h2>Akses</h2>

                <x-admin.field
                    name="roles"
                    label="Peran"
                    type="multiselect"
                    :value="$selectedRoles"
                    :options="$roles->pluck('name', 'id')->all()"
                    hint="Peran menentukan izin yang dimiliki akun admin."
                />

                <div class="field">
                    <label>Status akun</label>

                    <label class="checkbox-item">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            @checked(old('is_active', $record->exists ? $record->is_active : true))
                        >

                        Aktif
                    </label>

                    <p class="field-hint">
                        Admin yang dinonaktifkan tidak dapat login dan sesi aktifnya akan diputus pada request berikutnya.
                    </p>
                </div>

                @if ($record->exists)
                    <div class="field">
                        <label>Jenis akun</label>

                        <p class="field-hint">
                            {{ $record->isRoot() ? 'Root Owner' : 'Administrator' }}
                        </p>
                    </div>
                @endif
            </section>

        </div>

    </x-admin.form>

@endsection