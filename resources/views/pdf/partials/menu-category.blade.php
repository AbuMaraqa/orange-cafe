{{--
    One section of the printed menu, and everything under it.

    Recursive, because the catalog is: MenuCatalogPresenter nests categories
    to whatever depth the owner built ("مشروبات ← ساخنة ← قهوة"), and a
    partial that handled two fixed levels would silently drop the third — the
    same bug the presenter's own docblock records having shipped once.

    A section with no products anywhere beneath it prints nothing at all. An
    empty heading on a menu reads as food the kitchen has run out of.
--}}
@if ($category['product_count'] > 0)
    {{-- Each top-level section opens its own sheet. Sub-sections do not:
         "ساخنة" belongs on the same page as the "مشروبات" it is part of, and
         giving every leaf its own sheet would turn a café's drinks list into
         five pages with four lines on each. --}}
    <div class="category @if ($depth === 0 && ! $isFirstCategory) category-new-page @endif">
        @if ($depth === 0)
            <table class="category-head">
                <tr><td class="category-name">{{ $category['name'] }}</td></tr>
                @if ($category['description'])
                    <tr><td class="category-desc">{{ $category['description'] }}</td></tr>
                @endif
                <tr><td class="category-rule"><table class="category-rule-line"><tr><td>&nbsp;</td></tr></table></td></tr>
            </table>
        @else
            <table class="subcategory-head">
                <tr><td class="subcategory-name">{{ $category['name'] }}</td></tr>
            </table>
        @endif

        @foreach ($category['products'] as $product)
            @php
                // The group whose options replace the price rather than add
                // to it — that is what makes a size a size (see
                // ModifierGroup::is_variation). Extras are deliberately not
                // printed: a menu listing every possible addition next to
                // every dish becomes a form, not a menu.
                //
                // Two steps rather than subscripting firstWhere() directly:
                // a product with no variation group returns null there, and
                // indexing null is a warning on every dish that is not sold
                // in sizes — which is most of them.
                $variation = collect($product['modifier_groups'])->firstWhere('is_variation', true);
                $sizes = $variation ? $variation['modifiers'] : [];
                $tags = collect($product['tags'])->pluck('name')->filter()->implode(' · ');
            @endphp

            <table class="item">
                <tr>
                    <td class="item-name-cell item-name">{{ $product['name'] }}</td>
                    <td class="item-price">
                        {{-- Nothing here for a sized dish. Its prices are
                             listed underneath, one per size, and the single
                             figure this cell would hold is the top of the
                             range — right beside the name of the small one. --}}
                        @if (count($sizes) === 0)
                            {{ $currencySymbol }}{{ number_format($product['price_cents'] / 100, 2) }}
                        @endif
                    </td>
                </tr>
                @if ($product['description'])
                    <tr><td class="item-desc" colspan="2">{{ $product['description'] }}</td></tr>
                @endif
                {{-- One row per size, priced in the same column as every
                     other price on the page.

                     These used to run inline under the dish — "صغير ₪10 ·
                     كبير ₪15" — which left the price column empty on exactly
                     those rows. Next to dishes whose price sits out at the
                     margin, a sized dish read as a line the renderer had
                     failed to finish, and the figures a customer most needs
                     to compare were the only ones not in a column. --}}
                @foreach ($sizes as $size)
                    <tr>
                        <td class="item-name-cell size-name">{{ $size['name'] }}</td>
                        <td class="item-price size-price">{{ $currencySymbol }}{{ number_format($size['price_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                @if ($tags)
                    <tr><td class="item-tags" colspan="2">{{ $tags }}</td></tr>
                @endif
            </table>
        @endforeach

        @foreach ($category['subcategories'] as $subcategory)
            @include('pdf.partials.menu-category', [
                'category' => $subcategory,
                'depth' => $depth + 1,
                'currencySymbol' => $currencySymbol,
                'labels' => $labels,
                // Only a top-level section can open a page, so this never
                // reaches a decision from here — passed on so the include
                // has every variable the partial names.
                'isFirstCategory' => false,
            ])
        @endforeach
    </div>
@endif
