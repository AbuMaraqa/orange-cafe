<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Services\MenuCatalogPresenter;
use Inertia\Inertia;
use Inertia\Response;

class CustomerMenuController extends Controller
{
    /**
     * Public QR-menu page. Locale comes from the URL segment (e.g. /ar/menu,
     * /en/menu) like every other page in the app, so it's bookmarkable and
     * shareable per language — Astrotomic resolves each category/product's
     * translation from the app locale automatically, falling back to the
     * default language when a translation is missing.
     *
     * The QR code on a table only ever points here — never at a specific
     * menu — so which menu(s) are shown is resolved fresh on every request
     * by the MenuResolverService (published + active + within schedule).
     * When more than one menu is visible at once (e.g. a Ramadan special
     * running alongside the main menu) the customer gets a menu switcher.
     */
    public function index(MenuCatalogPresenter $presenter): Response
    {
        return Inertia::render('Customer/Menu', [
            'menus' => $presenter->visibleMenusPayload(),
            'tags' => Tag::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'icon' => $tag->icon]),
        ]);
    }
}
