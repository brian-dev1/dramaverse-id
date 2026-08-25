@extends('web.layouts.admin')

@section('title', $title)

@section('content')

    <x-admin.form :route-key="$routeKey" :record="$record" :mode="$mode" multipart>

        <div class="form-grid">

            <section class="form-card form-main">
                <h2>Informasi part</h2>

                <x-admin.field name="drama_id" label="Drama" type="select"
                               :value="$record->drama_id"
                               :options="$dramas->pluck('title', 'id')->all()"
                               required
                               data-next-numbers="{{ json_encode($nextNumbers) }}" />

                <x-admin.field name="episode_number" label="Nomor part" type="number"
                               :value="$record->episode_number" min="1" required
                               data-auto-number
                               hint="Terisi otomatis saat drama dipilih." />

                <x-admin.field name="title" label="Judul part" :value="$record->title"
                               hint="Kosongkan untuk memakai 'Part N'." />

                <x-admin.field name="description" label="Deskripsi" type="textarea"
                               :value="$record->description" />
            </section>

            <section class="form-card">
                <h2>Sumber video</h2>

                <x-admin.field name="video_url" label="URL video (MP4)" type="url" :value="$record->video_url"
                               hint="Dipakai pemutar bawaan." />

                <x-admin.field name="embed_url" label="URL embed" type="url" :value="$record->embed_url"
                               hint="Bila diisi, akan diprioritaskan daripada URL video." />

                <x-admin.image-field name="thumbnail_file" label="Thumbnail" :current="$record->thumbnail" />
            </section>

            <section class="form-card">
                <h2>Akses dan jadwal</h2>

                {{--
                    Akses tidak lagi bisa dipilih di sini: aturannya
                    ditegakkan EpisodeObserver berdasarkan nomor part.
                    Menampilkan kotak centang yang nilainya akan ditimpa
                    saat disimpan hanya akan menyesatkan.
                --}}
                <div class="field">
                    <span class="field-label">Akses</span>
                    <p class="field-static">
                        {{ ($record->episode_number ?? 1) > 1 ? 'Khusus VIP' : 'Gratis' }}
                    </p>
                    <span class="field-hint">
                        Mengikuti nomor part secara otomatis: part 1 gratis,
                        part 2 dan seterusnya VIP.
                    </span>
                </div>

                <x-admin.field name="status" label="Status" type="select"
                               :value="$record->status ?? 'draft'" :options="$statuses" required />

                <x-admin.field name="air_date" label="Tanggal tayang" type="date"
                               :value="$record->air_date?->format('Y-m-d')" />

                <x-admin.field name="published_at" label="Terbit pada" type="datetime-local"
                               :value="$record->published_at?->format('Y-m-d\TH:i')"
                               hint="Kosongkan bila status Terbit — akan diisi waktu sekarang." />

                <x-admin.field name="expired_at" label="Kedaluwarsa pada" type="datetime-local"
                               :value="$record->expired_at?->format('Y-m-d\TH:i')" />
            </section>

        </div>

    </x-admin.form>

@endsection
