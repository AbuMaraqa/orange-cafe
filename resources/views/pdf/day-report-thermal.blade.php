{{--
    The day's cash box, on the till printer.

    Deliberately the same visual language as pdf.invoice-thermal — same
    widths, same dashed dividers, same currency fallback — because both come
    off the same roll and a manager reading one after the other should not
    have to re-learn where to look.

    Every figure is handed in already computed — Cashbox::day() for the
    drawer, Cashbox::itemsSold() for the night's trade,
    Cashbox::ordersCollected() for the bill-by-bill list. Nothing is added up
    here: the screen, the closing record and this paper have to agree, and a
    template that does its own arithmetic is a fourth opinion.
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
        /* Two rows per bill — the headline and its reference — so a dotted
           line under the second is what tells one bill from the next on a
           list that can run to a hundred of them. */
        table.orders td.head { padding-bottom: 0; }
        table.orders td.method { text-align: center; white-space: nowrap; padding-left: 4px; padding-right: 4px; }
        table.orders td.sub { font-size: 9.5px; color: #4b5563; padding-top: 0; padding-bottom: 3px; border-bottom: 1px dotted #9ca3af; }
        table.orders td.part { font-size: 9.5px; color: #4b5563; padding-top: 0; padding-bottom: 0; }
        table.orders td.total-rule { border-top: 1px solid #111827; padding-top: 6px; }
        .flag { font-weight: bold; color: #111827; }
        /* Same reason as the invoice: the brand face has no ₪, DejaVu does. */
        .currency { font-family: dejavusanscondensed, sans-serif; }
    </style>
</head>
<body>
@php
    $r = $labels['day_report'];
    $money = fn (int $cents) => number_format($cents / 100, 2);
    // For the order list, where a line can be money handed back. The minus
    // goes before the symbol, as it does everywhere else on this paper,
    // rather than number_format's "₪-10.00".
    $signed = fn (int $cents) => ($cents < 0 ? '−' : '').'<span class="currency">'.e($currencySymbol).'</span>'.$money(abs($cents));
    $methodName = fn (string $method) => $labels['payment_methods'][$method] ?? $method;
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
@if ($day['card_collected_cents'] != 0 || $day['staff_deductions_cents'] > 0 || $day['bill_discounts_cents'] > 0)
    <div class="divider"></div>

    <table class="rows">
        <tr><td class="section" colspan="2">{{ $r['not_in_drawer'] }}</td></tr>
        {{-- Money the café earned tonight and will be paid days later. It
             belongs on this paper because the person counting the drawer is
             also the person asked how the night went, and a takings figure
             missing every card sale answers that question wrongly. It belongs
             in *this* section because it is not in the hand they are
             counting. --}}
        @if ($day['card_collected_cents'] != 0)
            <tr>
                <td>{{ $r['card_collected'] }}</td>
                <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($day['card_collected_cents']) }}</td>
            </tr>
        @endif
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

{{-- What actually went out tonight.

     Below the drawer, and deliberately so: the top of this paper is read
     standing at an open drawer with cash in hand, and that person needs the
     expected balance before anything else. The list of dishes is the part
     read afterwards, sitting down — how the night went, and on what — so it
     goes where a long list can grow without pushing the balance off the
     first thing you see.

     The figures come from Cashbox::itemsSold(). They describe trade, not the
     drawer: a card sale is in here, and the totals below are what the bills
     came to rather than what was counted. --}}
<div class="divider"></div>

<table class="rows">
    <tr><td class="section" colspan="2">{{ $r['items_sold'] }}</td></tr>

    @forelse ($items['lines'] as $line)
        <tr>
            <td>{{ $line['quantity'] }}× {{ $line['name'] }}</td>
            <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($line['total_cents']) }}</td>
        </tr>
    @empty
        <tr><td colspan="2" class="muted">{{ $r['items_none'] }}</td></tr>
    @endforelse
</table>

@if ($items['lines'] !== [])
    <div class="rule"></div>

    <table class="rows">
        <tr>
            <td>{{ $r['bills_count'] }}</td>
            <td class="amount">{{ $day['orders_count'] }}</td>
        </tr>
        <tr>
            <td>{{ $r['items_quantity'] }}</td>
            <td class="amount">{{ $items['quantity'] }}</td>
        </tr>
        <tr>
            <td>{{ $r['gross_sales'] }}</td>
            <td class="amount"><span class="currency">{{ $currencySymbol }}</span>{{ $money($items['gross_cents']) }}</td>
        </tr>
        {{-- The same number the "not in drawer" block names, in its other
             role. Up there it answers "why is the till not holding what the
             bills say"; here it is the step between what was rung up and
             what the café is owed, and a gross figure with the discount
             left out of it is the one a manager will subtract by hand. --}}
        @if ($items['discount_cents'] > 0)
            <tr>
                <td>{{ $r['sales_discount'] }}</td>
                <td class="amount">−<span class="currency">{{ $currencySymbol }}</span>{{ $money($items['discount_cents']) }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>{{ $r['net_sales'] }}</strong></td>
            <td class="amount"><strong><span class="currency">{{ $currencySymbol }}</span>{{ $money($items['net_cents']) }}</strong></td>
        </tr>
    </table>
@endif

{{-- Every bill that paid tonight, and how.

     The very end of the roll, because it is the longest thing on it — one
     entry per bill — and it is the part read last: the drawer is counted,
     the night is summed up, and this is where somebody goes looking for the
     one table they want to ask about.

     The totals underneath are the drawer's own figures, not a recount:
     Cashbox::ordersCollected() reads the same payments as day(), so "cash"
     here is "cash from orders" at the top and "card" is "card takings". --}}
<div class="divider"></div>

<table class="rows orders">
    <tr><td class="section" colspan="3">{{ $r['orders_detail'] }} ({{ count($orders['lines']) }})</td></tr>

    @forelse ($orders['lines'] as $line)
        <tr>
            <td class="head">{{ $line['at']->format('H:i') }}@if ($line['table']) · {{ $line['table'] }}@endif</td>
            <td class="head method">
                @if ($line['method'])
                    {{ $methodName($line['method']) }}
                @elseif ($line['by_method'] !== [])
                    {{ $r['split_payment'] }}
                @else
                    —
                @endif
            </td>
            <td class="head amount">{!! $signed($line['total_cents']) !!}</td>
        </tr>
        {{-- Paid more than one way: the headline says "split" and these say
             how, each under the amount column so the parts can be read
             against the whole — and against the card terminal's own slip. --}}
        @if (! $line['method'])
            @foreach ($line['by_method'] as $method => $cents)
                <tr>
                    <td class="part"></td>
                    <td class="part method">{{ $methodName($method) }}</td>
                    <td class="part amount">{!! $signed($cents) !!}</td>
                </tr>
            @endforeach
        @endif
        <tr>
            <td class="sub" colspan="3">
                #{{ $line['reference'] }}
                @foreach ($line['card_references'] as $cardReference)
                    · {{ $labels['payment_reference'] }} {{ $cardReference }}
                @endforeach
                @if ($line['is_refund'])
                    · <span class="flag">{{ $r['refund'] }}</span>
                @elseif ($line['is_part_payment'])
                    · <span class="flag">{{ $r['part_payment'] }}</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="3" class="muted">{{ $r['orders_none'] }}</td></tr>
    @endforelse

    {{-- In the same table as the list, not a second one beneath it, so the
         totals sit in the column the amounts they add up are in. --}}
    @if ($orders['lines'] !== [])
        <tr><td class="section total-rule" colspan="3">{{ $r['payment_summary'] }}</td></tr>
        @foreach ($orders['by_method'] as $method => $cents)
            <tr>
                <td colspan="2">{{ $methodName($method) }}</td>
                <td class="amount">{!! $signed($cents) !!}</td>
            </tr>
        @endforeach
        <tr>
            <td class="grand" colspan="2">{{ $r['orders_total'] }}</td>
            <td class="amount grand">{!! $signed($orders['total_cents']) !!}</td>
        </tr>
    @endif
</table>

<div class="divider"></div>

<div class="footer center">
    {{ $day['is_closed'] ? $r['day_closed'] : $r['day_open'] }}
</div>
</body>
</html>
