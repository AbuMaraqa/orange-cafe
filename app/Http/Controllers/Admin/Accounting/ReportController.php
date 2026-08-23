<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Admin\Accounting\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\StaffLedger;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One reports screen with three tabs, all sharing a date range and an
 * optional employee.
 *
 * Every figure is read back through the same models the entry screens
 * write, so a report and the screen it reports on cannot disagree. Nothing
 * here recomputes a closing — those are snapshots by design (see the
 * daily_closings migration) and are shown exactly as they were signed.
 */
class ReportController extends Controller
{
    use ResolvesDateRange;

    public function __construct(
        private Cashbox $cashbox,
        private StaffLedger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $range = $this->dateRange($request);
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $tab = in_array($request->input('tab'), ['expenses', 'absences', 'cashbox', 'orders', 'staff'], true)
            ? $request->input('tab')
            : 'expenses';

        return Inertia::render('Admin/Accounting/Reports/Index', [
            'filters' => [...$range, 'user_id' => $userId, 'tab' => $tab],
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'expenseCategories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name']),
            'absenceCategories' => AbsenceCategory::query()->orderBy('name')->get(['id', 'name']),

            'expenses' => $this->expenseReport($range, $userId),
            'absences' => $this->absenceReport($range, $userId),
            'cashbox' => $this->cashboxReport($range),
            'orders' => $this->ordersReport($range),
            // The individual lines behind the cash box totals, so "where did
            // the 500 go" is answerable without opening each day in turn.
            'ledger' => $this->ledgerReport($range),
            'staffReport' => $userId ? $this->staffReport($userId) : null,
        ]);
    }

    /**
     * The same rows the screen shows, as CSV.
     *
     * A BOM is written first: Excel on Windows reads a BOM-less UTF-8 file
     * in the system codepage, and every Arabic name in the export comes out
     * as mojibake — which looks like the app corrupted the data rather than
     * like a spreadsheet setting.
     */
    /**
     * The report as a PDF, with a running header and footer.
     *
     * Rendered through mPDF rather than the browser's own print because the
     * two are not the same document. Browser print gives whoever pressed it
     * their machine's margins, their headers, their idea of where a page
     * breaks — and no restaurant name on page three of four. This produces
     * one file that looks the same wherever it is opened, carries the
     * brand and the period on every page, numbers the pages so a stapled
     * stack can be checked, and shapes Arabic correctly (PdfService already
     * solved that for invoices; there is no reason to solve it twice).
     *
     * The figures come from the same private builders the screen uses, so
     * paper and screen cannot disagree about money.
     */
    public function pdf(Request $request, PdfService $pdf, RestaurantSettings $branding): HttpResponse
    {
        $range = $this->dateRange($request);
        $tab = in_array($request->input('tab'), ['orders', 'cashbox'], true)
            ? $request->input('tab')
            : 'orders';

        $symbol = $branding->currency_symbol;

        $document = $pdf->fromView('pdf.report', [
            'tab' => $tab,
            'branding' => $branding,
            'title' => $this->uiLabels('accounting')['report_'.$tab] ?? $tab,
            // With the time when one was chosen: a sheet headed "23/08 —
            // 24/08" for a range that was really 10:00 until 03:00 cannot
            // be checked against the screen it came from.
            'from' => $range['from'].($range['from_time'] ? ' '.$range['from_time'] : ''),
            'to' => $range['to'].($range['to_time'] ? ' '.$range['to_time'] : ''),
            'printedAt' => now()->format('Y-m-d H:i'),
            'labels' => $this->uiLabels('accounting'),
            'orders' => $tab === 'orders' ? $this->ordersReport($range) : ['totals' => [], 'by_day' => [], 'rows' => []],
            'cashbox' => $tab === 'cashbox' ? $this->cashboxReport($range) : ['days' => []],
            'ledger' => $tab === 'cashbox' ? $this->ledgerReport($range) : [],
            // Passed as closures so the template never does arithmetic on
            // money — the only place cents become a string is here.
            'money' => fn (?int $cents) => $symbol.number_format(($cents ?? 0) / 100, 2),
            'datetime' => fn (?string $iso) => $iso ? Carbon::parse($iso)->format('Y-m-d H:i') : '—',
        ], 'rtl');

        $filename = "{$tab}-{$range['from']}-{$range['to']}.pdf";

        return response($document->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            // inline: the point is to look at it, and every browser's PDF
            // viewer already has a print button.
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * One section of the interface's own translations.
     *
     * Read out of resources/js/lang/{locale}.json — the file vue-i18n uses
     * — rather than lang/{locale}/accounting.php, because the screen's
     * wording lives there and a PDF that restates it in a second file is a
     * second wording. They would agree on the day they were written and
     * drift on the first day somebody renames a column.
     *
     * Missing keys resolve to the key itself, which is ugly on paper and
     * therefore gets noticed, rather than an empty cell that does not.
     *
     * @return array<string, string>
     */
    private function uiLabels(string $section): array
    {
        $path = resource_path('js/lang/'.app()->getLocale().'.json');

        if (! is_file($path)) {
            $path = resource_path('js/lang/ar.json');
        }

        $all = json_decode((string) @file_get_contents($path), true) ?: [];

        return $all[$section] ?? [];
    }

    public function export(Request $request): StreamedResponse
    {
        $range = $this->dateRange($request);
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $tab = $request->input('tab', 'expenses');

        [$headers, $rows] = match ($tab) {
            'absences' => $this->absenceCsv($range, $userId),
            'cashbox' => $this->cashboxCsv($range),
            default => $this->expenseCsv($range, $userId),
        };

        $filename = "{$tab}-{$range['from']}-{$range['to']}.csv";

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    private function expenseReport(array $range, ?int $userId): array
    {
        $expenses = Expense::query()
            ->with('category', 'user')
            ->between($range['from'], $range['to'])
            ->when($userId, fn ($query, $id) => $query->where('user_id', $id))
            ->orderByDesc('spent_on')
            ->get();

        return [
            'total_cents' => (int) $expenses->sum('amount_cents'),
            'cash_cents' => (int) $expenses->where('paid_from_cash', true)->sum('amount_cents'),
            'count' => $expenses->count(),
            'by_category' => $expenses->groupBy('expense_category_id')
                ->map(fn ($group) => [
                    'name' => $group->first()->category?->name,
                    'total_cents' => (int) $group->sum('amount_cents'),
                    'count' => $group->count(),
                ])->sortByDesc('total_cents')->values(),
            'by_day' => $expenses->groupBy(fn (Expense $expense) => $expense->spent_on->toDateString())
                ->map(fn ($group, $day) => [
                    'date' => $day,
                    'total_cents' => (int) $group->sum('amount_cents'),
                ])->sortBy('date')->values(),
            'rows' => $expenses->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'date' => $expense->spent_on->toDateString(),
                'category' => $expense->category?->name,
                'staff' => $expense->user?->name,
                'amount_cents' => $expense->amount_cents,
                'reason' => $expense->reason,
                'from_cash' => $expense->paid_from_cash,
            ]),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    private function absenceReport(array $range, ?int $userId): array
    {
        $absences = Absence::query()
            ->with('category', 'user')
            ->between($range['from'], $range['to'])
            ->when($userId, fn ($query, $id) => $query->where('user_id', $id))
            ->orderByDesc('absent_on')
            ->get();

        return [
            'count' => $absences->count(),
            'deductions_cents' => (int) $absences->sum('deduction_cents'),
            'by_employee' => $absences->groupBy('user_id')
                ->map(fn ($group) => [
                    'name' => $group->first()->user?->name,
                    'count' => $group->count(),
                    'deductions_cents' => (int) $group->sum('deduction_cents'),
                ])->sortByDesc('deductions_cents')->values(),
            'by_category' => $absences->groupBy('absence_category_id')
                ->map(fn ($group) => [
                    'name' => $group->first()->category?->name,
                    'count' => $group->count(),
                    'deductions_cents' => (int) $group->sum('deduction_cents'),
                ])->sortByDesc('count')->values(),
            'rows' => $absences->map(fn (Absence $absence) => [
                'id' => $absence->id,
                'date' => $absence->absent_on->toDateString(),
                'staff' => $absence->user?->name,
                'category' => $absence->category?->name,
                'deduction_cents' => $absence->deduction_cents,
                'reason' => $absence->reason,
            ]),
        ];
    }

    /**
     * Day by day across the range.
     *
     * A closed day reports the figures that were signed off; an open one is
     * computed live. They are labelled differently on screen for exactly
     * that reason — an open day's numbers can still change.
     *
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    /**
     * The day's trade: how many orders, what they came to, what was taken.
     *
     * Separate from the cash box on purpose. The cash box answers "what is
     * in the drawer"; this answers "what did we sell", and the two are not
     * the same number — a bill settled by card, or a table still open at
     * close, is trade that never touches the drawer.
     *
     * @return array<string, mixed>
     */
    private function ordersReport(array $range): array
    {
        // On the moment, not the date. A payment is the one figure here
        // with a clock reading, and it is the whole reason the range
        // carries a time: a night that ends at 3am is one night, not two
        // days, and reading it by date puts its last three hours in a
        // separate report.
        $orders = Order::query()
            ->whereBetween('paid_at', [$range['from_at'], $range['to_at']])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('paid_at')
            ->with('table', 'paidBy')
            ->withCount('items')
            ->orderBy('paid_at')
            ->get();

        $rows = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'reference' => $order->reference,
            'table' => $order->table?->label ?? $order->table_number,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items_count' => $order->items_count,
            'gross_cents' => $order->total_cents + ($order->discount_cents ?? 0),
            'discount_cents' => $order->discount_cents ?? 0,
            'total_cents' => $order->total_cents,
            'collected_cents' => $order->collectedCents(),
            'by' => $order->paidBy?->name,
        ])->values()->all();

        $collected = array_sum(array_column($rows, 'collected_cents'));
        $count = count($rows);

        // Per day as well as in total: a range report that only totals is a
        // number nobody can check against a till roll.
        $byDay = collect($rows)
            ->groupBy(fn (array $row) => substr((string) $row['paid_at'], 0, 10))
            ->map(fn ($day, $date) => [
                'date' => $date,
                'orders_count' => $day->count(),
                'gross_cents' => (int) $day->sum('gross_cents'),
                'discount_cents' => (int) $day->sum('discount_cents'),
                'collected_cents' => (int) $day->sum('collected_cents'),
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'by_day' => $byDay,
            'totals' => [
                'orders_count' => $count,
                'gross_cents' => (int) array_sum(array_column($rows, 'gross_cents')),
                'discount_cents' => (int) array_sum(array_column($rows, 'discount_cents')),
                'collected_cents' => (int) $collected,
                // The number an owner actually asks for after "how much did
                // we take": what an average table was worth.
                'average_cents' => $count > 0 ? (int) round($collected / $count) : 0,
            ],
        ];
    }

    /**
     * Every movement in the range, day by day, with each day's opening and
     * closing balance around it.
     *
     * This is the detailed answer to "I took 500 out — where did it come
     * from and what was there before". The summary above says the balance
     * moved; this says which bill, which expense, which withdrawal, in the
     * order they happened.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledgerReport(array $range): array
    {
        $days = [];
        $cursor = Carbon::parse($range['from']);
        $end = Carbon::parse($range['to']);

        // Same bound as cashboxReport(): a mistyped range must not walk
        // years of days and time the page out.
        $limit = 190;

        while ($cursor->lte($end) && count($days) < $limit) {
            $date = $cursor->toDateString();
            $movements = $this->cashbox->movements($date);

            // A day with no movement at all is noise in a range report —
            // the summary tab already shows it as a flat line.
            if ($movements !== []) {
                $day = $this->cashbox->day($date);

                $days[] = [
                    'date' => $date,
                    'opening_cents' => $day['opening_cents'],
                    'balance_cents' => $day['balance_cents'],
                    'in_cents' => $day['total_in_cents'],
                    'out_cents' => $day['total_out_cents'],
                    'movements' => $movements,
                ];
            }

            $cursor->addDay();
        }

        return $days;
    }

    private function cashboxReport(array $range): array
    {
        $closings = DailyClosing::query()
            ->whereDate('business_date', '>=', $range['from'])->whereDate('business_date', '<=', $range['to'])
            ->get()
            ->keyBy(fn (DailyClosing $closing) => $closing->business_date->toDateString());

        $days = [];
        $cursor = Carbon::parse($range['from']);
        $end = Carbon::parse($range['to']);

        // Bounded so a mistyped range ("2020 إلى 2026") cannot walk two
        // thousand days and time the page out.
        $limit = 190;

        while ($cursor->lte($end) && count($days) < $limit) {
            $date = $cursor->toDateString();
            $closing = $closings->get($date);

            if ($closing && $closing->isClosed()) {
                $days[] = [
                    'date' => $date,
                    'opening_cents' => $closing->opening_cents,
                    'collected_cents' => $closing->collected_cents,
                    'expenses_cents' => $closing->expenses_cents,
                    'staff_deductions_cents' => $closing->staff_deductions_cents,
                    'balance_cents' => $closing->expected_cents,
                    'counted_cents' => $closing->counted_cents,
                    'difference_cents' => $closing->difference_cents,
                    'difference_state' => $closing->differenceState(),
                    'is_closed' => true,
                ];
            } else {
                $day = $this->cashbox->day($date);

                $days[] = [
                    'date' => $date,
                    'opening_cents' => $day['opening_cents'],
                    'collected_cents' => $day['total_in_cents'],
                    'expenses_cents' => $day['total_out_cents'],
                    'staff_deductions_cents' => $day['staff_deductions_cents'],
                    'balance_cents' => $day['balance_cents'],
                    'counted_cents' => null,
                    'difference_cents' => null,
                    'difference_state' => null,
                    'is_closed' => false,
                ];
            }

            $cursor->addDay();
        }

        return [
            'days' => $days,
            'truncated' => $cursor->lte($end),
            'totals' => [
                'collected_cents' => array_sum(array_column($days, 'collected_cents')),
                'expenses_cents' => array_sum(array_column($days, 'expenses_cents')),
                'staff_deductions_cents' => array_sum(array_column($days, 'staff_deductions_cents')),
                'difference_cents' => array_sum(array_filter(array_column($days, 'difference_cents'), fn ($value) => $value !== null)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffReport(int $userId): array
    {
        $user = User::findOrFail($userId);

        return [
            'name' => $user->name,
            'summary' => $this->ledger->summary($user),
            'entries' => $this->ledger->statement($user),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{0: array<int, string>, 1: iterable}
     */
    private function expenseCsv(array $range, ?int $userId): array
    {
        $report = $this->expenseReport($range, $userId);

        return [
            ['التاريخ', 'الفئة', 'الموظف', 'المبلغ', 'السبب', 'من الصندوق'],
            $report['rows']->map(fn ($row) => [
                $row['date'],
                $row['category'],
                $row['staff'] ?? '',
                number_format($row['amount_cents'] / 100, 2, '.', ''),
                $row['reason'],
                $row['from_cash'] ? 'نعم' : 'لا',
            ]),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{0: array<int, string>, 1: iterable}
     */
    private function absenceCsv(array $range, ?int $userId): array
    {
        $report = $this->absenceReport($range, $userId);

        return [
            ['التاريخ', 'الموظف', 'الفئة', 'قيمة الخصم', 'السبب'],
            $report['rows']->map(fn ($row) => [
                $row['date'],
                $row['staff'],
                $row['category'],
                number_format($row['deduction_cents'] / 100, 2, '.', ''),
                $row['reason'] ?? '',
            ]),
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{0: array<int, string>, 1: iterable}
     */
    private function cashboxCsv(array $range): array
    {
        $report = $this->cashboxReport($range);

        $money = fn (?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, '.', '');

        return [
            ['التاريخ', 'رصيد البداية', 'التحصيل', 'المصروفات', 'خصومات الموظفين', 'الرصيد المتوقع', 'الفعلي', 'الفرق', 'مغلق'],
            collect($report['days'])->map(fn ($day) => [
                $day['date'],
                $money($day['opening_cents']),
                $money($day['collected_cents']),
                $money($day['expenses_cents']),
                $money($day['staff_deductions_cents']),
                $money($day['balance_cents']),
                $money($day['counted_cents']),
                $money($day['difference_cents']),
                $day['is_closed'] ? 'نعم' : 'لا',
            ]),
        ];
    }
}
