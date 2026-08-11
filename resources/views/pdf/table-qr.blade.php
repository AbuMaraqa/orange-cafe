<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; text-align: center; color: #1f2937; }
        .card { margin-top: 60px; }
        .brand-name { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
        .tagline { color: #6b7280; font-size: 12px; margin-bottom: 24px; }
        .qr { margin: 0 auto; }
        .table-label { font-size: 28px; font-weight: bold; margin-top: 20px; }
        .instruction { color: #6b7280; font-size: 13px; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand-name">{{ $branding->name }}</div>
        @if ($branding->tagline)
            <div class="tagline">{{ $branding->tagline }}</div>
        @endif

        <img class="qr" src="data:image/png;base64,{{ $qrCode }}" width="260" height="260">

        <div class="table-label">{{ $labels['table'] }} {{ $table->label }}</div>
        <div class="instruction">{{ $labels['scan_instruction'] }}</div>
    </div>
</body>
</html>
