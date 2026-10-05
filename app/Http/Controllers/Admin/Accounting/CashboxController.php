<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Admin\Accounting\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use App\Models\AbsenceCategory;
use App\Models\CashMovement;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Services\Accounting\DayReportPrinter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The drawer, one day at a time.
 *
 * Nothing here computes anything. Every figure comes from Cashbox so the
 * screen, the closing, the dashboard and the reports cannot drift apart —
 * which is what "لا تسمح بوجود حسابات متناقضة" has to mean in practice.
 */
class CashboxController extends Controller
{
    use NormalisesMoney, ResolvesDateRange;

    public function __construct(
        private Cashbox $cashbox,
        private DayCloser $closer,
    ) {}

    public function index(Request $request): Response
    {
        $date = $this->singleDate($request);
        $day = $this->cashbox->day($date);
        $closing = $day['closing'];

        return Inertia::render('Admin/Accounting/Cashbox/Index', [
            'date' => $date,
            'today' => $this->cashbox->today(),
            'summary' => collect($day)->except('closing')->all(),

            // The day's cash, line by line, so a balance that looks wrong
            // can be traced without leaving the page.
            'movements' => $this->cashbox->movements($date),

            // What tomorrow opens on, so the closing form can tell the owner
            // how much to leave behind when emptying the drawer.
            'nextOpeningCents' => $this->cashbox->openingCents(Carbon::parse($date)->addDay()->toDateString()),

            'closing' => $closing ? [
                'id' => $closing->id,
                'opening_cents' => $closing->opening_cents,
                'collected_cents' => $closing->collected_cents,
                'expenses_cents' => $closing->expenses_cents,
                'cash_discounts_cents' => $closing->cash_discounts_cents,
                'staff_deductions_cents' => $closing->staff_deductions_cents,
                'expected_cents' => $closing->expected_cents,
                'counted_cents' => $closing->counted_cents,
                'difference_cents' => $closing->difference_cents,
                'difference_state' => $closing->differenceState(),
                'notes' => $closing->notes,
                'closed_by_name' => $closing->closedBy?->name,
                'closed_at' => $closing->closed_at?->toIso8601String(),
                'reopened_by_name' => $closing->reopenedBy?->name,
                'reopened_at' => $closing->reopened_at?->toIso8601String(),
                'is_closed' => $closing->isClosed(),
            ] : null,

            // The quick-add menu opens the expense and absence forms from
            // this page, so their option lists have to travel with it —
            // otherwise the first thing a manager does after seeing the
            // balance is navigate away to record what changed it.
            'expenseCategories' => ExpenseCategory::query()
                ->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'absenceCategories' => AbsenceCategory::query()
                ->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'default_deduction_cents']),
            'staffOptions' => User::query()->orderBy('name')->get(['id', 'name']),

            'can' => [
                'collect' => $request->user()?->can('cashbox.collect') ?? false,
                'close' => $request->user()?->can('cashbox.close') ?? false,
                'reopen' => $request->user()?->can('cashbox.reopen') ?? false,
            ],
        ]);
    }

    /**
     * A collection, a float top-up, a withdrawal — cash that moved for a
     * reason that is not a customer bill.
     */
    public function storeMovement(Request $request): RedirectResponse
    {
        $this->normaliseAmount('amount_cents');
        $this->defaultToToday('occurred_on');

        $data = $request->validate([
            'direction' => ['required', Rule::in([CashMovement::IN, CashMovement::OUT])],
            'type' => ['required', Rule::in([
                CashMovement::TYPE_COLLECTION,
                CashMovement::TYPE_FLOAT,
                CashMovement::TYPE_REFUND,
                CashMovement::TYPE_WITHDRAWAL,
            ])],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->cashbox->isClosed($data['occurred_on'])) {
            return back()->withErrors(['occurred_on' => __('accounting.day_is_closed')]);
        }

        CashMovement::create([...$data, 'recorded_by' => $request->user()?->id]);

        return back()->with('success', __('accounting.cash.recorded'));
    }

    /**
     * Only movements entered by hand can be deleted here. One created by an
     * expense belongs to that expense — removing it on its own would leave
     * the expense reporting money that the drawer says never left.
     */
    public function destroyMovement(CashMovement $cashMovement): RedirectResponse
    {
        if ($cashMovement->source_type !== null) {
            return back()->withErrors(['id' => __('accounting.cash.linked_cannot_delete')]);
        }

        if ($this->cashbox->isClosed($cashMovement->occurred_on->toDateString())) {
            return back()->withErrors(['id' => __('accounting.day_is_closed')]);
        }

        $cashMovement->delete();

        return back()->with('success', __('accounting.cash.deleted'));
    }

    /**
     * The day's figures on the till printer.
     *
     * Sent straight through rather than queued, and the result is reported
     * as it happened — see DayReportPrinter for why a retry an hour later
     * would be a different report rather than a rescue. A failure comes back
     * as `error` and not `success`, because a cashier told the report printed
     * will go looking for paper that is not there rather than for the
     * printer that is off.
     */
    public function print(Request $request, DayReportPrinter $printer): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);

        $sent = $printer->print($data['date'], $request->user());

        if ($sent === 0) {
            return back()->with('error', __('accounting.day_report_failed', [
                'error' => $printer->lastError(),
            ]));
        }

        return back()->with('success', __('accounting.day_report_printed'));
    }

    public function close(Request $request): RedirectResponse
    {
        $this->normaliseAmount('counted_cents', 'counted');

        $data = $request->validate([
            'date' => ['required', 'date'],
            // The only figure a human supplies: what is physically in the
            // drawer. Everything else is derived, per "لا تجعل المستخدم
            // يقوم بأي عملية حسابية بنفسه".
            'counted_cents' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->closer->close($data['date'], $data['counted_cents'], $data['notes'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('success', __('accounting.day_closed'));
    }

    public function reopen(Request $request): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);

        try {
            $this->closer->reopen($data['date'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('success', __('accounting.day_reopened'));
    }

    /** The history of signed-off days. */
    public function closings(Request $request): Response
    {
        $range = $this->dateRange($request);

        return Inertia::render('Admin/Accounting/Cashbox/Closings', [
            'filters' => $range,
            'closings' => DailyClosing::query()
                ->with('closedBy', 'reopenedBy')
                ->whereDate('business_date', '>=', $range['from'])->whereDate('business_date', '<=', $range['to'])
                ->orderByDesc('business_date')
                ->get()
                ->map(fn (DailyClosing $closing) => [
                    'id' => $closing->id,
                    'business_date' => $closing->business_date->toDateString(),
                    'opening_cents' => $closing->opening_cents,
                    'collected_cents' => $closing->collected_cents,
                    'expenses_cents' => $closing->expenses_cents,
                    'staff_deductions_cents' => $closing->staff_deductions_cents,
                    'expected_cents' => $closing->expected_cents,
                    'counted_cents' => $closing->counted_cents,
                    'difference_cents' => $closing->difference_cents,
                    'difference_state' => $closing->differenceState(),
                    'notes' => $closing->notes,
                    'closed_by_name' => $closing->closedBy?->name,
                    'closed_at' => $closing->closed_at?->toIso8601String(),
                    'reopened_by_name' => $closing->reopenedBy?->name,
                    'reopened_at' => $closing->reopened_at?->toIso8601String(),
                    'is_closed' => $closing->isClosed(),
                ]),
            'can' => ['reopen' => $request->user()?->can('cashbox.reopen') ?? false],
        ]);
    }
}
