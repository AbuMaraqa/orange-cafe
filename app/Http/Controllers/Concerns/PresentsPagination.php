<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * One shape for every paginated list in the app.
 *
 * Deliberately not Laravel's own paginator payload. That carries a `links`
 * array of pre-rendered anchors with labels like "&laquo; Previous" —
 * markup decided on the server, in English, for a Blade view this app does
 * not have. A Vue component that has to parse it back out cannot do
 * anything sensible with the result in Arabic or right-to-left.
 *
 * So the server sends facts and the component decides how to say them:
 * which page, how many there are, how many rows in total, and the range
 * this page covers. Every table then reads the same contract, and a new one
 * gets working pagination by passing this array to the same component.
 *
 * @see resources/js/Components/Pagination.vue
 */
trait PresentsPagination
{
    /**
     * The page sizes offered in the picker.
     *
     * Kept short on purpose. A staff member choosing between four numbers
     * makes a decision; one choosing between ten is reading a menu.
     */
    private const PAGE_SIZES = [10, 25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 25;

    /**
     * Paginates a query and returns the two props a table screen needs.
     *
     * Spread into an Inertia payload:
     *
     *     return Inertia::render('Admin/Products/Index', [
     *         ...$this->paginated('products', $query, $request, fn ($p) => $this->present($p)),
     *         'filters' => $filters,
     *     ]);
     *
     * Exists because the alternative is four steps repeated per screen —
     * paginate with the right page size, withQueryString(), map the page's
     * own collection, name the meta prop `pagination` — and there are
     * nineteen of these tables. Every one of them is a chance to forget
     * withQueryString(), which does not fail loudly: the table simply
     * widens back to the unfiltered set on page two, and only somebody
     * paging through a filtered list ever finds out.
     *
     * @param  string  $key  the prop the rows arrive under, e.g. 'products'
     * @param  (callable(mixed): mixed)|null  $present  per-row presenter
     * @return array<string, mixed>
     */
    protected function paginated(string $key, Builder $query, Request $request, ?callable $present = null): array
    {
        $paginator = $query->paginate($this->perPage($request))->withQueryString();

        $rows = $paginator->getCollection();

        return [
            // values() so a filtered collection cannot arrive as a JSON
            // object keyed by its original offsets, which is what a Vue
            // v-for over it would then silently iterate.
            $key => ($present ? $rows->map($present) : $rows)->values(),
            'pagination' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * How many rows this request asked for, clamped to something sane.
     *
     * Validated rather than trusted: `?per_page=100000` is otherwise a way
     * for anyone with a URL bar to ask the server to load the whole table
     * into memory, which is the exact failure pagination exists to prevent.
     */
    protected function perPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($requested, self::PAGE_SIZES, true) ? $requested : self::DEFAULT_PAGE_SIZE;
    }

    /**
     * The facts a pagination control needs, and nothing else.
     *
     * `from` and `to` are null on an empty result — there is no range to
     * show when there is nothing to show — and the component reads that as
     * "say nothing" rather than printing "0–0 of 0".
     *
     * @return array<string, mixed>
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'page_sizes' => self::PAGE_SIZES,
        ];
    }
}
