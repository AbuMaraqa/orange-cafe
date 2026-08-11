<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreModifierGroupRequest;
use App\Http\Requests\Admin\UpdateModifierGroupRequest;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function index(): Response
    {
        $groups = ModifierGroup::query()
            ->with('translations', 'modifiers.translations')
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
        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $modifiers = $data['modifiers'];
            $translations = $data['translations'];
            unset($data['modifiers'], $data['translations']);

            $group = ModifierGroup::create([...$data, ...$translations]);

            foreach ($modifiers as $index => $modifier) {
                $modifierTranslations = $modifier['translations'];
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
        DB::transaction(function () use ($request, $modifierGroup) {
            $data = $request->validated();
            $modifiers = $data['modifiers'];
            $translations = $data['translations'];
            unset($data['modifiers'], $data['translations']);

            $modifierGroup->update([...$data, ...$translations]);

            $keptIds = [];

            foreach ($modifiers as $index => $modifier) {
                $modifierTranslations = $modifier['translations'];
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
            'min_select' => $group->min_select,
            'max_select' => $group->max_select,
            'is_active' => $group->is_active,
            'sort_order' => $group->sort_order,
            'modifiers_count' => $group->modifiers->count(),
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
