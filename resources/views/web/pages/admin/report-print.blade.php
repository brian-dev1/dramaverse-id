<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — {{ setting('site_name', 'DramaVerse ID') }}</title>

    <style>
        /* Gaya cetak berdiri sendiri: berkas ini dibuka di tab baru dan
           tidak memakai tema gelap panel admin. */
        *{ margin:0; padding:0; box-sizing:border-box; }

        body{
            font-family:-apple-system, "Segoe UI", Roboto, sans-serif;
            font-size:11pt; line-height:1.5;
            color:#111; background:#fff;
            padding:24px;
        }

        header{ margin-bottom:20px; border-bottom:2px solid #111; padding-bottom:12px; }
        h1{ font-size:16pt; font-weight:600; margin-bottom:4px; }
        .meta{ font-size:9.5pt; color:#555; }

        table{ width:100%; border-collapse:collapse; font-size:9pt; }
        th, td{ padding:6px 8px; border:1px solid #ccc; text-align:left; vertical-align:top; }
        th{ background:#f2f2f2; font-weight:600; }
        tbody tr:nth-child(even){ background:#fafafa; }

        .toolbar{ margin-bottom:16px; display:flex; gap:8px; }
        .toolbar button{
            padding:8px 16px; font-size:10pt; cursor:pointer;
            border:1px solid #111; background:#111; color:#fff; border-radius:4px;
        }
        .hint{ font-size:9pt; color:#666; align-self:center; }

        footer{ margin-top:20px; font-size:8.5pt; color:#666; }

        @media print{
            body{ padding:0; }
            .toolbar{ display:none; }
            thead{ display:table-header-group; }
            tr{ page-break-inside:avoid; }
        }
    </style>
</head>
<body>

    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
        <span class="hint">Pada dialog cetak, pilih tujuan &ldquo;Simpan sebagai PDF&rdquo;.</span>
    </div>

    <header>
        <h1>{{ $title }}</h1>
        <p class="meta">
            {{ setting('site_name', 'DramaVerse ID') }} &nbsp;|&nbsp;
            Periode {{ $from->translatedFormat('d F Y') }} &ndash; {{ $to->translatedFormat('d F Y') }} &nbsp;|&nbsp;
            {{ number_format($rows->count()) }} baris
        </p>
    </header>

    @if ($rows->isEmpty())
        <p>Tidak ada data pada periode ini.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $value)
                            <td>
                                @if ($value instanceof \Illuminate\Support\Carbon)
                                    {{ $value->format('d/m/Y H:i') }}
                                @elseif (is_bool($value))
                                    {{ $value ? 'Ya' : 'Tidak' }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <footer>Dicetak {{ now()->translatedFormat('d F Y, H:i') }} WIB</footer>

</body>
</html>
