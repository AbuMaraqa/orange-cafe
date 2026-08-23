<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreModifierGroupRequest;
use App\Http\Requests\Admin\UpdateModifierGroupRequest;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    /**
     * Persists a drag-and-drop reorder of the variation groups.
     *
     * This order is not cosmetic: it is the sequence the groups appear in on
     * the customer's product screen (index() and every product query order by
     * sort_order), so "الحجم" belongs above "إضافات" and only someone looking
     * at the list can say so. A flat list of ids in display order is all the
     * client has to send.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $order = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:modifier_groups,id'],
        ])['order'];

        DB::transaction(function () use ($order) {
            foreach ($order as $index => $id) {
                ModifierGroup::whereKey($id)->update(['sort_order' => $index]);
            }
        });

        return back();
    }

    public function index(): Response
    {
        $groups = ModifierGroup::query()
            ->with('translations', 'modifiers.translations')
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/ModifierGroups/Index', [
            'groups' => $groups->map(fn (ModifierGroup $group) => $this->present($group)),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ModifierGroups/Form', [
            'languages' => $this->registry->active()->values(),
            'group' => null,
        ]);
    }

    public function store(StoreModifierGroupRequest $request): RedirectResponse
    {
        $defaultLocale = $this->registry->default()?->code;

        DB::transaction(function () use ($request, $defaultLocale) {
            $data = $request->validated();
            $modifiers = $data['modifiers'];
            $translations = $this->fillMissingTranslations($data['translations'], $defaultLocale);
            unset($data['modifiers'], $data['translations']);

            $group = ModifierGroup::create([...$data, ...$translations]);

            foreach ($modifiers as $index => $modifier) {
                $modifierTranslations = $this->fillMissingTranslations($modifier['translations'], $defaultLocale);
                unset($modifier['translations']);

                $group->modifiers()->create([
                    ...$modifier,
                    ...$modifierTranslations,
                    'sort_order' => $modifier['sort_order'] ?? $index,
                ]);
            }
        });

        return to_route('admin.modifier-groups.index')->with('success', __('admin.modifier_groups.created'));
    }

    public function edit(ModifierGroup $modifierGroup): Response
    {
        $modifierGroup->load('translations', 'modifiers.translations');

        return Inertia::render('Admin/ModifierGroups/Form', [
            'languages' => $this->registry->active()->values(),
            'group' => $this->present($modifierGroup, includeTranslations: true),
        ]);
    }

    public function update(UpdateModifierGroupRequest $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $defaultLocale = $this->registry->default()?->code;

        DB::transaction(function () use ($request, $modifierGroup, $defaultLocale) {
            $data = $request->validated();
            $modifiers = $data['modifiers'];
            $translations = $this->fillMissingTranslations($data['translations'], $defaultLocale);
            unset($data['modifiers'], $data['translations']);

            $modifierGroup->update([...$data, ...$translations]);

            $keptIds = [];

            foreach ($modifiers as $index => $modifier) {
                $modifierTranslations = $this->fillMissingTranslations($modifier['translations'], $defaultLocale);
                $id = $modifier['id'] ?? null;
                unset($modifier['id'], $modifier['translations']);
                $modifier['sort_order'] = $modifier['sort_order'] ?? $index;

                if ($id) {
                    $existing = Modifier::findOrFail($id);
                    $existing->update([...$modifier, ...$modifierTranslations]);
                    $keptIds[] = $existing->id;
                } else {
                    $created = $modifierGroup->modifiers()->create([...$modifier, ...$modifierTranslations]);
                    $keptIds[] = $created->id;
                }
            }

            $modifierGroup->modifiers()->whereKeyNot($keptIds)->delete();
        });

        return to_route('admin.modifier-groups.index')->with('success', __('admin.modifier_groups.updated'));
    }

    /**
     * The screen for putting one add-on group onto many products at once.
     *
     * Attaching add-ons product by product does not scale: this cafe has 148
     * products and five add-on groups, and only 14 products ever ended up
     * linked — not because anyone chose that, but because doing it meant
     * opening 148 edit forms one at a time. So the add-ons existed and
     * almost no customer was ever offered one.
     */
    public function products(ModifierGroup $modifierGroup): Response
    {
        $modifierGroup->load('modifiers.translations', 'translations');

        return Inertia::render('Admin/ModifierGroups/Products', [
            'group' => [
                'id' => $modifierGroup->id,
                'name' => $modifierGroup->name,
                'is_required' => $modifierGroup->is_required,
                'min_select' => $modifierGroup->min_select,
                'max_select' => $modifierGroup->max_select,
                'modifiers' => $modifierGroup->modifiers->map(fn ($modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price_cents' => $modifier->price_cents,
                ]),
            ],
            'products' => Product::query()
                ->with(['translations', 'menuItems.category.translations'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price_cents' => $product->price_cents,
                    'is_active' => $product->is_active,
                    // Which sections it sits in, so a whole section can be
                    // ticked at once instead of hunted for by name.
                    'categories' => $product->menuItems
                        ->map(fn ($item) => $item->category?->name)
                        ->filter()
                        ->unique()
                        ->values(),
                ]),
            'assigned' => $modifierGroup->products()->pluck('products.id'),
        ]);
    }

    /**
     * Applies the chosen set. sync() rather than attach-only, so unticking a
     * product removes it — the screen shows the whole picture, and saving has
     * to mean what it shows.
     */
    public function syncProducts(Request $request, ModifierGroup $modifierGroup): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids' => ['present', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $modifierGroup->products()->sync($validated['product_ids']);

        return back()->with('success', __('admin.modifier_groups.products_synced'));
    }

    public function destroy(ModifierGroup $modifierGroup): RedirectResponse
    {
        $modifierGroup->delete();

        return to_route('admin.modifier-groups.index')->with('success', __('admin.modifier_groups.deleted'));
    }

    protected function present(ModifierGroup $group, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();
        $defaultLocale = $this->registry->default()?->code;

        $payload = [
            'id' => $group->id,
            'name' => $group->translate($defaultLocale)?->name ?? "#{$group->id}",
            'is_required' => $group->is_required,
            'is_variation' => $group->is_variation,
            'min_select' => $group->min_select,
            'max_select' => $group->max_select,
            'is_active' => $group->is_active,
            'sort_order' => $group->sort_order,
            'modifiers_count' => $group->modifiers->count(),
            'products_count' => $group->products_count ?? $group->products()->count(),
            'missing_locales' => array_values(array_diff($activeCodes, $group->translations->pluck('locale')->all())),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(fn (string $code) => [
                $code => ['name' => $group->translate($code, withFallback: false)?->name ?? ''],
            ]);

            $payload['modifiers'] = $group->modifiers->map(fn (Modifier $modifier) => [
                'id' => $modifier->id,
                'price_cents' => $modifier->price_cents,
                'is_active' => $modifier->is_active,
                'sort_order' => $modifier->sort_order,
                'translations' => collect($activeCodes)->mapWithKeys(fn (string $code) => [
                    $code => ['name' => $modifier->translate($code, withFallback: false)?->name ?? ''],
                ]),
            ])->values();
        }

        return $payload;
    }
}
