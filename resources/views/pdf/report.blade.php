<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 12px; color: #1f2937; }

        /* Running header and footer. mPDF repeats whatever these named
           blocks contain on every page, which is the whole reason this is a
           PDF and not the browser's print: a report that runs to four pages
           needs the restaurant's name and the period on all four, and a page
           number on each so a stapled stack can be checked for completeness. */
        .run-head { border-bottom: 1px solid #9ca3af; padding-bottom: 5px; }
        .run-head .brand { font-size: 15px; font-weight: bold; }
        .run-head .title { font-size: 12px; }
        .run-head .period { font-size: 10px; color: #6b7280; }
        .run-foot { border-top: 1px solid #d1d5db; padding-top: 4px; font-size: 9.5px; color: #6b7280; }

        h2 { font-size: 14px; margin: 14px 0 6px; }
        h3 { font-size: 12px; margin: 12px 0 5px; color: #374151; }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #f3f4f6; text-align: start; padding: 5px 7px;
            font-size: 10px; text-transform: uppercase; color: #4b5563;
            border-bottom: 1px solid #d1d5db;
        }
        tbody td { padding: 5px 7px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        .text-end { text-align: end; }
        .num { text-align: end; }
        .muted { color: #6b7280; font-size: 10px; }
        .strong { font-weight: bold; }

        /* The summary strip: four figures an owner reads before anything
           else, so they sit above the detail rather than under it. */
        .cards { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin-bottom: 10px; }
        .cards td {
            width: 25%; border: 1px solid #e5e7eb; border-radius: 4px;
            padding: 7px 9px; text-align: center;
        }
        .cards .label { font-size: 9.5px; color: #6b7280; }
        .cards .value { font-size: 14px; font-weight: bold; }

        .in  { color: #047857; }
        .out { color: #b91c1c; }

        /* Each day's movements as one block that is not split across a page
           break — a half-day of cash on one sheet and the rest on another is
           how a reconciliation goes wrong. */
        .day { page-break-inside: avoid; margin-bottom: 10px; border: 1px solid #e5e7eb; }
        .day-head {
            background: #f9fafb; padding: 5px 7px;
            border-bottom: 1px solid #e5e7eb; font-size: 11px;
        }

        /* The brand font (Tajawal/Almarai) has no ₪ glyph — fall back to
           mPDF's bundled DejaVu just for the symbol, as the invoice does. */
        .currency { font-family: dejavusanscondensed, sans-serif; }
        /* The amount is a left-to-right run: symbol, then digits. Without
           this the RTL paragraph direction moves the symbol to the other
           end of the number and 162.00₪ is what comes out. */
        .money { direction: ltr; unicode-bidi: embed; display: inline-block; }

        @page {
            header: html_run-head;
            footer: html_run-foot;
            margin-top: 30mm;
            margin-bottom: 20mm;
        }
    </style>
</head>
<body>

{{-- Repeated on every page by mPDF. --}}
<htmlpageheader name="run-head">
    <div class="run-head">
        <table style="width:100%">
            <tr>
                <td style="border:0;padding:0">
                    <div class="brand">{{ $branding->name }}</div>
                    <div class="title">{{ $title }}</div>
                </td>
                <td style="border:0;padding:0;text-align:end">
                    <div class="period">{{ $labels['period'] }}: <span dir="ltr">{{ $from }} — {{ $to }}</span></div>
                    @if ($branding->phone)
                        <div class="period" dir="ltr">{{ $branding->phone }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</htmlpageheader>

<htmlpagefooter name="run-foot">
    <div class="run-foot">
        <table style="width:100%">
            <tr>
                <td style="border:0;padding:0">{{ $labels['printed_at'] }}: <span dir="ltr">{{ $printedAt }}</span></td>
                <td style="border:0;padding:0;text-align:end">{{ $labels['page'] }} {PAGENO} / {nbpg}</td>
            </tr>
        </table>
    </div>
</htmlpagefooter>

{{-- ---------------------------------------------------------------- --}}
{{-- Orders                                                            --}}
{{-- ---------------------------------------------------------------- --}}
@if ($tab === 'orders')
    @php $t = $orders['totals']; @endphp

    <table class="cards">
        <tr>
            <td><div class="label">{{ $labels['orders_count'] }}</div><div class="value">{{ $t['orders_count'] }}</div></td>
            <td><div class="label">{{ $labels['collected'] }}</div><div class="value in"><span class="currency money">{{ $money($t['collected_cents']) }}</span></div></td>
            <td><div class="label">{{ $labels['discounts'] }}</div><div class="value"><span class="currency money">{{ $money($t['discount_cents']) }}</span></div></td>
            <td><div class="label">{{ $labels['average_ticket'] }}</div><div class="value"><span class="currency money">{{ $money($t['average_cents']) }}</span></div></td>
        </tr>
    </table>

    <h2>{{ $labels['summary'] }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ $labels['date'] }}</th>
                <th class="num">{{ $labels['orders_count'] }}</th>
                <th class="num">{{ $labels['gross'] }}</th>
                <th class="num">{{ $labels['discounts'] }}</th>
                <th class="num">{{ $labels['collected'] }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders['by_day'] as $day)
                <tr>
                    <td dir="ltr">{{ $day['date'] }}</td>
                    <td class="num">{{ $day['orders_count'] }}</td>
                    <td class="num currency money">{{ $money($day['gross_cents']) }}</td>
                    <td class="num currency money">{{ $money($day['discount_cents']) }}</td>
                    <td class="num strong currency money">{{ $money($day['collected_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;padding:14px" class="muted">{{ $labels['no_movements'] }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($orders['rows'])
        <h2>{{ $labels['detailed'] }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ $labels['order_ref'] }}</th>
                    <th>{{ $labels['table'] }}</th>
                    <th>{{ $labels['paid_at'] }}</th>
                    <th class="num">{{ $labels['items'] }}</th>
                    <th class="num">{{ $labels['discounts'] }}</th>
                    <th class="num">{{ $labels['collected'] }}</th>
                    <th>{{ $labels['by'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders['rows'] as $row)
                    <tr>
                        <td dir="ltr">{{ $row['reference'] }}</td>
                        <td>{{ $row['table'] ?? '—' }}</td>
                        <td class="muted" dir="ltr">{{ $datetime($row['paid_at']) }}</td>
                        <td class="num">{{ $row['items_count'] }}</td>
                        <td class="num currency money">{{ $money($row['discount_cents']) }}</td>
                        <td class="num strong currency money">{{ $money($row['collected_cents']) }}</td>
                        <td class="muted">{{ $row['by'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Cash box: summary, then the ledger behind it                      --}}
{{-- ---------------------------------------------------------------- --}}
@if ($tab === 'cashbox')
    <h2>{{ $labels['summary'] }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ $labels['date'] }}</th>
                <th class="num">{{ $labels['opening'] }}</th>
                <th class="num">{{ $labels['total_in'] }}</th>
                <th class="num">{{ $labels['total_out'] }}</th>
                <th class="num">{{ $labels['closing_balance'] }}</th>
                <th class="num">{{ $labels['difference'] }}</th>
            </tr>
        </thead>
        <tbody>
            {{-- Days where nothing happened are skipped. A month range put
                 thirty-one rows on the page, twenty-eight of them zeros, and
                 the three days that actually had money in them were lost in
                 the middle. A closed day is always shown even if it was
                 quiet — somebody signed it, and that is worth printing. --}}
            @php
                $active = array_values(array_filter(
                    $cashbox["days"],
                    fn ($d) => $d["collected_cents"] || $d["expenses_cents"] || $d["balance_cents"] || $d["opening_cents"] || ($d["is_closed"] ?? false)
                ));
            @endphp
            @forelse ($active as $day)
                <tr>
                    <td dir="ltr">{{ $day['date'] }}</td>
                    <td class="num currency money">{{ $money($day['opening_cents']) }}</td>
                    <td class="num in currency money">{{ $money($day['collected_cents']) }}</td>
                    <td class="num out currency money">{{ $money($day['expenses_cents']) }}</td>
                    <td class="num strong currency money">{{ $money($day['balance_cents']) }}</td>
                    <td class="num currency money">{{ $day['is_closed'] ? $money($day['difference_cents']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;padding:14px" class="muted">{{ $labels['no_movements'] }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($ledger)
        <h2>{{ $labels['ledger_title'] }}</h2>
        @foreach ($ledger as $day)
            <div class="day">
                {{-- Opening and closing on the block's own header, so each
                     day reads as a statement: what was there, what happened,
                     what is left. --}}
                <div class="day-head">
                    <table style="width:100%">
                        <tr>
                            <td style="border:0;padding:0" class="strong" dir="ltr">{{ $day['date'] }}</td>
                            <td style="border:0;padding:0;text-align:end" class="muted">
                                {{ $labels['opening'] }}: <span class="currency money">{{ $money($day['opening_cents']) }}</span>
                                &nbsp;·&nbsp;
                                {{ $labels['closing_balance'] }}: <span class="strong currency money">{{ $money($day['balance_cents']) }}</span>
                            </td>
                        </tr>
                    </table>
                </div>

                <table>
                    <tbody>
                        @foreach ($day['movements'] as $row)
                            <tr>
                                <td style="width:12%" class="{{ $row['direction'] === 'in' ? 'in' : 'out' }}">
                                    {{ $row['direction'] === 'in' ? $labels['in'] : $labels['out'] }}
                                </td>
                                <td>
                                    <span dir="ltr">{{ $row['label'] ?? '—' }}</span>
                                    @if (!empty($row['description']))
                                        <span class="muted"> — {{ $row['description'] }}</span>
                                    @endif
                                </td>
                                <td style="width:20%" class="muted" dir="ltr">{{ $datetime($row['at']) }}</td>
                                <td style="width:15%" class="muted">{{ $row['by'] ?? '—' }}</td>
                                <td style="width:16%" class="num strong {{ $row['direction'] === 'in' ? 'in' : 'out' }}">
                                    <span class="currency money">{{ $row['direction'] === 'in' ? '+' : '−' }}{{ $money($row['amount_cents']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
@endif

</body>
</html>
