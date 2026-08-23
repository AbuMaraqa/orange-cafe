<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceChargeRequest;
use App\Http\Requests\Admin\UpdateServiceChargeRequest;
use App\Models\ServiceCharge;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The price list for things nobody cooks.
 *
 * Short by design: a name and an amount. What makes one of these worth a
 * screen of its own is that the floor should not be typing "خدمة طاولة"
 * and a number by hand onto every bill — the wording and the price are
 * decisions the owner makes once, and the till's job is to press them.
 */
class ServiceChargeController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    public function index(): Response
    {
        $charges = ServiceCharge::query()->with('translations')->inOrder()->get();

        return Inertia::render('Admin/ServiceCharges/Index', [
            'charges' => $charges->map(fn (ServiceCharge $charge) => $this->present($charge)),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ServiceCharges/Form', [
            'languages' => $this->registry->active()->values(),
            'charge' => null,
        ]);
    }

    public function store(StoreServiceChargeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        ServiceCharge::create([...$data, ...$translations]);

        return to_route('admin.service-charges.index')->with('success', __('admin.service_charges.created'));
    }

    public function edit(ServiceCharge $serviceCharge): Response
    {
        $serviceCharge->load('translations');

        return Inertia::render('Admin/ServiceCharges/Form', [
            'languages' => $this->registry->active()->values(),
            'charge' => $this->present($serviceCharge, includeTranslations: true),
        ]);
    }

    public function update(UpdateServiceChargeRequest $request, ServiceCharge $serviceCharge): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        $serviceCharge->update([...$data, ...$translations]);

        return to_route('admin.service-charges.index')->with('success', __('admin.service_charges.updated'));
    }

    /**
     * Deleting takes it off the price list and nothing else.
     *
     * Bills that already carry one keep it: the line copied the name and
     * the amount when it was added, and its foreign key is nulled rather
     * than cascading. A charge deleted in March cannot quietly remove
     * itself from February's takings.
     */
    public function destroy(ServiceCharge $serviceCharge): RedirectResponse
    {
        $serviceCharge->delete();

        return to_route('admin.service-charges.index')->with('success', __('admin.service_charges.deleted'));
    }

    protected function present(ServiceCharge $charge, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();

        $payload = [
            'id' => $charge->id,
            'amount_cents' => $charge->amount_cents,
            'is_active' => $charge->is_active,
            'sort_order' => $charge->sort_order,
            'name' => $charge->translate($this->registry->default()?->code)?->name ?? '',
            'missing_locales' => array_values(array_diff($activeCodes, $charge->translations->pluck('locale')->all())),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(fn (string $code) => [
                $code => ['name' => $charge->translate($code, withFallback: false)?->name ?? ''],
            ]);
        }

        return $payload;
    }
}
