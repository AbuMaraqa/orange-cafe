<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #1f2937; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td { width: 50%; text-align: center; padding: 24px 10px; border: 1px dashed #d1d5db; vertical-align: middle; }
        .brand-name { font-size: 15px; font-weight: bold; margin-bottom: 10px; }
        .table-label { font-size: 20px; font-weight: bold; margin-top: 10px; }
        .instruction { color: #6b7280; font-size: 11px; margin-top: 4px; }
    </style>
</head>
<body>
    <table class="grid">
        @foreach ($tables->chunk(2) as $row)
            <tr>
                @foreach ($row as $table)
                    <td>
                        <div class="brand-name">{{ $branding->name }}</div>
                        <img src="data:image/png;base64,{{ $qrCodes[$table->id] }}" width="170" height="170">
                        <div class="table-label">{{ $labels['table'] }} {{ $table->label }}</div>
                        <div class="instruction">{{ $labels['scan_instruction'] }}</div>
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
