@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid">

            <section class="form-card form-main">
                <h2>Peran</h2>

                <x-admin.field name="name" label="Nama peran" :value="$record->name" required />

                <x-admin.field name="slug" label="Slug" :value="$record->slug"
                               hint="Penanda tetap. Kosongkan untuk dibuat otomatis." />

                <x-admin.field name="description" label="Keterangan" type="textarea" :rows="3"
                               :value="$record->description" />

                <x-admin.field name="users" label="Admin dengan peran ini" type="multiselect"
                               :value="$selectedUsers"
                               :options="$admins->pluck('name', 'id')->all()"
                               hint="Hanya akun bertanda admin yang bisa diberi peran." />
            </section>

            <section class="form-card">
                <h2>Izin</h2>

                @if ($record->exists && $record->isSuperAdmin())
                    <p class="field-hint">
                        Super Admin selalu memegang seluruh izin. Daftar di bawah tidak dapat diubah.
                    </p>
                @endif

                @foreach ($permissionGroups as $module => $permissions)
                    <div class="field">
                        <label>{{ ucfirst($module) }}</label>

                        <div class="checkbox-grid">
                            @foreach ($permissions as $permission)
                                <label class="checkbox-item">
                                    <input type="checkbox" name="permissions[]"
                                           value="{{ $permission->id }}"
                                           @checked(in_array($permission->id, old('permissions', $selectedPermissions), false))
                                           @disabled($record->exists && $record->isSuperAdmin())>
                                    {{ $permission->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>

        </div>

    </x-admin.form>

@endsection
