<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\StaffLedgerEntry;
use App\Models\User;
use App\Services\Accounting\StaffLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * كشف حساب الموظف — everything the cafe has paid to or charged against one
 * person, in the order it happened.
 *
 * The statement is built from the ledger and nothing else. Absences and
 * expenses write to the ledger when they are saved (see AbsenceRecorder and
 * ExpenseRecorder), so there is one list to read rather than three to merge
 * and reconcile — and no way for the page to show a different total from
 * the one the reports use.
 */
class StaffAccountController extends Controller
{
    use NormalisesMoney;

    public function __construct(private StaffLedger $ledger) {}

    /** Everyone, with what they owe, so the outstanding ones stand out. */
    public function index(): Response
    {
        $balances = StaffLedgerEntry::query()
            ->selectRaw('user_id, sum(amount_cents) as balance')
            ->groupBy('user_id')
            ->pluck('balance', 'user_id');

        return Inertia::render('Admin/Accounting/Staff/Index', [
            // Someone deleted while they still owe, or are owed, stays on
            // the list until it's settled — deleting the account must not
            // quietly write off the money.
            'staff' => User::withTrashed()
                ->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->orWhereIn('id', $balances->filter()->keys()))
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'roles' => $user->getRoleNames(),
                    'balance_cents' => (int) ($balances[$user->id] ?? 0),
                    'deleted' => $user->trashed(),
                ]),
        ]);
    }

    public function show(User $user): Response
    {
        return Inertia::render('Admin/Accounting/Staff/Show', [
            'staffMember' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'roles' => $user->getRoleNames(),
            ],
            'summary' => $this->ledger->summary($user),
            'entries' => $this->ledger->statement($user),

            // So the quick-add buttons on this page can open a form with the
            // employee already chosen — "فلا أريد أن يطلب مني النظام اختيار
            // الموظف مرة أخرى".
            'expenseCategories' => ExpenseCategory::query()
                ->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'absenceCategories' => AbsenceCategory::query()
                ->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'default_deduction_cents']),

            'recentExpenses' => Expense::query()
                ->where('user_id', $user->id)
                ->with('category')
                ->orderByDesc('spent_on')->limit(20)->get()
                ->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'spent_on' => $expense->spent_on->toDateString(),
                    'category_name' => $expense->category?->name,
                    'amount_cents' => $expense->amount_cents,
                    'reason' => $expense->reason,
                ]),

            'recentAbsences' => Absence::query()
                ->where('user_id', $user->id)
                ->with('category')
                ->orderByDesc('absent_on')->limit(20)->get()
                ->map(fn (Absence $absence) => [
                    'id' => $absence->id,
                    'absent_on' => $absence->absent_on->toDateString(),
                    'category_name' => $absence->category?->name,
                    'deduction_cents' => $absence->deduction_cents,
                    'reason' => $absence->reason,
                ]),

            'can' => [
                'post' => request()->user()?->can('staff_accounts.post') ?? false,
            ],
        ]);
    }

    /**
     * A movement with no originating record — a salary, a repayment, a
     * correction. Advances and deductions are not posted here: those come
     * from an expense or an absence so they stay linked to what caused them.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->can('staff_accounts.post'), 403);

        $this->normaliseAmount('amount_cents');
        $this->defaultToToday('occurred_on');

        $data = $request->validate([
            'type' => ['required', Rule::in([
                StaffLedgerEntry::TYPE_SALARY,
                StaffLedgerEntry::TYPE_PAYMENT,
                StaffLedgerEntry::TYPE_OTHER_DEDUCTION,
                StaffLedgerEntry::TYPE_ADJUSTMENT,
            ])],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ledger->post(
            user: $user,
            type: $data['type'],
            amountCents: $data['amount_cents'],
            occurredOn: $data['occurred_on'],
            description: $data['description'],
            notes: $data['notes'] ?? null,
            recordedBy: $request->user()?->id,
        );

        return back()->with('success', __('accounting.staff.posted'));
    }

    /**
     * Only free-standing entries can be removed here. One belonging to an
     * absence or an expense is deleted by deleting that record, which keeps
     * the two from ever disagreeing.
     */
    public function destroy(Request $request, User $user, StaffLedgerEntry $entry): RedirectResponse
    {
        abort_unless($request->user()?->can('staff_accounts.post'), 403);
        abort_unless($entry->user_id === $user->id, 404);

        if ($entry->source_type !== null) {
            return back()->withErrors(['id' => __('accounting.staff.linked_cannot_delete')]);
        }

        $entry->delete();

        return back()->with('success', __('accounting.staff.deleted'));
    }
}
