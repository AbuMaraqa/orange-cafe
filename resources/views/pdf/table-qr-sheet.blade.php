<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { color: #1f2937; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid > tbody > tr > td { width: 50%; padding: 20px 10px; border: 1px dashed #d1d5db; vertical-align: top; }
        {{-- mPDF's table engine stacks rows reliably where block/inline
             flow (divs next to an <img>) does not — each card is its own
             1-column table so the QR image can never overlap the text. --}}
        .card { width: 100%; text-align: center; }
        .card td { border: none; padding: 0; width: 100%; }
        .brand-name { font-size: 15px; font-weight: bold; padding-bottom: 12px; }
        .qr-cell { padding-bottom: 10px; }
        .table-label { font-size: 20px; font-weight: bold; }
        .instruction { color: #6b7280; font-size: 11px; padding-top: 2px; }
    </style>
</head>
<body>
    <table class="grid">
        @foreach ($tables->chunk(2) as $row)
            <tr>
                @foreach ($row as $table)
                    <td>
                        <table class="card">
                            <tr><td class="brand-name">{{ $branding->name }}</td></tr>
                            <tr><td class="qr-cell"><img src="data:image/png;base64,{{ $qrCodes[$table->id] }}" width="170" height="170"></td></tr>
                            <tr><td class="table-label">{{ $labels['table'] }} {{ $table->label }}</td></tr>
                            <tr><td class="instruction">{{ $labels['scan_instruction'] }}</td></tr>
                        </table>
                    </td>
                @endforeach
                @if ($row->count() < 2)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>
