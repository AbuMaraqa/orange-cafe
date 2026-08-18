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
        .card { width: 100%; text-align: center; margin-top: 40px; }
        .card td { border: none; padding: 0; }
        .brand-name { font-size: 22px; font-weight: bold; padding-bottom: 4px; }
        .tagline { color: #6b7280; font-size: 12px; padding-bottom: 18px; }
        .heading { font-size: 26px; font-weight: bold; padding-bottom: 16px; }
        .qr-cell { padding-bottom: 18px; }
        .instruction { color: #6b7280; font-size: 13px; padding-bottom: 14px; }
        {{-- The SSID is printed as text as well as encoded: an older phone
             without a QR-capable camera, or a laptop, still needs to be able
             to read the network name and type it in. --}}
        .network { font-size: 15px; font-weight: bold; }
        .network-label { color: #6b7280; font-size: 11px; padding-top: 10px; }
    </style>
</head>
<body>
    <table class="card">
        <tr><td class="brand-name">{{ $branding->name }}</td></tr>
        @if ($branding->tagline)
            <tr><td class="tagline">{{ $branding->tagline }}</td></tr>
        @endif

        <tr><td class="heading">{{ $labels['wifi_heading'] }}</td></tr>
        <tr><td class="qr-cell"><img src="data:image/png;base64,{{ $qrCode }}" width="260" height="260"></td></tr>
        <tr><td class="instruction">{{ $labels['wifi_instruction'] }}</td></tr>

        <tr><td class="network-label">{{ $labels['wifi_network'] }}</td></tr>
        {{-- dir=ltr: an SSID is a technical identifier and must read
             left-to-right even on an Arabic sign, or a name like
             "Orange-Cafe-5G" comes out reordered and unusable to type. --}}
        <tr><td class="network" dir="ltr">{{ $branding->wifi_ssid }}</td></tr>
    </table>
</body>
</html>
