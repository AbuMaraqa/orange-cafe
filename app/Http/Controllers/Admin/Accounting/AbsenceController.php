<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Admin\Accounting\Concerns\ResolvesDateRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\StoreAbsenceRequest;
use App\Http\Requests\Admin\Accounting\UpdateAbsenceRequest;
use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\User;
use App\Services\Accounting\AbsenceRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AbsenceController extends Controller
{
    use ResolvesDateRange;

    public function __construct(private AbsenceRecorder $recorder) {}

    public function index(Request $request): Response
    {
        $range = $this->dateRange($request);
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;

        $absences = Absence::query()
            ->with('user', 'category', 'recordedBy')
            ->between($range['from'], $range['to'])
            ->when($categoryId, fn ($query, $id) => $query->where('absence_category_id', $id))
            ->when($userId, fn ($query, $id) => $query->where('user_id', $id))
            ->orderByDesc('absent_on')
            ->orderByDesc('id')
            ->get();

        $today = Carbon::now()->toDateString();

        return Inertia::render('Admin/Accounting/Absences/Index', [
            'absences' => $absences->map(fn (Absence $absence) => [
                'id' => $absence->id,
                'user_id' => $absence->user_id,
                'user_name' => $absence->user?->name,
                'category_id' => $absence->absence_category_id,
                'category_name' => $absence->category?->name,
                'deduction_cents' => $absence->deduction_cents,
                'absent_on' => $absence->absent_on->toDateString(),
                'reason' => $absence->reason,
                'notes' => $absence->notes,
                'recorded_by_name' => $absence->recordedBy?->name,
            ]),
            'filters' => [...$range, 'category_id' => $categoryId, 'user_id' => $userId],
            'categories' => AbsenceCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'default_deduction_cents']),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'range_count' => $absences->count(),
                'range_deductions_cents' => (int) $absences->sum('deduction_cents'),
                'today_count' => Absence::query()->onDate($today)->count(),
            ],
            'byEmployee' => $absences->groupBy('user_id')
                ->map(fn ($group) => [
                    'id' => $group->first()->user_id,
                    'name' => $group->first()->user?->name,
                    'count' => $group->count(),
                    'deductions_cents' => (int) $group->sum('deduction_cents'),
                ])
                ->sortByDesc('deductions_cents')
                ->values(),
            'today' => $today,
        ]);
    }

    public function store(StoreAbsenceRequest $request): RedirectResponse
    {
        $this->recorder->create($request->validated(), $request->user()?->id);

        return back()->with('success', __('accounting.absence.created'));
    }

    public function update(UpdateAbsenceRequest $request, Absence $absence): RedirectResponse
    {
        $this->recorder->update($absence, $request->validated());

        return back()->with('success', __('accounting.absence.updated'));
    }

    public function destroy(Absence $absence): RedirectResponse
    {
        $this->recorder->delete($absence);

        return back()->with('success', __('accounting.absence.deleted'));
    }
}
