/**
 * What one of a product costs, given what the customer chose.
 *
 * The mirror of Product::unitPriceCents() on the server, and the only
 * place the client does this arithmetic. It was previously written out as
 * a `+` in three places — the customer's cart, the staff order screen, and
 * the product sheet — which was fine while there was one rule and became
 * three places to forget the second one.
 *
 * The rule: a **variation** (small / medium / large) *is* the price and
 * replaces the base; everything else (extra cheese) adds to it. A dish
 * sold in sizes therefore needs no base price of its own — it never had a
 * single price to give.
 *
 * The server recomputes every line from the menu on submit and never
 * trusts these numbers, so this is what the customer is *shown*. When the
 * two disagree the customer is quoted one total and charged another, which
 * is precisely the bug this exists to prevent.
 */

/** Ids of the groups on this product whose choice sets the price. */
function variationGroupIds(product) {
    return new Set(
        (product.modifier_groups ?? [])
            .filter((group) => group.is_variation)
            .map((group) => group.id)
    );
}

/**
 * @param {object} product        a menu payload product
 * @param {Array}  selected       the chosen modifiers
 * @returns {number} price in cents for a single unit
 */
export function unitPriceCents(product, selected = []) {
    const variations = variationGroupIds(product);

    const chosenVariation = selected.find((modifier) => variations.has(modifier.modifier_group_id));

    // base_price_cents is the product's own price. `price_cents` is what
    // the card advertises, which for a sized dish is the top of its range
    // — using that as a base would add the largest size to every choice.
    const base = chosenVariation
        ? chosenVariation.price_cents
        : (product.base_price_cents ?? product.price_cents);

    const extras = selected
        .filter((modifier) => !variations.has(modifier.modifier_group_id))
        .reduce((sum, modifier) => sum + modifier.price_cents, 0);

    return base + extras;
}

/** True when the customer must pick a size before this has a price. */
export function hasVariation(product) {
    return variationGroupIds(product).size > 0;
}
