/**
 * Finding a dish by typing its name, forgivingly.
 *
 * Arabic is typed fast and inconsistently on a busy floor: أ/إ/آ/ا are the
 * same letter to everyone except a string comparison, ة and ه are used
 * interchangeably at the end of a word, ى and ي are a coin toss, and
 * diacritics and tatweel appear or not depending on the keyboard. A plain
 * `includes()` matches none of those, so the waiter types a real dish name,
 * gets "no results", and goes back to hunting through categories.
 *
 * This lived only in the customer menu; the staff order screen had its own
 * naive matcher. Extracted so both use one implementation — the staff
 * screen is the one under time pressure, and it had the worse of the two.
 */

/**
 * Folds away everything two people would consider the same word.
 */
const normalizeCache = new Map();

export function normalize(value) {
    const key = value ?? '';

    // Searching re-folds the same few hundred names on every keystroke, and
    // those strings never change. The second pass over a name is work done
    // between the waiter's finger leaving one key and hitting the next.
    const cached = normalizeCache.get(key);

    if (cached !== undefined) {
        return cached;
    }

    const folded = fold(key);

    normalizeCache.set(key, folded);

    return folded;
}

function fold(value) {
    return (value ?? '')
        .toLowerCase()
        // Harakat, superscript alef and tatweel: decoration, never meaning.
        .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
        .replace(/[إأآا]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Every typed word must appear somewhere in the name, the description, or
 * the categories the dish sits under, in any order — so "تفاح كيك" still
 * finds "كيك التفاح", which is how people actually remember a dish.
 *
 * `context` is the category trail ("مشروبات › ساخنة"). A good half of what
 * gets typed on the floor is a category and not a dish — "عصائر",
 * "حلويات" — and matching names only answered that with "no results" while
 * the screen sat there with a chip for that exact word.
 */
export function matchesQuery(product, query, context = '') {
    const needle = normalize(query);

    if (!needle) {
        return true;
    }

    const haystack = `${normalize(product.name)} ${normalize(product.description ?? '')} ${normalize(context)}`;

    return needle.split(' ').every((word) => haystack.includes(word));
}

/**
 * How good a match this is, for ordering results. Lower sorts first.
 *
 * The point is the top result: on this screen Enter adds it, so "the one
 * they meant" has to be first rather than merely present. A name that
 * starts with what was typed beats one that starts with something else,
 * and a shorter name beats a longer one containing it — typing "شاي" should
 * offer شاي before شاي بالنعناع المثلج.
 */
export function matchRank(product, query, context = '') {
    const needle = normalize(query);
    const name = normalize(product.name);

    if (!needle) {
        return 4;
    }

    if (name === needle) {
        return 0;
    }

    if (name.startsWith(needle)) {
        return 1;
    }

    if (name.includes(needle)) {
        return 2;
    }

    // Matched on separate words or on the description only.
    if (matchesQuery(product, query)) {
        return 3;
    }

    // Nothing on the dish itself matched — only the category it sits in
    // did. Every dish in that category is a hit, so they sort below
    // anything whose own name was typed: otherwise a broad word like
    // "قهوة" buries the dish actually called قهوة under its own shelf.
    return 4;
}

/**
 * The flat, best-first list of everything matching, across every menu.
 *
 * Flat and cross-menu on purpose. Grouped-by-category results re-create the
 * problem searching is meant to solve — the waiter is back to scanning
 * headings — and a menu switcher hides matches that are one tab away, which
 * on this screen means the dish appears not to exist.
 *
 * @param {Array} menus  the payload from MenuCatalogPresenter
 * @param {string} query
 */
export function searchMenus(menus, query) {
    const found = [];

    const walk = (category, trail) => {
        const path = [...trail, category.name];

        const contextPath = path.join(' › ');

        for (const product of category.products ?? []) {
            if (matchesQuery(product, query, contextPath)) {
                // The trail is what tells two identically-named dishes apart
                // — the same drink can sit on two menus at two prices.
                found.push({ ...product, categoryPath: contextPath });
            }
        }

        for (const sub of category.subcategories ?? []) {
            walk(sub, path);
        }
    };

    for (const menu of menus) {
        for (const category of menu.categories ?? []) {
            walk(category, menus.length > 1 ? [menu.name] : []);
        }
    }

    return found.sort((a, b) => {
        const byRank = matchRank(a, query, a.categoryPath) - matchRank(b, query, b.categoryPath);

        return byRank !== 0 ? byRank : normalize(a.name).length - normalize(b.name).length;
    });
}
