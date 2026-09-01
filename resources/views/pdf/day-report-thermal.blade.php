{{--
    The day's cash box, on the till printer.

    Deliberately the same visual language as pdf.invoice-thermal — same
    widths, same dashed dividers, same currency fallback — because both come
    off the same roll and a manager reading one after the other should not
    have to re-learn where to look.

    Every figure is handed in already computed by Cashbox::day(). Nothing is
    added up here: the screen, the closing record and this paper have to
    agree, and a template that does its own arithmetic is a fourth opinion.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 11px; color: #111827; margin: 0; }
        .center { text-align: center; }
        .end { text-align: end; }
        .brand-name { font-size: 15px; font-weight: bold; }
        .report-title { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .muted { font-size: 9.5px; color: #4b5563; }
        .divider { border-top: 1px dashed #6b7280; margin: 6px 0; }
        .rule { border-top: 1px solid #111827; margin: 5px 0; }
        table.meta { width: 100%; font-size: 10px; margin-bottom: 2px; }
        table.meta td { padding: 1px 0; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { padding: 2.5px 0; font-size: 11px; vertical-align: top; }
        td.amount { text-align: end; white-space: nowrap; }
        .section { font-size: 10px; font-weight: bold; color: #4b5563; padding-top: 4px; }
        .grand { font-size: 14px; font-weight: bold; }
        .banner {
            font-size: 11px; font-weight: bold; text-align: center;
            border: 2px solid #000; padding: 4px; margin-bottom: 6px;
        }
        .footer { margin-top: 10px; font-size: 9.5px; color: #6b7280; }
        /* Same reason as the invoice: the brand face has no ₪, DejaVu does. */
        .currency { font-family: dejavusanscondensed, sans-serif; }
    </style>
</head>
<body>
@php
    $r = $labels['day_report'];
    $money = fn (int $cents) => number_format($cents / 100, 2);
@endphp

{{-- A report pulled mid-shift is legitimate and common — a manager checking
     before going home. It must not be mistaken afterwards for the signed-off
     figure, so it says so on its face rather than in a footnote. --}}
@if (! $day['is_closed'])
    <div class="banner">{{ $r['provisional'] }}</div>
@endif

<div class="center">
    @if ($logoPath)
        <img src="{{ $logoPath }}" alt="{{ $branding->name }}" style="max-width:62mm;max-height:36mm;margin-bottom:4px;">
    @endif
    <div class="brand-name">{{ $branding->name }}</div>
    <div class="report-title">{{ $r['title'] }}</div>
</div>

<div class="divider"></div>

<table class="meta">
    <tr>
        <td>{{ $r['business_date'] }}</td>
        <td class="end">{{ $day['date'] }}</td>
    </tr>
    <tr>
        <td>{{ $r['printed_at'] }}</td>
        <td class="end">{{ $printedAt }}</td>
    </tr>
    @if ($printedBy)
        <tr>
            <td>{{ $r['printed_by'] }}</td>
            <td class="end">{{ $printedBy }}</td>
        </tr>
    @endif
</table>

<div class="divider"></div>

<table class="rows">
    <tr>
        <td>{{ $r['opening'] }}</td>
        <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['opening_cents']) }}</td>
    </tr>

    <tr><td class="section" colspan="2">{{ $r['total_in'] }}</td></tr>
    <tr>
        <td>{{ $r['collected'] }} ({{ $day['orders_count'] }})</td>
        <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['collected_cents']) }}</td>
    </tr>
    <tr>
        <td>{{ $r['manual_in'] }}</td>
        <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['manual_in_cents']) }}</td>
    </tr>
    <tr>
        <td><strong>{{ $r['total_in'] }}</strong></td>
        <td class="amount"><strong><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['total_in_cents']) }}</strong></td>
    </tr>

    <tr><td class="section" colspan="2">{{ $r['total_out'] }}</td></tr>
    <tr>
        <td>{{ $r['expenses'] }} ({{ $day['expenses_count'] }})</td>
        <td class="amount">−<span class="currency">{{ $currencySymbol }}</span>{{ $money($day['expenses_cents']) }}</td>
    </tr>
    <tr>
        <td>{{ $r['manual_out'] }}</td>
        <td class="amount">−<span class="currency">{{ $currencySymbol }}</span>{{ $money($day['manual_out_cents']) }}</td>
    </tr>
    <tr>
        <td><strong>{{ $r['total_out'] }}</strong></td>
        <td class="amount"><strong>−<span class="currency">{{ $currencySymbol }}</span>{{ $money($day['total_out_cents']) }}</strong></td>
    </tr>
</table>

<div class="rule"></div>

<table class="rows">
    <tr>
        <td class="grand">{{ $r['expected'] }}</td>
        <td class="amount grand"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['balance_cents']) }}</td>
    </tr>
</table>

{{-- The counted figure and the gap exist only once somebody has signed the
     day off. Printing an empty "difference: 0.00" before that would read as
     "the till balances", which is a claim nobody has made yet. --}}
@if ($closing && $day['is_closed'])
    <div class="divider"></div>

    <table class="rows">
        <tr>
            <td>{{ $r['counted'] }}</td>
            <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($closing->counted_cents) }}</td>
        </tr>
        <tr>
            <td>
                <strong>{{ $r['difference'] }}</strong>
                @if ($closing->difference_cents > 0)
                    <span class="muted">({{ $r['over'] }})</span>
                @elseif ($closing->difference_cents < 0)
                    <span class="muted">({{ $r['short'] }})</span>
                @else
                    <span class="muted">({{ $r['balanced'] }})</span>
                @endif
            </td>
            <td class="amount"><strong><span class="currency">{{ $currencySymbol }}</span>{{ $money(abs($closing->difference_cents)) }}</strong></td>
        </tr>
    </table>

    <table class="meta" style="margin-top:4px;">
        <tr>
            <td>{{ $r['closed_by'] }}</td>
            <td class="end">{{ $closing->closedBy?->name ?? '—' }}</td>
        </tr>
        @if ($closing->notes)
            <tr>
                <td colspan="2">{{ $r['closing_notes'] }}: {{ $closing->notes }}</td>
            </tr>
        @endif
    </table>
@endif

{{-- Shown, never subtracted — the same rule the Cashbox class is built on.
     They are on the paper because a manager asks about them, and labelled
     because a figure on a cash report that is not in the balance is exactly
     the kind of thing that gets subtracted by hand. --}}
@if ($day['staff_deductions_cents'] > 0 || $day['bill_discounts_cents'] > 0)
    <div class="divider"></div>

    <table class="rows">
        <tr><td class="section" colspan="2">{{ $r['not_in_drawer'] }}</td></tr>
        @if ($day['staff_deductions_cents'] > 0)
            <tr>
                <td>{{ $r['staff_deductions'] }}</td>
                <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['staff_deductions_cents']) }}</td>
            </tr>
        @endif
        @if ($day['bill_discounts_cents'] > 0)
            <tr>
                <td>{{ $r['bill_discounts'] }}</td>
                <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['bill_discounts_cents']) }}</td>
            </tr>
        @endif
    </table>
@endif

<div class="divider"></div>

<div class="footer center">
    {{ $day['is_closed'] ? $r['day_closed'] : $r['day_open'] }}
</div>
</body>
</html>
