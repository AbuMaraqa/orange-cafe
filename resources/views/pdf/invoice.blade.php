<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 13px; color: #1f2937; }
        .header { width: 100%; margin-bottom: 16px; }
        .header td { vertical-align: top; }
        .brand-name { font-size: 20px; font-weight: bold; }
        .tagline { font-size: 12px; font-style: italic; color: #6b7280; }
        .logo { max-width: 62mm; max-height: 36mm; margin-bottom: 6px; }
        .muted { color: #6b7280; font-size: 11px; }
        .meta { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .meta td { padding: 3px 0; font-size: 12px; }
        .meta .label { color: #6b7280; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th { background: #f3f4f6; text-align: start; padding: 6px 8px; font-size: 11px; text-transform: uppercase; color: #6b7280; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-size: 12px; vertical-align: top; }
        .modifiers { color: #6b7280; font-size: 10.5px; }
        .text-end { text-align: end; }
        .totals { width: 60%; margin-top: 10px; border-collapse: collapse; }
        .totals td { padding: 4px 8px; font-size: 12px; }
        .totals .grand { font-size: 15px; font-weight: bold; border-top: 1px solid #1f2937; padding-top: 8px; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; }
        .footer { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 11px; }
        /* The brand font (Tajawal/Almarai) doesn't include every currency
           glyph (e.g. ₪) — fall back to mPDF's bundled DejaVu Sans, which
           has broad Unicode coverage, just for that one character. */
        .currency { font-family: dejavusanscondensed, sans-serif; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 60%;">
                @if ($logoPath)
                    <img class="logo" src="{{ $logoPath }}" alt="{{ $branding->name }}">
                @endif
                <div class="brand-name">{{ $branding->name }}</div>
                @if ($branding->tagline)
                    <div class="tagline">{{ $branding->tagline }}</div>
                @endif
                <div class="muted">{{ $branding->address }}</div>
                @if ($branding->phone)
                    {{-- U+200E (LRM) anchors the run as strongly LTR so the
                         bidi algorithm doesn't reverse the digit/dash
                         sequence inside an RTL document — a dir="ltr"
                         attribute alone isn't enough for mPDF here. --}}
                    <div class="muted">&#x200E;{{ $branding->phone }}</div>
                @endif
            </td>
            <td style="width: 40%;" class="text-end">
                <div class="muted">{{ $labels['invoice'] }}</div>
                <div style="font-size: 16px; font-weight: bold;">{{ $order->reference }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">{{ $labels['date'] }}</td>
            <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
            <td class="label">{{ $labels['table'] }}</td>
            <td>{{ $order->table?->label ?? $order->table_number ?? '—' }}</td>
        </tr>
        @if ($order->customer_name)
            <tr>
                <td class="label">{{ $labels['customer'] }}</td>
                <td colspan="3">{{ $order->customer_name }}</td>
            </tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>{{ $labels['item'] }}</th>
                <th class="text-end">{{ $labels['qty'] }}</th>
                <th class="text-end">{{ $labels['unit_price'] }}</th>
                <th class="text-end">{{ $labels['subtotal'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>
                        {{ $item->product_name }}
                        @if ($item->modifiers->isNotEmpty())
                            <div class="modifiers">{{ $item->modifiers->pluck('modifier_name')->implode(', ') }}</div>
                        @endif
                        @if ($item->notes)
                            <div class="modifiers">{{ $item->notes }}</div>
                        @endif
                    </td>
                    <td class="text-end">{{ $item->quantity }}</td>
                    <td class="text-end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($item->unit_price_cents / 100, 2) }}</td>
                    <td class="text-end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($item->subtotal_cents / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- mPDF auto-mirrors physical left/right margins when the document
         direction is RTL, so a plain margin-left works for both directions. --}}
    <table class="totals" style="margin-left: auto;">
        {{-- A discount only earns its two lines when there is one. Showing
             the pre-discount figure is the point: the customer sees what
             they were given, and the lines above still add up to it. --}}
        @if (($order->discount_cents ?? 0) > 0)
            <tr>
                <td class="label">{{ $labels['subtotal'] }}</td>
                <td class="text-end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format(($order->total_cents + $order->discount_cents) / 100, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ $labels['order_discount'] }}@if ($order->discount_reason) <span style="font-size: 10px; color: #6b7280;">({{ $order->discount_reason }})</span>@endif</td>
                <td class="text-end">−<span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->discount_cents / 100, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">{{ $labels['total'] }}</td>
            <td class="text-end grand"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->total_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td class="label">{{ $labels['payment_status'] }}</td>
            <td class="text-end">
                <span class="status-badge" style="background: {{ $order->isPaid() ? '#d1fae5' : '#fef3c7' }}; color: {{ $order->isPaid() ? '#065f46' : '#92400e' }};">
                    {{ $order->isPaid() ? $labels['paid'] : $labels['unpaid'] }}
                </span>
            </td>
        </tr>
        @php
            // See invoice-thermal: read off the timeline, because a bill
            // split between cash and a card has two answers and the column
            // on the order keeps only the last.
            $collectedByMethod = $order->collectedByMethod();
        @endphp
        @if ($collectedByMethod !== [])
            <tr>
                <td class="label">{{ $labels['payment_method'] }}</td>
                <td class="text-end">
                    @if (count($collectedByMethod) === 1)
                        {{ $labels['payment_methods'][array_key_first($collectedByMethod)] ?? array_key_first($collectedByMethod) }}
                    @else
                        {{-- A4 has the width for the split on one line, which
                             the receipt roll does not. --}}
                        @foreach ($collectedByMethod as $method => $cents)
                            {{ $labels['payment_methods'][$method] ?? $method }}
                            <span class="currency">{{ $currencySymbol }}</span>{{ number_format($cents / 100, 2) }}@if (! $loop->last) &nbsp;·&nbsp; @endif
                        @endforeach
                    @endif
                </td>
            </tr>
            @foreach ($order->cardReferences() as $cardReference)
                <tr>
                    <td class="label">{{ $labels['payment_reference'] }}</td>
                    <td class="text-end">{{ $cardReference }}</td>
                </tr>
            @endforeach
        @endif
        @if ($order->isPaid() && $order->amount_paid_cents !== null && $order->amount_paid_cents !== $order->total_cents)
            <tr>
                <td class="label">{{ $labels['amount_paid'] }}</td>
                <td class="text-end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->amount_paid_cents / 100, 2) }}</td>
            </tr>
            <tr>
                <td class="label">{{ $order->amount_paid_cents > $order->total_cents ? $labels['change_due'] : $labels['discount'] }}</td>
                <td class="text-end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format(abs($order->amount_paid_cents - $order->total_cents) / 100, 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="footer">{{ $labels['thank_you'] }}</div>
</body>
</html>
