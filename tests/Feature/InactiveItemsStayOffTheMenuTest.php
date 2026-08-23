<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Services\MenuCatalogPresenter;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching something off in the admin panel takes it off the menu.
 *
 * It did not, at any depth below the top of the tree. The customer menu
 * walked the section tree through Category::childrenRecursive — the admin's
 * relation, which shows everything on purpose so a switched-off section can
 * still be found and switched back on. The `is_active` filters lived on the
 * eager-load in MenuCatalogPresenter, and an eager-load constraint reaches
 * the first level of a self-referencing relation and no further. So the top
 * of the menu was filtered and the depths of it were not: a café that turned
 * off a dish it had run out of watched customers keep ordering it.
 *
 * Each case here switches exactly one thing off and re-renders, because the
 * three are separate paths through the presenter and the bug only ever
 * affected some of them.
 */
class InactiveItemsStayOffTheMenuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every menu item a customer can see, at any depth, by id.
     *
     * By id and not by product name: one product can sit on several menus,
     * so switching one of its listings off correctly leaves the name on the
     * menu via the others. A name-based assertion reads that correct
     * behaviour as a failure.
     */
    private function visibleProducts(): array
    {
        $names = [];

        $walk = function ($categories) use (&$walk, &$names) {
            foreach ($categories as $category) {
                foreach ($category['products'] ?? [] as $product) {
                    $names[] = $product['menu_item_id'] ?? null;
                }

                $walk($category['subcategories'] ?? []);
            }
        };

        foreach (app(MenuCatalogPresenter::class)->visibleMenusPayload() as $menu) {
            $walk($menu['categories'] ?? []);
        }

        return $names;
    }

    /** Every section name a customer can see, at any depth. */
    private function visibleSections(): array
    {
        $names = [];

        $walk = function ($categories) use (&$walk, &$names) {
            foreach ($categories as $category) {
                $names[] = $category['name'] ?? '';
                $walk($category['subcategories'] ?? []);
            }
        };

        foreach (app(MenuCatalogPresenter::class)->visibleMenusPayload() as $menu) {
            $walk($menu['categories'] ?? []);
        }

        return $names;
    }

    /**
     * An item nested under a sub-section — the path that was broken. An item
     * directly under a top-level section was always filtered correctly, so
     * testing one of those would have passed throughout.
     */
    private function nestedItem(): MenuItem
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(CatalogSeeder::class);

        return MenuItem::query()
            ->whereHas('category', fn ($q) => $q->whereNotNull('parent_id'))
            ->with('product', 'category')
            ->firstOrFail();
    }

    public function test_a_switched_off_product_disappears_from_the_menu(): void
    {
        $item = $this->nestedItem();
        $id = $item->id;

        $this->assertContains($id, $this->visibleProducts(), 'precondition: it is on the menu to begin with');

        $item->product->update(['is_active' => false]);

        $this->assertNotContains($id, $this->visibleProducts());
    }

    /**
     * The other switch. A product can be active in the catalogue and still be
     * taken off one particular menu, which is a different decision with a
     * different button, and it has to work on its own.
     */
    public function test_a_switched_off_menu_item_disappears_from_the_menu(): void
    {
        $item = $this->nestedItem();
        $id = $item->id;

        $item->update(['is_active' => false]);

        $this->assertNotContains($id, $this->visibleProducts());
    }

    /**
     * A section switched off takes its contents with it — otherwise the items
     * survive as an orphaned list under a heading nobody meant to publish.
     */
    public function test_a_switched_off_section_takes_its_items_with_it(): void
    {
        $item = $this->nestedItem();
        $section = $item->category;
        $id = $item->id;

        $this->assertContains($section->name, $this->visibleSections());

        $section->update(['is_active' => false]);

        $this->assertNotContains($section->name, $this->visibleSections());
        $this->assertNotContains($id, $this->visibleProducts());
    }

    /**
     * The admin's own tree must keep showing it, or there is no way back.
     *
     * The obvious fix for all of the above — filtering childrenRecursive
     * itself — would have hidden a switched-off section from the panel that
     * exists to switch it on again, turning a reversible decision into a
     * permanent one.
     */
    public function test_the_admin_tree_still_shows_what_was_switched_off(): void
    {
        $item = $this->nestedItem();
        $section = $item->category;
        $section->update(['is_active' => false]);

        $parent = Category::with('childrenRecursive')->findOrFail($section->parent_id);

        $this->assertTrue(
            $parent->childrenRecursive->contains('id', $section->id),
            'A switched-off section has to stay reachable from the admin panel.',
        );
    }
}
