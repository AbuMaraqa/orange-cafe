<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {{-- No font-family here on purpose: PdfService::make() sets the
             document's default font (brand font or mPDF's auto per-script
             selection) via SetDefaultFont()/autoLangToFont, and a CSS
             generic-family value here would silently override that. --}}
        body { color: #1f2937; }
        {{-- mPDF's table engine stacks rows reliably where block/inline
             flow (divs next to an <img>) does not — the whole card is a
             1-column table so the QR image can never overlap the text. --}}
        .card { width: 100%; text-align: center; margin-top: 50px; }
        .card td { border: none; padding: 0; }
        .brand-name { font-size: 22px; font-weight: bold; padding-bottom: 4px; }
        .tagline { color: #6b7280; font-size: 12px; padding-bottom: 20px; }
        .qr-cell { padding-bottom: 20px; }
        {{-- Two side-by-side QR cards (menu + Wi-Fi) rather than one QR
             carrying both: a phone reads a scanned code as either "open
             this link" or "join this network", never both at once. --}}
        .qr-grid { width: 100%; border-collapse: collapse; }
        .qr-grid td { border: none; text-align: center; vertical-align: top; padding: 0 6px; }
        .qr-caption { color: #6b7280; font-size: 11px; padding-top: 6px; }
        .table-label { font-size: 26px; font-weight: bold; padding-top: 20px; }
        .instruction { color: #6b7280; font-size: 13px; padding-top: 6px; }
    </style>
</head>
<body>
    <table class="card">
        <tr><td class="brand-name">{{ $branding->name }}</td></tr>
        @if ($branding->tagline)
            <tr><td class="tagline">{{ $branding->tagline }}</td></tr>
        @endif

        @if ($wifiQrCode)
            <tr>
                <td class="qr-cell">
                    <table class="qr-grid">
                        <tr>
                            <td>
                                <img src="data:image/png;base64,{{ $qrCode }}" width="190" height="190">
                                <div class="qr-caption">{{ $labels['scan_instruction'] }}</div>
                            </td>
                            <td>
                                <img src="data:image/png;base64,{{ $wifiQrCode }}" width="190" height="190">
                                <div class="qr-caption">{{ $labels['wifi_instruction'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @else
            <tr><td class="qr-cell"><img src="data:image/png;base64,{{ $qrCode }}" width="260" height="260"></td></tr>
        @endif

        <tr><td class="table-label">{{ $labels['table'] }} {{ $table->label }}</td></tr>
        @unless ($wifiQrCode)
            <tr><td class="instruction">{{ $labels['scan_instruction'] }}</td></tr>
        @endunless
    </table>
</body>
</html>
