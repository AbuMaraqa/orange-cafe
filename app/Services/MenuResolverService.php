<?php

namespace App\Services;

use App\Models\Menu;
use Illuminate\Support\Collection;

/**
 * The single place that decides which menus a customer sees right now.
 * Nothing else — controllers, Vue components, the QR code — encodes menu
 * selection logic; they all go through here. A table's QR code always
 * points at the generic customer menu page, never a specific menu, so
 * whatever this resolves is what's served.
 */
class MenuResolverService
{
    /**
     * Menus visible to a customer scanning a QR code right now: published,
     * manually active, and inside their schedule — ordered by priority.
     *
     * @return Collection<int, Menu>
     */
    public function visibleMenus(): Collection
    {
        return Menu::query()
            ->where('publish_status', 'published')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Menu $menu) => $menu->isWithinSchedule())
            ->values();
    }
}
