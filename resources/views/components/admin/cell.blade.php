@props(['record', 'path'])

@php
    $value = data_get($record, $path);
    $isImage = in_array($path, ['poster', 'cover', 'image', 'thumbnail'], true);

    // Enum tidak bisa dicetak langsung: `(string) $enum` melempar Error, dan
    // begitu juga `{{ $enum }}` di Blade. StorageProvider adalah model pertama
    // yang cast kolom tampilannya ke enum (driver, status), jadi tanpa cabang
    // ini halaman daftarnya mati total — bukan cuma tampil aneh.
    //
    // Nilai mentah enum disimpan terpisah karena yang menentukan warna badge
    // adalah nilainya, bukan labelnya yang sudah diterjemahkan.
    $enumValue = null;

    if ($value instanceof \BackedEnum) {
        $enumValue = (string) $value->value;

        $value = method_exists($value, 'label')
            ? $value->label()
            : $enumValue;
    }

    // Pewarnaan status hanya berlaku untuk nilai ENUM, dan itu disengaja.
    //
    // Modul Langganan punya kolom status berisi string 'active' juga. Kalau
    // pencocokan di bawah memakai nilai string, halaman Langganan ikut berubah
    // warnanya — perubahan yang tidak diminta dan tidak diuji di sprint ini.
    // Dengan bertumpu pada $enumValue (null untuk string), seluruh modul lama
    // dirender sama persis seperti sebelumnya.
    $statusClass = match ($enumValue) {
        'active'   => 'badge-on',
        'inactive' => 'badge-off',
        default    => 'badge-status',
    };
@endphp

@if ($isImage)
    @if ($value)
        {{-- `decoding="async"` melepaskan penguraian gambar dari utas utama.
             Tanpa itu, poster yang baru masuk layar saat digulir diurai di
             utas yang sama dengan yang menggerakkan gulirannya — dan pada
             daftar berisi 20 poster, itu terasa sebagai tersendat. --}}
        <img src="{{ asset('storage/'.$value) }}" alt="" class="cell-thumb"
             loading="lazy" decoding="async" width="40" height="57">
    @else
        <span class="cell-thumb cell-thumb-empty"><x-web.home.icon name="image" :size="14" /></span>
    @endif

@elseif (is_bool($value))
    <span class="badge {{ $value ? 'badge-on' : 'badge-off' }}">{{ $value ? 'Ya' : 'Tidak' }}</span>

@elseif ($value instanceof \Illuminate\Support\Carbon)
    <time datetime="{{ $value->toDateString() }}" title="{{ \App\Support\Waktu::presisi($value) }}">{{ \App\Support\Waktu::ringkas($value) }}</time>

@elseif ($path === 'status')
    <span class="badge {{ $statusClass }}">{{ ucfirst((string) $value) }}</span>

@elseif ($value === null || $value === '')
    <span class="cell-empty">—</span>

@else
    {{ $value }}
@endif
