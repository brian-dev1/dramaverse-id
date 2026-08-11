{{--
    Kerangka bersama seluruh halaman galat.

    ## Kenapa gayanya ditulis di dalam berkas ini, bukan mengimpor CSS situs

    Halaman galat harus tetap utuh justru ketika hal lain sedang rusak.
    Memanggil @vite() di sini berarti halaman 500 ikut gagal setiap kali
    manifest aset belum dibangun, terhapus saat deploy, atau permission-nya
    salah — dan yang muncul adalah galat di dalam penanganan galat, halaman
    putih tanpa satu pun petunjuk. Warnanya disalin dari theme.css, jadi
    tampilannya sama tanpa satu pun berkas eksternal.

    Alasan yang sama membuat font situs tidak dimuat dari Google Fonts di
    sini. Kalau jaringan pengguna yang bermasalah, halaman ini justru yang
    paling dibutuhkan.

    ## Yang diisi setiap halaman turunan

      @section('kode')     — label kecil di atas judul
      @section('judul')    — judul besar
      @section('pesan')    — satu paragraf penjelasan
      @section('ilustrasi')— SVG, boleh dikosongkan (memakai gulungan film)
--}}
@php
    // Panel admin dan sisi pengunjung memakai halaman yang sama, tapi tombol
    // "kembali" -nya harus berbeda. Admin yang sesinya habis perlu kembali ke
    // form login, sedangkan pengunjung tidak punya urusan dengan panel — dan
    // menawarinya tautan login admin sama saja memberi tahu di mana pintunya.
    $diAdmin = request()->is('admin', 'admin/*');

    $tujuan = $diAdmin
        ? ['url' => route('admin.login'), 'label' => 'Masuk ke panel admin', 'ikon' => 'gembok']
        : ['url' => route('web.home'),    'label' => 'Kembali ke beranda',   'ikon' => 'rumah'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('judul', 'Terjadi kesalahan') — DramaVerse ID</title>

    <style>
        *{ box-sizing:border-box; margin:0; padding:0; }

        body{
            min-height:100vh;
            display:flex; align-items:center; justify-content:center;
            padding:32px 20px;
            background:#140A06;
            color:#FFE9D6;
            font-family:"Work Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height:1.6;
            text-align:center;
        }

        .kotak{ width:100%; max-width:520px; }

        .gambar{ display:block; margin:0 auto 26px; width:176px; height:auto; }

        .kode{
            display:inline-block;
            margin-bottom:20px; padding:5px 13px;
            border:1px solid rgba(255,178,56,.35); border-radius:999px;
            font-family:"JetBrains Mono", ui-monospace, monospace;
            font-size:11px; letter-spacing:.16em; text-transform:uppercase;
            color:#FFB238;
        }

        h1{ font-size:25px; font-weight:600; margin-bottom:11px; }

        .pesan{
            max-width:420px; margin:0 auto 28px;
            font-size:15px; color:#D9BFA9;
        }

        .tombol{ display:flex; gap:11px; justify-content:center; flex-wrap:wrap; }

        .tombol a, .tombol button{
            display:inline-flex; align-items:center; gap:8px;
            padding:12px 21px;
            border:0; border-radius:8px;
            font-family:inherit; font-size:14.5px; font-weight:500;
            text-decoration:none; cursor:pointer;
        }

        .utama{ background:#C8102E; color:#FFE9D6; }
        .utama:hover{ background:#E01535; }

        .kedua{
            background:transparent; color:#D9BFA9;
            border:1px solid rgba(255,178,56,.3);
        }
        .kedua:hover{ border-color:rgba(255,178,56,.6); color:#FFE9D6; }

        .kaki{
            margin-top:30px;
            font-family:"JetBrains Mono", ui-monospace, monospace;
            font-size:11px; letter-spacing:.1em; color:#9A7B63;
        }

        /* Nomor tiket galat: hanya dirender halaman 500. Ditampilkan agar
           pengguna punya sesuatu yang bisa disebutkan saat melapor, alih-alih
           "tadi error". */
        .tiket{
            margin-top:14px;
            font-family:"JetBrains Mono", ui-monospace, monospace;
            font-size:11.5px; color:#9A7B63;
        }
        .tiket b{ color:#D9BFA9; font-weight:500; }

        @media (max-width:420px){
            h1{ font-size:21px; }
            .gambar{ width:140px; }
            .tombol a, .tombol button{ width:100%; justify-content:center; }
        }

        @media (prefers-reduced-motion: no-preference){
            .kotak{ animation:masuk .45s ease-out; }
            @keyframes masuk{
                from{ opacity:0; transform:translateY(8px); }
                to{ opacity:1; transform:none; }
            }
        }
    </style>
</head>
<body>

    <main class="kotak">

        @hasSection('ilustrasi')
            @yield('ilustrasi')
        @else
            @include('errors.partials.gulungan')
        @endif

        <p class="kode">@yield('kode', 'Kesalahan')</p>

        <h1>@yield('judul', 'Terjadi kesalahan')</h1>

        <p class="pesan">@yield('pesan')</p>

        <div class="tombol">
            {{--
                Tombol muat ulang memakai location.reload(), bukan tautan ke
                URL saat ini. Untuk halaman 419 keduanya berbeda: yang gagal
                tadi adalah POST, dan menautkannya kembali sebagai GET akan
                membawa pengguna ke halaman yang tidak ada.

                Bila JavaScript mati, tombolnya disembunyikan lewat <noscript>
                di bawah — tombol yang tidak melakukan apa-apa lebih buruk
                daripada tombol yang tidak ada.
            --}}
            <button type="button" class="utama" onclick="location.reload()" id="muat-ulang">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>
                </svg>
                Muat ulang halaman
            </button>

            <a href="{{ $tujuan['url'] }}" class="kedua">
                @if ($tujuan['ikon'] === 'gembok')
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    </svg>
                @else
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>
                    </svg>
                @endif
                {{ $tujuan['label'] }}
            </a>
        </div>

        <noscript>
            <style>#muat-ulang{ display:none; }</style>
        </noscript>

        @yield('tambahan')

        <p class="kaki">DRAMAVERSE ID{{ $diAdmin ? ' — PANEL ADMIN' : '' }}</p>

    </main>

</body>
</html>
