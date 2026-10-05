<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #111827; margin: 0; }
        .center { text-align: center; }
        .end { text-align: end; }
        .brand-name { font-size: 15px; font-weight: bold; }
        .tagline { font-size: 10px; font-style: italic; color: #4b5563; }
        .logo { max-width: 62mm; max-height: 36mm; margin-bottom: 4px; }
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
    @if (!empty($notice))
        <div style="font-size:12px;font-weight:bold;border:2px solid #000;padding:4px;margin-bottom:6px;text-align:center;">{{ $notice }}</div>
    @endif

    <div class="center">
        @if ($logoPath)
            <img class="logo" src="{{ $logoPath }}" alt="{{ $branding->name }}">
        @endif
        <div class="brand-name">{{ $branding->name }}</div>
        @if ($branding->tagline)
            <div class="tagline">{{ $branding->tagline }}</div>
        @endif
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
                    {{-- No section on the customer's bill, by request: a paying
                         customer reads a list of what they ordered, and the
                         kitchen's own filing of it is noise on a receipt they
                         are checking against their table. It moved to the
                         kitchen ticket, where the person reading it is the
                         person the section is for. --}}
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
        {{-- On a receipt the discount is the line the customer looks for.
             It only appears when there is one, above the total it produced. --}}
        @if (($order->discount_cents ?? 0) > 0)
            <tr>
                <td>{{ $labels['subtotal'] }}</td>
                <td class="end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format(($order->total_cents + $order->discount_cents) / 100, 2) }}</td>
            </tr>
            <tr>
                <td>{{ $labels['order_discount'] }}@if ($order->discount_reason) ({{ $order->discount_reason }})@endif</td>
                <td class="end">−<span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->discount_cents / 100, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="grand">{{ $labels['total'] }}</td>
            <td class="end grand"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($order->total_cents / 100, 2) }}</td>
        </tr>
        <tr>
            <td>{{ $labels['payment_status'] }}</td>
            <td class="end">{{ $order->isPaid() ? $labels['paid'] : $labels['unpaid'] }}</td>
        </tr>
        @php
            // How the money arrived, read off the payment timeline rather
            // than off the bill's own column: a table that put half on a card
            // and half in cash has two answers, and the column keeps only the
            // last one. Empty for a bill nobody has paid anything towards.
            $collectedByMethod = $order->collectedByMethod();
        @endphp
        @if ($collectedByMethod !== [])
            {{-- One line when the bill was settled one way, which is nearly
                 all of them. The amounts appear only when they are needed to
                 read the line, i.e. when the money came in more than one
                 way — a receipt that says "Cash 0.00 / Card 12.50" on every
                 card sale is noise on a 58mm roll. --}}
            @if (count($collectedByMethod) === 1)
                <tr>
                    <td>{{ $labels['payment_method'] }}</td>
                    <td class="end">{{ $labels['payment_methods'][array_key_first($collectedByMethod)] ?? array_key_first($collectedByMethod) }}</td>
                </tr>
            @else
                <tr><td colspan="2">{{ $labels['payment_method'] }}</td></tr>
                @foreach ($collectedByMethod as $method => $cents)
                    <tr>
                        <td>&nbsp;&nbsp;{{ $labels['payment_methods'][$method] ?? $method }}</td>
                        <td class="end"><span class="currency">{{ $currencySymbol }}</span>{{ number_format($cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            @endif
            {{-- The terminal's own number for the charge. On the customer's
                 copy so a disputed line on their statement can be matched to
                 this bill without the café going through a day's paperwork. --}}
            @foreach ($order->cardReferences() as $cardReference)
                <tr>
                    <td>{{ $labels['payment_reference'] }}</td>
                    <td class="end">{{ $cardReference }}</td>
                </tr>
            @endforeach
        @endif
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

    <div class="footer center">{!! nl2br(e($branding->receipt_footer_text ?: $labels['thank_you'])) !!}</div>
</body>
</html>
