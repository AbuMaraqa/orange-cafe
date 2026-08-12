<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #111827; margin: 0; }
        .center { text-align: center; }
        .end { text-align: end; }
        .brand-name { font-size: 15px; font-weight: bold; }
        .muted { font-size: 9.5px; color: #4b5563; }
        .divider { border-top: 1px dashed #6b7280; margin: 6px 0; }
        table.meta { width: 100%; font-size: 10px; margin-bottom: 2px; }
        table.meta td { padding: 1px 0; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items td { padding: 3px 0; font-size: 10.5px; vertical-align: top; }
        .modifiers { color: #6b7280; font-size: 9px; }
        table.totals { width: 100%; margin-top: 4px; }
        table.totals td { padding: 2px 0; font-size: 11px; }
        .grand { font-size: 14px; font-weight: bold; }
        .footer { margin-top: 10px; font-size: 9.5px; color: #6b7280; }
        /* The brand font (Tajawal/Almarai) doesn't include every currency
           glyph (e.g. ₪) — fall back to mPDF's bundled DejaVu Sans, which
           has broad Unicode coverage, just for that one character. */
        .currency { font-family: dejavusanscondensed, sans-serif; }
    </style>
</head>
<body>
    <div class="center">
        <div class="brand-name">{{ $branding->name }}</div>
        <div class="muted">{{ $branding->address }}</div>
        @if ($branding->phone)
            {{-- U+200E anchors the digit/dash run as strongly LTR — see the
                 A-series invoice template for why dir="ltr" alone isn't enough. --}}
            <div class="muted">&#x200E;{{ $branding->phone }}</div>
        @endif
    </div>

    <div class="divider"></div>

    <table class="meta">
        <tr>
            <td>{{ $labels['invoice'] }}</td>
            <td class="end">{{ $order->reference }}</td>
        </tr>
        <tr>
            <td>{{ $labels['date'] }}</td>
            <td class="end">{{ $order->created_at->format('Y-m-d H:i') }}</td>
        </tr>
        <tr>
            <td>{{ $labels['table'] }}</td>
            <td class="end">{{ $order->table?->label ?? $order->table_number ?? '—' }}</td>
        </tr>
        @if ($order->customer_name)
            <tr>
                <td>{{ $labels['customer'] }}</td>
                <td class="end">{{ $order->customer_name }}</td>
            </tr>
        @endif
    </table>

    <div class="divider"></div>

    <table class="items">
        @foreach ($order->items as $item)
            <tr>
                <td>
                    {{ $item->quantity }}× {{ $item->product_name }}
                    @if ($item->modifiers->isNotEmpty())
                        <div class="modifiers">{{ $item->modifiers->pluck('modifier_name')->implode(', ') }}</div>
                    @endif
                    @if ($item->notes)
                        <div class="modifiers">{{ $item->notes }}</div>
                    @endif
                </td>
                <td class="end" style="white-space: nowrap;"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($item->subtotal_cents / 100, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="divider"></div>

    <table class="totals">
        <tr>
            <td class="grand">{{ $labels['total'] }}</td>
            <td class="end grand"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->total_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td>{{ $labels['payment_status'] }}</td>
            <td class="end">{{ $order->isPaid() ? $labels['paid'] : $labels['unpaid'] }}</td>
        </tr>
        @if ($order->isPaid() && $order->amount_paid_cents !== null && $order->amount_paid_cents !== $order->total_cents)
            <tr>
                <td>{{ $labels['amount_paid'] }}</td>
                <td class="end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->amount_paid_cents / 100, 2) }}</td>
            </tr>
            <tr>
                <td>{{ $order->amount_paid_cents > $order->total_cents ? $labels['change_due'] : $labels['discount'] }}</td>
                <td class="end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format(abs($order->amount_paid_cents - $order->total_cents) / 100, 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="divider"></div>

    <div class="footer center">{{ $labels['thank_you'] }}</div>
</body>
</html>
