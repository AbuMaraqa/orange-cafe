<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use App\Models\AbsenceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AbsenceCategoryController extends Controller
{
    use NormalisesMoney;

    public function index(): Response
    {
        return Inertia::render('Admin/Accounting/AbsenceCategories/Index', [
            'categories' => AbsenceCategory::query()
                ->withCount('absences')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (AbsenceCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'default_deduction_cents' => $category->default_deduction_cents,
                    'affects_pay' => $category->affects_pay,
                    'is_active' => $category->is_active,
                    'absences_count' => $category->absences_count,
                    'can_delete' => $category->absences_count === 0,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        AbsenceCategory::create([
            ...$data,
            'sort_order' => (int) AbsenceCategory::max('sort_order') + 1,
        ]);

        return back()->with('success', __('accounting.absence_category.created'));
    }

    public function update(Request $request, AbsenceCategory $absenceCategory): RedirectResponse
    {
        $absenceCategory->update($this->validated($request, $absenceCategory));

        return back()->with('success', __('accounting.absence_category.updated'));
    }

    public function destroy(AbsenceCategory $absenceCategory): RedirectResponse
    {
        if (! $absenceCategory->isDeletable()) {
            return back()->withErrors(['name' => __('accounting.absence_category.in_use')]);
        }

        $absenceCategory->delete();

        return back()->with('success', __('accounting.absence_category.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AbsenceCategory $ignore = null): array
    {
        // The form types a currency amount; the column holds cents.
        if ($request->has('default_deduction')) {
            $raw = $request->input('default_deduction');
            $request->merge([
                'default_deduction_cents' => $raw === null || $raw === '' ? 0 : (int) round(((float) $raw) * 100),
            ]);
        }

        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                $ignore
                    ? Rule::unique('absence_categories', 'name')->ignore($ignore)
                    : Rule::unique('absence_categories', 'name'),
            ],
            // A suggestion the absence form pre-fills, never a rule: whether
            // a given absence costs anything is decided case by case.
            'default_deduction_cents' => ['sometimes', 'integer', 'min:0'],
            'affects_pay' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
