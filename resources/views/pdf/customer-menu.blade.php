{{--
    The printable customer menu.

    Typographic, not photographic. Every dish carrying its picture would run
    a modest café's menu to twenty megabytes and a dozen pages, and this file
    exists to be sent to someone over WhatsApp — the version that arrives is
    worth more than the version that looks best on screen. The logo is the
    one image here, and it is the one that has to be there.

    Laid out in tables throughout. mPDF's block flow does not float or wrap
    the way a browser's does, and a price positioned by anything other than a
    table cell drifts the moment a dish name runs to two lines.
--}}
@php
    $primary = $branding->primary_color ?: '#c2410c';
    $secondary = $branding->secondary_color ?: '#1f2937';

    $contact = collect([
        $branding->phone,
        $branding->whatsapp && $branding->whatsapp !== $branding->phone ? $branding->whatsapp : null,
    ])->filter()->implode(' · ');

    $social = collect([
        $branding->instagram ? '@'.ltrim($branding->instagram, '@') : null,
        $branding->facebook,
    ])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {{-- IBM Plex Sans Arabic, on this document only.

             PdfService picks the restaurant's brand face for everything it
             renders, and that stays true of every receipt, ticket and report;
             naming a font here overrides it for this stylesheet and nothing
             else. The face is registered in PdfService's fontdata — mPDF
             cannot resolve a family it has never been told about, whatever
             the CSS says.

             It also removes the missing-currency problem from this page
             outright, rather than patching around it: Tajawal and Almarai
             carry no shekel sign, so a price printed "₪45.00" came out with
             an empty box in front of the number. Plex has U+20AA, along with
             the euro, pound, lira and riyal, so the symbol is simply drawn —
             in the same typeface as the rest of the line, which the earlier
             fix (borrowing the glyph from DejaVu for that one character)
             could not manage. --}}
        body { font-family: plexarabic; font-size: 13px; color: #1f2937; }

        /* ---------- Masthead (page 1 only) ---------- */
        .masthead { width: 100%; text-align: center; }
        .masthead td { border: 0; padding: 0; text-align: center; }
        .logo-cell { padding-bottom: 12px; }
        .brand-name {
            font-size: 38px; font-weight: bold; letter-spacing: 0.5px;
            color: {{ $secondary }}; padding-bottom: 3px;
        }
        .brand-tagline { font-size: 13.5px; color: #6b7280; padding-bottom: 8px; }
        /* A short centred rule under the name, in the restaurant's own
           colour — the one flourish on the page, and the thing that makes it
           read as this café's menu rather than a generic price list. */
        {{-- The rules are tables holding a non-breaking space, not empty
             divs with a border.

             An empty element gets no line box in mPDF and none of its
             borders are painted, so both of these — the flourish under the
             restaurant's name and the one closing each section heading —
             rendered as nothing at all. The document simply had no ornament
             on it and looked like a list. The nbsp gives the cell something
             to be the height of; font-size and line-height hold that height
             down to the thickness of the rule itself. --}}
        .rule { padding-top: 8px; padding-bottom: 20px; }
        .rule-line {
            width: 70px; margin: 0 auto;
            border-bottom: 2.5px solid {{ $primary }};
            font-size: 1px; line-height: 1px;
        }
        .rule-line td { border: 0; padding: 0; font-size: 1px; line-height: 1px; }
        .brand-contact { font-size: 11.5px; color: #4b5563; padding-bottom: 3px; }

        /* ---------- Menu title (only when more than one menu is live) ---------- */
        .menu-title {
            font-size: 17px; font-weight: bold; color: {{ $primary }};
            text-align: center; padding: 16px 0 2px;
        }

        /* ---------- Category heading ---------- */
        /* A sheet per section.

           page-break-inside is deliberately NOT set here. It was, back when
           sections ran on one after another and a heading could strand
           itself at the foot of a page — a problem the page break solves
           outright. Left in place it would be actively harmful: a section
           with more dishes than fit on one sheet cannot avoid an internal
           break, and asking mPDF to avoid one anyway is how a long list ends
           up overflowing the page instead of continuing on the next. Only
           the individual dish still refuses to split. */
        .category-new-page { page-break-before: always; }
        {{-- The section title, set as the title of the page it now owns.

             It used to be a 16px line with the section's description pushed
             out to the opposite margin and a rule beneath — the shape of a
             heading in a report, which is what the whole page looked like.
             Now that a section gets a sheet to itself, its name is the first
             thing a customer sees on that sheet and can carry the weight:
             centred, large, with the description under it and a short rule
             in the restaurant's colour to close the block. --}}
        .category-head { width: 100%; margin-top: 6px; }
        .category-head td { border: 0; padding: 0; text-align: center; }
        {{-- Fixed dark, not the brand's secondary colour. Accents on this
             page follow the restaurant's palette; the text you actually read
             does not, because nothing stops an owner choosing a pale yellow
             and turning every section heading on the menu into a smudge. --}}
        .category-name {
            font-size: 24px; font-weight: bold; color: #111827;
            letter-spacing: 0.3px; padding-bottom: 4px;
        }
        .category-desc {
            font-size: 11.5px; color: #6b7280; padding-bottom: 8px;
        }
        {{-- Same construction as .rule-line above, and for the same reason. --}}
        .category-rule { padding-bottom: 18px; }
        .category-rule-line {
            width: 46px; margin: 0 auto;
            border-bottom: 2px solid {{ $primary }};
            font-size: 1px; line-height: 1px;
        }
        .category-rule-line td { border: 0; padding: 0; font-size: 1px; line-height: 1px; }

        /* A nested section — "مشروبات ← ساخنة". Quieter than its parent so
           the hierarchy is legible without indentation, which RTL and LTR
           would need mirrored. */
        .subcategory-head {
            width: 100%; margin-top: 18px; border-bottom: 0.8px dotted #cbd5e1;
        }
        .subcategory-head td { border: 0; padding: 0 0 5px; }
        .subcategory-name {
            font-size: 14px; font-weight: bold; color: {{ $primary }};
        }

        /* ---------- One dish ---------- */
        {{-- One dotted rule per dish, running the full width beneath it.

             The first attempt at this was the classic mid-line leader — a
             narrow cell of dots between the name and the price. It cannot be
             made to work here. The dots need to begin where the dish name
             ends, and mPDF has no shrink-to-fit cell: the name column is a
             fixed share of the row whatever the name, so short dishes left
             the leader floating in open space with a gap on either side,
             reading as a printing fault rather than a flourish. Widening the
             dots cell to close the gap collapsed the Arabic name to one
             character per line, which is the failure this layout started
             from.

             A rule under the whole entry is the older convention anyway, it
             separates a dish from the one after it — which the leader never
             did — and it holds whatever length the name turns out to be. --}}
        {{-- Set at the size a menu is actually read at.

             This whole page was 12px type with 8px between entries, which is
             the density of a printed report — right for a page you file, and
             wrong for one held at arm's length across a table while somebody
             decides what to eat. A section rarely fills its sheet anyway, so
             the tighter setting bought nothing and spent the one thing a
             menu trades on. --}}
        .item {
            width: 100%; margin-top: 13px; padding-bottom: 11px;
            border-bottom: 1px dotted #dcdfe4;
            {{-- A dish is one thing. Split across a page break, its name
                 ends one page and its price starts the next. --}}
            page-break-inside: avoid;
        }
        .item td { border: 0; padding: 0; vertical-align: bottom; }
        .item-name { font-size: 15px; font-weight: bold; color: #111827; }
        {{-- Both shares stated, and that is not cosmetic. mPDF hands a cell
             asking for 100% exactly that and squeezes its neighbour to the
             narrowest box it can manage — which for Arabic is one character,
             so dish names came out as vertical columns of letters and prices
             as stacks of digits. --}}
        .item-name-cell { width: 76%; }
        .item-price {
            width: 24%;
            font-size: 15px; font-weight: bold; color: {{ $primary }};
            text-align: end;
        }
        .item-desc {
            font-size: 11px; color: #6b7280; padding-top: 3px;
            line-height: 1.45;
        }
        {{-- A size up and a shade darker, but still grey rather than the
             brand colour. "نباتي" and "حار" are two of the few words on a
             menu a customer may actually be hunting for, and at 9px in
             #9ca3af they were the faintest thing on the page.

             Not tinted with $primary, which was the first attempt: that
             setting defaults to near-black and plenty of restaurants leave
             it there, which would print these at the weight of body text and
             make a dish's tags compete with its name. Grey holds whatever
             the palette turns out to be. --}}
        .item-tags {
            font-size: 10px; color: #8b93a1; padding-top: 3px;
        }
        /* Sizes, priced individually and one to a line. A dish sold in three
           sizes has no one price, and printing the largest next to its name
           — which is what the single figure would be — is the kind of wrong
           that gets argued about at the till.

           Set a step down from the dish name so the sizes read as belonging
           to it rather than as three more dishes. */
        .size-name {
            font-size: 13px; color: #374151;
            padding-top: 2px; padding-{{ $direction === 'rtl' ? 'right' : 'left' }}: 10px;
        }
        .item td.size-price { font-size: 13px; padding-top: 4px; }

        .empty { font-size: 11px; color: #9ca3af; padding-top: 20px; text-align: center; }

        /* ---------- Running header (pages 2 and on) ---------- */
        .run-head {
            border-bottom: 0.8px solid #e5e7eb; padding-bottom: 5px;
            font-size: 9.5px; color: #6b7280; width: 100%;
        }
        .run-head td { border: 0; padding: 0; }
        .head-end { text-align: end; }

        /* ---------- Running footer ---------- */
        .run-foot {
            border-top: 0.8px solid #e5e7eb; padding-top: 5px;
            font-size: 9px; color: #6b7280; width: 100%;
        }
        .run-foot td { border: 0; padding: 0; }
        .foot-end { text-align: end; }
    </style>
</head>
<body>

{{-- Repeated on every page: the number to ring is the entire point of a menu
     that travels, and page two of a forwarded PDF is as likely to be the one
     someone is looking at as page one. --}}
<htmlpagefooter name="menu-foot">
    <table class="run-foot">
        <tr>
            <td>{{ $branding->name }}@if ($contact) — {{ $contact }}@endif</td>
            <td class="foot-end">{{ $labels['page'] }} {PAGENO} / {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="menu-foot" value="on" />

<table class="masthead">
    @if ($logoPath)
        <tr><td class="logo-cell"><img src="{{ $logoPath }}" height="70"></td></tr>
    @endif
    <tr><td class="brand-name">{{ $branding->name }}</td></tr>
    @if ($branding->tagline)
        <tr><td class="brand-tagline">{{ $branding->tagline }}</td></tr>
    @endif
    <tr><td class="rule"><table class="rule-line"><tr><td>&nbsp;</td></tr></table></td></tr>
    @if ($branding->address)
        <tr><td class="brand-contact">{{ $branding->address }}</td></tr>
    @endif
    @if ($contact)
        <tr><td class="brand-contact">{{ $labels['phone'] }}: {{ $contact }}</td></tr>
    @endif
    @if ($social)
        <tr><td class="brand-contact">{{ $social }}</td></tr>
    @endif
</table>

{{-- A quiet line of identity on every sheet after the first.

     Now that each section opens its own page, the pages come apart: one is
     handed to a customer asking about drinks, another is left on a table,
     a third is the only one that gets forwarded. Page one carries the full
     masthead and the rest carried nothing but a section heading — a sheet of
     prices with no name on it.

     Declared after the masthead and without show-this-page, which is what
     keeps it off page one: repeating the name directly under the logo would
     say the same thing twice in two sizes. --}}
<htmlpageheader name="menu-head">
    <table class="run-head">
        <tr>
            <td>{{ $branding->name }}</td>
            @if ($branding->tagline)
                <td class="head-end">{{ $branding->tagline }}</td>
            @endif
        </tr>
    </table>
</htmlpageheader>
<sethtmlpageheader name="menu-head" value="on" />

@php
    $renderable = $menus->filter(fn (array $menu) => collect($menu['categories'])
        ->sum(fn (array $category) => $category['product_count']) > 0);

    // Counted across every menu, not restarted for each one. Only the very
    // first section on the document shares the masthead's page; each one
    // after it opens a sheet of its own, and a counter that reset per menu
    // would put the Ramadan list's first section on the same page as the
    // last section of the main one.
    $printedCategories = 0;
@endphp

@forelse ($renderable as $menu)
    {{-- Named only when there is more than one. A single-menu café would
         otherwise get "القائمة" printed under its own masthead, which says
         nothing and costs a line. --}}
    @if ($renderable->count() > 1)
        <div class="menu-title @if ($printedCategories > 0) category-new-page @endif">{{ $menu['name'] }}</div>
    @endif

    @foreach ($menu['categories'] as $category)
        @if ($category['product_count'] > 0)
            @include('pdf.partials.menu-category', [
                'category' => $category,
                'depth' => 0,
                'currencySymbol' => $currencySymbol,
                'labels' => $labels,
                // A menu title has already opened the page for the section
                // that follows it, so that section must not break again and
                // leave a sheet holding nothing but the title.
                'isFirstCategory' => $printedCategories === 0
                    || ($renderable->count() > 1 && $loop->first),
            ])
            @php $printedCategories++; @endphp
        @endif
    @endforeach
@empty
    <div class="empty">{{ $labels['menu_empty'] }}</div>
@endforelse

@if ($branding->receipt_footer_text)
    <table class="masthead" style="margin-top: 22px;">
        <tr><td class="rule"><table class="rule-line"><tr><td>&nbsp;</td></tr></table></td></tr>
        <tr><td class="brand-tagline">{{ $branding->receipt_footer_text }}</td></tr>
    </table>
@endif

</body>
</html>
