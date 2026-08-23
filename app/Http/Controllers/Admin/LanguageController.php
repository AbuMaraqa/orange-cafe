<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLanguageRequest;
use App\Http\Requests\Admin\UpdateLanguageRequest;
use App\Models\Language;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class LanguageController extends Controller
{
    public function __construct(protected LanguageRegistry $registry) {}

    public function index(): Response
    {
        $languages = Language::query()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return Inertia::render('Admin/Languages/Index', [
            'languages' => $languages,
            'translationStatus' => $this->translationStatus($languages),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Languages/Create');
    }

    public function store(StoreLanguageRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['sort_order'] = $data['sort_order'] ?? ((int) Language::max('sort_order') + 1);

        Language::create($data);

        return to_route('admin.languages.index')->with('success', __('admin.languages.created'));
    }

    public function edit(Language $language): Response
    {
        return Inertia::render('Admin/Languages/Edit', ['language' => $language]);
    }

    public function update(UpdateLanguageRequest $request, Language $language): RedirectResponse
    {
        $language->update($request->validated());

        return to_route('admin.languages.index')->with('success', __('admin.languages.updated'));
    }

    public function destroy(Language $language): RedirectResponse
    {
        $this->registry->assertDeletable($language);

        $language->delete();

        return to_route('admin.languages.index')->with('success', __('admin.languages.deleted'));
    }

    public function activate(Language $language): RedirectResponse
    {
        if ($language->is_default && $language->is_active) {
            return back()->withErrors(['language' => __('admin.languages.cannot_deactivate_default')]);
        }

        $language->update(['is_active' => ! $language->is_active]);

        return back()->with('success', __('admin.languages.updated'));
    }

    public function makeDefault(Language $language): RedirectResponse
    {
        $language->update(['is_default' => true, 'is_active' => true]);

        return back()->with('success', __('admin.languages.default_set'));
    }

    public function reorder(Request $request): RedirectResponse
    {
        $order = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:languages,id'],
        ])['order'];

        foreach ($order as $index => $id) {
            Language::whereKey($id)->update(['sort_order' => $index]);
        }

        $this->registry->flush();

        return back();
    }

    /**
     * @return array<string, array{total: int, byLocale: Collection}>
     */
    protected function translationStatus($languages): array
    {
        $status = [];

        foreach (config('translation.translatable_models', []) as $modelClass) {
            $translationClass = (new $modelClass)->getTranslationModelName();

            $counts = $translationClass::query()
                ->selectRaw('locale, count(*) as aggregate')
                ->groupBy('locale')
                ->pluck('aggregate', 'locale');

            $status[class_basename($modelClass)] = [
                'total' => $modelClass::query()->count(),
                'byLocale' => $languages->mapWithKeys(fn ($lang) => [
                    $lang->code => (int) ($counts[$lang->code] ?? 0),
                ]),
            ];
        }

        return $status;
    }
}
