{{--
    403 — akses ditolak.

    Halaman inilah yang dilihat admin biasa ketika mengetik langsung URL menu
    yang bergembok di sidebar. Karena itu pesannya diambil dari exception bila
    ada: `EnsureHasPermission` sudah menyusun kalimat yang menyebut sebabnya
    ("halaman ini berisi data keuangan..."), dan menimpanya dengan kalimat
    umum di sini akan membuang penjelasan yang justru paling berguna.

    Ada satu syarat yang mudah terlewat: pesan itu hanya boleh ditampilkan
    karena kita sendiri yang menulisnya lewat abort(403, '...'). Untuk 403 yang
    datang dari tempat lain, getMessage() bisa berisi teks teknis, jadi
    dipakai kalimat cadangan.
--}}
@extends('errors.layout')

@section('kode', 'Akses ditolak')
@section('judul', 'Bagian ini bukan untuk akun Anda')

@section('pesan')
    @php
        // `$exception` biasanya dikirim Laravel ke view galat, tapi tidak
        // dijamin ada di setiap jalur render — mis. saat view ini dipanggil
        // langsung untuk pratinjau. `?? null` mencegah halaman galat itu
        // sendiri melempar galat.
        $pesan = trim((($exception ?? null)?->getMessage()) ?? '');

        // Laravel mengisi pesan bawaan berbahasa Inggris ("This action is
        // unauthorized.", "Forbidden") bila abort() dipanggil tanpa teks.
        // Keduanya tidak layak ditampilkan ke pengguna Indonesia.
        $milikKita = $pesan !== ''
            && str_contains($pesan, 'Anda');
    @endphp

    {{ $milikKita
        ? $pesan
        : 'Anda tidak memiliki izin untuk membuka halaman ini. Hubungi Super Admin bila Anda memerlukan aksesnya.' }}
@endsection

@section('ilustrasi')
    {{-- Gembok bermotif pita film: dua sisi gembok diberi lubang perforasi
         seperti pinggiran film. --}}
    <svg class="gambar" viewBox="0 0 200 176" role="img"
         aria-label="Gembok tertutup bermotif pita film">
        <path d="M66 78V56a34 34 0 0 1 68 0v22" fill="none" stroke="#FFB238"
              stroke-width="2.5" stroke-linecap="round"/>
        <rect x="46" y="78" width="108" height="76" rx="10" fill="#1F120A"
              stroke="#FFB238" stroke-width="2.5"/>
        <g stroke="rgba(255,178,56,.4)" stroke-width="2" stroke-linecap="round">
            <path d="M58 90v8M58 108v8M58 126v8M142 90v8M142 108v8M142 126v8"/>
        </g>
        <circle cx="100" cy="108" r="12" fill="none" stroke="#FFCE73" stroke-width="2.5"/>
        <path d="M100 120v14" stroke="#C8102E" stroke-width="3.5" stroke-linecap="round"/>
    </svg>
@endsection
