<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 13px; color: #000; margin: 0; text-align: center; }
        .order-ref { font-size: 20px; font-weight: bold; }
        .meta-line { font-size: 12px; margin-top: 4px; }
        .divider { border-top: 2px dashed #000; margin: 8px 0; }
        .item { padding: 5px 0; font-size: 15px; font-weight: bold; }
        .modifiers { color: #333; font-size: 12px; font-weight: normal; }
        .notes { font-size: 12px; font-weight: normal; font-style: italic; }
        .footer { margin-top: 8px; font-size: 10.5px; color: #4b5563; }
        .fallback-notice { font-size: 12px; font-weight: bold; border: 2px solid #000; padding: 4px; margin-bottom: 6px; }
    </style>
</head>
<body>
    {{-- Printed on a stand-in printer: without this the ticket reads as a
         stray duplicate to whoever finds it at the till. --}}
    @if (!empty($notice))
        <div class="fallback-notice">{{ $notice }}</div>
    @endif

    <div class="order-ref">{{ $order->reference }}</div>

    <div class="meta-line">{{ $labels['table'] }}: {{ $order->table?->label ?? $order->table_number ?? '—' }}</div>
    @if ($order->guest_count)
        <div class="meta-line">{{ $labels['guests'] }}: {{ $order->guest_count }}</div>
    @endif

    <div class="divider"></div>

    @foreach ($items as $item)
        <div class="item">
            {{ $item->quantity }}× {{ $item->product_name }}
            @if ($item->modifiers->isNotEmpty())
                <div class="modifiers">+ {{ $item->modifiers->pluck('modifier_name')->implode(', ') }}</div>
            @endif
            @if ($item->notes)
                <div class="notes">{{ $item->notes }}</div>
            @endif
        </div>
    @endforeach

    <div class="divider"></div>

    <div class="footer">{{ $order->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</div>
</body>
</html>
