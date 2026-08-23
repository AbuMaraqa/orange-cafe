<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Admin\Accounting\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\StoreExpenseRequest;
use App\Http\Requests\Admin\Accounting\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\ExpenseRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    use ResolvesDateRange;

    public function __construct(
        private ExpenseRecorder $recorder,
        private Cashbox $cashbox,
    ) {}

    public function index(Request $request): Response
    {
        $range = $this->dateRange($request);
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $search = trim((string) $request->input('search', ''));

        $expenses = Expense::query()
            ->with('category', 'user', 'recordedBy')
            ->between($range['from'], $range['to'])
            ->when($categoryId, fn ($query, $id) => $query->where('expense_category_id', $id))
            ->when($userId, fn ($query, $id) => $query->where('user_id', $id))
            ->when($search, fn ($query, $term) => $query->where(function ($query) use ($term) {
                $query->where('reason', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            }))
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();

        $today = Carbon::now();

        return Inertia::render('Admin/Accounting/Expenses/Index', [
            'expenses' => $expenses->map(fn (Expense $expense) => $this->present($expense)),
            'filters' => [...$range, 'category_id' => $categoryId, 'user_id' => $userId, 'search' => $search],
            'categories' => $this->categoryOptions(),
            'staff' => $this->staffOptions(),

            // The totals the filtered list adds up to, plus the three fixed
            // windows the dashboard asks about. Computed here rather than in
            // the browser so a filtered view still reports the true day and
            // month, not the sum of whatever happens to be on screen.
            'stats' => [
                'range_cents' => (int) $expenses->sum('amount_cents'),
                'range_count' => $expenses->count(),
                'today_cents' => (int) Expense::query()->onDate($today->toDateString())->sum('amount_cents'),
                'week_cents' => (int) Expense::query()
                    ->between($today->copy()->startOfWeek()->toDateString(), $today->copy()->endOfWeek()->toDateString())
                    ->sum('amount_cents'),
                'month_cents' => (int) Expense::query()
                    ->between($today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString())
                    ->sum('amount_cents'),
            ],

            'byCategory' => $expenses->groupBy('expense_category_id')
                ->map(fn ($group) => [
                    'id' => $group->first()->expense_category_id,
                    'name' => $group->first()->category?->name,
                    'total_cents' => (int) $group->sum('amount_cents'),
                    'count' => $group->count(),
                ])
                ->sortByDesc('total_cents')
                ->values(),

            'today' => $today->toDateString(),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($guard = $this->refuseIfDayClosed($data['spent_on'])) {
            return $guard;
        }

        $this->recorder->create($data, $request->user()?->id);

        return back()->with('success', __('accounting.expense.created'));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $data = $request->validated();

        // Both dates matter: moving an expense out of a closed day changes
        // that day's figures just as much as moving one into it.
        foreach ([$expense->spent_on->toDateString(), $data['spent_on']] as $date) {
            if ($guard = $this->refuseIfDayClosed($date)) {
                return $guard;
            }
        }

        $this->recorder->update($expense, $data);

        return back()->with('success', __('accounting.expense.updated'));
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        if ($guard = $this->refuseIfDayClosed($expense->spent_on->toDateString())) {
            return $guard;
        }

        $this->recorder->delete($expense);

        return back()->with('success', __('accounting.expense.deleted'));
    }

    /**
     * A closed day is a signed statement about what the drawer held. Letting
     * an expense be added to it afterwards would make the stored figures
     * disagree with the records behind them, with nothing on screen to say
     * which is right — so the day has to be reopened first, which is itself
     * recorded.
     */
    private function refuseIfDayClosed(string $date): ?RedirectResponse
    {
        if (! $this->cashbox->isClosed($date)) {
            return null;
        }

        return back()->withErrors(['spent_on' => __('accounting.day_is_closed')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'category_id' => $expense->expense_category_id,
            'category_name' => $expense->category?->name,
            'user_id' => $expense->user_id,
            'user_name' => $expense->user?->name,
            'amount_cents' => $expense->amount_cents,
            'spent_on' => $expense->spent_on->toDateString(),
            'reason' => $expense->reason,
            'notes' => $expense->notes,
            'paid_from_cash' => $expense->paid_from_cash,
            'recorded_by_name' => $expense->recordedBy?->name,
            'created_at' => $expense->created_at?->toIso8601String(),
            'day_is_closed' => $this->cashbox->isClosed($expense->spent_on->toDateString()),
        ];
    }

    private function categoryOptions()
    {
        return ExpenseCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Everyone who can have money recorded against them — which is every
     * user, because "employee" here is just an account with a role.
     */
    private function staffOptions()
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }
}
