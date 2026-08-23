<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "أكتب فقط: صيانة ثم حفظ" — so there is no create screen and no edit
 * screen, just a list you type into.
 */
class ExpenseCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Accounting/ExpenseCategories/Index', [
            'categories' => ExpenseCategory::query()
                ->withCount('expenses')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ExpenseCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'is_active' => $category->is_active,
                    'expenses_count' => $category->expenses_count,
                    // Sent rather than inferred from the count in the
                    // browser, so the rule lives in one place.
                    'can_delete' => $category->expenses_count === 0,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:expense_categories,name'],
        ]);

        ExpenseCategory::create([
            ...$data,
            'sort_order' => (int) ExpenseCategory::max('sort_order') + 1,
        ]);

        return back()->with('success', __('accounting.expense_category.created'));
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($expenseCategory)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $expenseCategory->update($data);

        return back()->with('success', __('accounting.expense_category.updated'));
    }

    /**
     * Refused while anything is filed under it. Deleting would either take
     * the expenses with it or leave them pointing at nothing — and either
     * way the month's totals would change without anyone asking for that.
     * Switching the category off is the answer for one that is no longer
     * used, and it keeps the history readable.
     */
    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        if (! $expenseCategory->isDeletable()) {
            return back()->withErrors(['name' => __('accounting.expense_category.in_use')]);
        }

        $expenseCategory->delete();

        return back()->with('success', __('accounting.expense_category.deleted'));
    }
}
