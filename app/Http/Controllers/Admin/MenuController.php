<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuPublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Models\Menu;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function index(): Response
    {
        $menus = Menu::query()
            ->withCount('categories', 'items')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Menus/Index', [
            'menus' => $menus->map(fn (Menu $menu) => $this->present($menu)),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Menus/Form', [
            'languages' => $this->registry->active()->values(),
            'publishStatuses' => $this->publishStatusOptions(),
            'menu' => null,
        ]);
    }

    public function store(StoreMenuRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        $menu = Menu::create([...$data, ...$translations]);

        return to_route('admin.menus.builder', $menu)->with('success', __('admin.menus.created'));
    }

    public function edit(Menu $menu): Response
    {
        $menu->load('translations');

        return Inertia::render('Admin/Menus/Form', [
            'languages' => $this->registry->active()->values(),
            'publishStatuses' => $this->publishStatusOptions(),
            'menu' => $this->present($menu, includeTranslations: true),
        ]);
    }

    public function update(UpdateMenuRequest $request, Menu $menu): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        $menu->update([...$data, ...$translations]);

        return to_route('admin.menus.index')->with('success', __('admin.menus.updated'));
    }

    public function destroy(Menu $menu): RedirectResponse
    {
        $menu->delete();

        return to_route('admin.menus.index')->with('success', __('admin.menus.deleted'));
    }

    protected function publishStatusOptions(): array
    {
        return array_map(fn (MenuPublishStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
        ], MenuPublishStatus::cases());
    }

    protected function present(Menu $menu, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();
        $translatedCodes = $menu->translations->pluck('locale')->all();

        $payload = [
            'id' => $menu->id,
            'slug' => $menu->slug,
            'publish_status' => $menu->publish_status->value,
            'publish_status_label' => $menu->publish_status->label(),
            'is_active' => $menu->is_active,
            'starts_at' => $menu->starts_at?->toDateString(),
            'ends_at' => $menu->ends_at?->toDateString(),
            'start_time' => $menu->start_time?->format('H:i'),
            'end_time' => $menu->end_time?->format('H:i'),
            'days_of_week' => $menu->days_of_week ?? [],
            'sort_order' => $menu->sort_order,
            'name' => $menu->translate($this->registry->default()?->code)?->name ?? $menu->slug,
            'sections_count' => $menu->categories_count ?? null,
            'items_count' => $menu->items_count ?? null,
            'missing_locales' => array_values(array_diff($activeCodes, $translatedCodes)),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(function (string $code) use ($menu) {
                $translation = $menu->translate($code, withFallback: false);

                return [$code => [
                    'name' => $translation?->name ?? '',
                    'description' => $translation?->description ?? '',
                ]];
            });
        }

        return $payload;
    }
}
