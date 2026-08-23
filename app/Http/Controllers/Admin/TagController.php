<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTagRequest;
use App\Http\Requests\Admin\UpdateTagRequest;
use App\Models\Tag;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    public function index(): Response
    {
        $tags = Tag::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('Admin/Tags/Index', [
            'tags' => $tags->map(fn (Tag $tag) => $this->present($tag)),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Tags/Form', [
            'languages' => $this->registry->active()->values(),
            'tag' => null,
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        Tag::create([...$data, ...$translations]);

        return to_route('admin.tags.index')->with('success', __('admin.tags.created'));
    }

    public function edit(Tag $tag): Response
    {
        $tag->load('translations');

        return Inertia::render('Admin/Tags/Form', [
            'languages' => $this->registry->active()->values(),
            'tag' => $this->present($tag, includeTranslations: true),
        ]);
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        $tag->update([...$data, ...$translations]);

        return to_route('admin.tags.index')->with('success', __('admin.tags.updated'));
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return to_route('admin.tags.index')->with('success', __('admin.tags.deleted'));
    }

    protected function present(Tag $tag, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();

        $payload = [
            'id' => $tag->id,
            'slug' => $tag->slug,
            'icon' => $tag->icon,
            'is_active' => $tag->is_active,
            'sort_order' => $tag->sort_order,
            'name' => $tag->translate($this->registry->default()?->code)?->name ?? $tag->slug,
            'missing_locales' => array_values(array_diff($activeCodes, $tag->translations->pluck('locale')->all())),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(fn (string $code) => [
                $code => ['name' => $tag->translate($code, withFallback: false)?->name ?? ''],
            ]);
        }

        return $payload;
    }
}
