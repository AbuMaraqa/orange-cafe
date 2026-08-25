<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Every size on this ticket was scaled down by a quarter at the
           owner's request, to fit more of an order on less paper.

           Scaled, not retyped: the sizes here are a hierarchy, not a set of
           independent numbers, and each one is doing a job explained in the
           comment beside it. A cook reads the dish name first, the section
           only on a tie, and must not skip a note — so the note stays at
           the dish name's size and the prep box stays as loud as it was.
           Shrinking any one of them on its own is how a "no onions" starts
           coming back as a returned plate. If this needs to shrink again,
           scale the whole set again rather than picking at individual
           lines. */
        body { font-size: 10px; color: #000; margin: 0; text-align: center; }
        .order-ref { font-size: 15px; font-weight: bold; }
        .meta-line { font-size: 9px; margin-top: 4px; }
        .divider { border-top: 2px dashed #000; margin: 8px 0; }
        .item { padding: 5px 0; font-size: 11px; font-weight: bold; }
        .modifiers { color: #333; font-size: 9px; font-weight: normal; }
        /* Quieter than the dish name and on the same line as it: the cook
           reads the dish first and the section only when two dishes share a
           name. */
        .section { color: #555; font-size: 8.5px; font-weight: normal; }
        /* Boxed rather than grey small print: on a 72mm thermal ticket the
           cook is scanning for exactly this number to decide what to start
           first, and anything lighter than the dish name disappears under
           kitchen lighting. */
        .prep {
            display: inline-block;
            border: 1pt solid #000;
            padding: 0 3pt;
            font-size: 9px;
            font-weight: bold;
        }

        /* A note is an instruction, not a footnote. It used to print at
           12px italic under a 15px bold item, which on 80mm thermal paper
           under kitchen lighting is the first thing a cook's eye skips —
           and a missed "no onions" comes back as a returned plate.
           Boxed and bold instead, at the same weight as the dish itself. */
        .notes {
            font-size: 11px;
            font-weight: bold;
            border: 2px solid #000;
            padding: 3px 4px;
            margin: 4px 6px 0;
            text-align: start;
        }

        /* An order-level note applies to everything on the ticket, so it
           goes at the top where it is read before any cooking starts —
           inverted, because it is the one line that must not be missed. */
        .order-notes {
            font-size: 10.5px;
            font-weight: bold;
            background: #000;
            color: #fff;
            padding: 5px 4px;
            margin: 6px 0;
        }
        .footer { margin-top: 8px; font-size: 8px; color: #4b5563; }
        .fallback-notice { font-size: 9px; font-weight: bold; border: 2px solid #000; padding: 4px; margin-bottom: 6px; }
    </style>
</head>
<body>
    {{-- Printed on a stand-in printer: without this the ticket reads as a
         stray duplicate to whoever finds it at the till. --}}
    @if (!empty($notice))
        <div class="fallback-notice">{{ $notice }}</div>
    @endif

    {{-- A round added to a table the kitchen has already been sent. Said
         at the top and unmissably, because the failure it prevents is the
         cook reading a short ticket for a table they remember as a big one
         and assuming something went missing. --}}
    @if (!empty($isAddition))
        <div class="fallback-notice">{{ $labels['added_round'] ?? 'إضافة على طلب سابق' }}</div>
    @endif

    <div class="order-ref">{{ $order->reference }}</div>

    <div class="meta-line">{{ $labels['table'] }}: {{ $order->table?->label ?? $order->table_number ?? '—' }}</div>
    @if ($order->guest_count)
        <div class="meta-line">{{ $labels['guests'] }}: {{ $order->guest_count }}</div>
    @endif

    {{-- The note attached to the whole order. It was never printed at
         all: a customer asking for everything without onions, or a waiter
         flagging an allergy, wrote it down and the kitchen never saw it. --}}
    @if (filled($order->notes))
        <div class="order-notes">{{ $labels['order_note'] ?? 'ملاحظة على الطلبية' }}: {{ $order->notes }}</div>
    @endif

    <div class="divider"></div>

    @foreach ($items as $item)
        <div class="item">
            {{-- The quantity this round added, not the line's new total:
                 adding two of something already on the ticket bumps that
                 line rather than writing a second one, and printing the
                 total would have the kitchen cook the whole line again. --}}
            {{ ($addedQuantities ?? [])[$item->id] ?? $item->quantity }}× {{ $item->product_name }}
            {{-- Which section the line belongs to. The name alone is not
                 always unique, and on a ticket two dishes sharing a name
                 go to different people. Absent for anything sold
                 off-menu, which has no section to name. --}}
            @if (filled($item->menuItem?->category?->name))
                <span class="section">({{ $item->menuItem->category->name }})</span>
            @endif
            {{-- Prep time sits on the line it belongs to, not summed at the
                 bottom: the cook reads this ticket top to bottom deciding
                 what to start first, and a single total tells them nothing
                 about which dish needs the longest head start. Absent when
                 the product has no estimate, because a blank is honest and
                 a zero reads as "instant". --}}
            @if (filled($item->product?->prep_time_minutes))
                {{-- No clock emoji: the ticket is set in the brand font
                     (Tajawal/Almarai), neither of which has a glyph for
                     U+23F1, and mPDF is told not to substitute fonts — so
                     it printed as an empty box on every line. The border
                     already does the work of setting the number apart. --}}
                <span class="prep">{{ $item->product->prep_time_minutes }} {{ $labels['minutes'] ?? 'د' }}</span>
            @endif
            @if ($item->modifiers->isNotEmpty())
                <div class="modifiers">+ {{ $item->modifiers->pluck('modifier_name')->implode(', ') }}</div>
            @endif
            @if (filled($item->notes))
                <div class="notes">{{ $labels['note'] ?? 'ملاحظة' }}: {{ $item->notes }}</div>
            @endif
        </div>
    @endforeach

    <div class="divider"></div>

    <div class="footer">{{ $order->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i') }}</div>
</body>
</html>
