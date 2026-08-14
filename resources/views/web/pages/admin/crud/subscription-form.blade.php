@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode">

        <div class="form-grid form-grid-narrow">

            <section class="form-card form-main">
                <h2>Langganan</h2>

                <x-admin.field name="user_id" label="Pengguna" type="select"
                               :value="$record->user_id" required
                               :options="$users->mapWithKeys(fn ($u) => [
                                   $u->id => $u->name.($u->telegram_username ? ' (@'.$u->telegram_username.')' : ''),
                               ])->all()" />

                <x-admin.field name="membership_plan_id" label="Paket" type="select"
                               :value="$record->membership_plan_id" required
                               {{-- Harga dan durasi lewat accessor: paket kini
                                    bisa berdenominasi Ringgit atau Dolar, dan
                                    bisa berdurasi "Selamanya". Merangkainya di
                                    sini dengan "Rp" dan "hari" mentah membuat
                                    dropdown ini menyebut angka yang salah. --}}
                               :options="$plans->mapWithKeys(fn ($p) => [
                                   $p->id => $p->name.' — '.$p->harga_tampil.' / '.$p->durasi_tampil,
                               ])->all()" />

                <x-admin.field name="price" label="Harga dibayar (Rupiah)" type="number" step="1000"
                               :value="$record->price" min="0" required
                               hint="Isi sesuai nominal yang benar-benar diterima." />

                <x-admin.field name="payment_reference" label="Referensi pembayaran"
                               :value="$record->payment_reference"
                               hint="Nomor transaksi atau catatan verifikasi." />
            </section>

            <section class="form-card">
                <h2>Masa berlaku</h2>

                <x-admin.field name="status" label="Status" type="select"
                               :value="$record->status ?? 'pending'" :options="$statuses" required />

                <x-admin.field name="started_at" label="Mulai" type="datetime-local"
                               :value="$record->started_at?->format('Y-m-d\TH:i')"
                               hint="Kosongkan bila status Aktif — akan diisi waktu sekarang." />

                <x-admin.field name="expired_at" label="Berakhir" type="datetime-local"
                               :value="$record->expired_at?->format('Y-m-d\TH:i')"
                               hint="Kosongkan bila status Aktif — dihitung dari durasi paket." />
            </section>

        </div>

    </x-admin.form>

@endsection
