<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Services\Images\ImageOptimizer;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Service;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    public function index(): Response
    {
        $services = Service::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('Admin/Services/Index', [
            'services' => $services->map(fn (Service $service) => $this->present($service)),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Services/Form', [
            'languages' => $this->registry->active()->values(),
            'service' => null,
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        if ($request->hasFile('image')) {
            $data['image_path'] = app(ImageOptimizer::class)->store($request->file('image'), 'services');
        }
        unset($data['image']);

        Service::create([...$data, ...$translations]);

        return to_route('admin.services.index')->with('success', __('admin.services.created'));
    }

    public function edit(Service $service): Response
    {
        $service->load('translations');

        return Inertia::render('Admin/Services/Form', [
            'languages' => $this->registry->active()->values(),
            'service' => $this->present($service, includeTranslations: true),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        if ($request->hasFile('image')) {
            if ($service->image_path) {
                Storage::disk('public')->delete($service->image_path);
            }
            $data['image_path'] = app(ImageOptimizer::class)->store($request->file('image'), 'services');
        }
        unset($data['image']);

        $service->update([...$data, ...$translations]);

        return to_route('admin.services.index')->with('success', __('admin.services.updated'));
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return to_route('admin.services.index')->with('success', __('admin.services.deleted'));
    }

    protected function present(Service $service, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();

        $payload = [
            'id' => $service->id,
            'slug' => $service->slug,
            'icon' => $service->icon,
            'image_url' => $service->image_url,
            'color' => $service->displayColor(),
            'is_active' => $service->is_active,
            'sort_order' => $service->sort_order,
            'name' => $service->translate($this->registry->default()?->code)?->name ?? $service->slug,
            'missing_locales' => array_values(array_diff($activeCodes, $service->translations->pluck('locale')->all())),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(fn (string $code) => [
                $code => ['name' => $service->translate($code, withFallback: false)?->name ?? ''],
            ]);
        }

        return $payload;
    }
}
