<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PresentsPagination;
use App\Jobs\PrintCashierReceipt;
use App\Jobs\PrintOrderTicket;
use App\Models\PrintJob;
use App\Services\Printing\PrintQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "الطلبات غير المطبوعة" — the list of paper that should exist and does
 * not, and the only way anything from it reaches a printer.
 *
 * Reprinting is deliberately manual. When a dead printer comes back it must
 * not empty an hour of held tickets into the kitchen; somebody has to look
 * at each one and decide it is still wanted. That decision cannot be
 * automated because only a person knows whether table 6 is still sitting
 * there.
 */
class PrintJobController extends Controller
{
    use PresentsPagination;

    public function __construct(private PrintQueue $queue) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->input('status'), ['unprinted', 'printed', 'all'], true)
            ? $request->input('status')
            : 'unprinted';

        $jobs = PrintJob::query()
            ->with('order', 'printer', 'printedOnPrinter', 'requestedBy')
            ->when($status === 'unprinted', fn ($query) => $query->unprinted())
            ->when($status === 'printed', fn ($query) => $query->where('status', PrintJob::PRINTED))
            ->when($request->filled('printer_id'), fn ($query) => $query->where('printer_id', (int) $request->input('printer_id')))
            ->latest('id')
            // Was ->limit(200)->get(): every visit to this screen loaded two
            // hundred rows to render whatever fitted, and any job older than
            // the two-hundredth was simply invisible — on a busy service the
            // queue passes that in a couple of days, and the ticket somebody
            // came here to reprint was the one that had fallen off the end.
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Printers/Jobs', [
            'jobs' => $jobs->getCollection()->map(fn (PrintJob $job) => $this->present($job))->values(),
            'pagination' => $this->paginationMeta($jobs),
            'filters' => ['status' => $status, 'printer_id' => $request->input('printer_id')],
            'counts' => [
                'unprinted' => PrintJob::query()->unprinted()->count(),
                'needs_attention' => PrintJob::query()->needsAttention()->count(),
            ],
        ]);
    }

    /**
     * Sends one held ticket to its own printer, on purpose.
     *
     * A new row is created rather than the old one reset — see
     * PrintQueue::requeue(). The failure that put it here is part of the
     * night's record and erasing it would hide the fact that the printer
     * was ever down.
     */
    public function reprint(Request $request, PrintJob $printJob): RedirectResponse
    {
        if ($printJob->isPrinted()) {
            return back()->withErrors(['job' => __('printing.already_printed')]);
        }

        if (! $printJob->printer_id || ! $printJob->printer) {
            return back()->withErrors(['job' => __('printing.no_printer_to_reprint')]);
        }

        $copy = $this->queue->requeue($printJob, $request->user()?->id);

        $printJob->loadMissing('order');

        // Dispatched with the copy's id so the worker updates the new row,
        // and flagged as a human request so the recovery gate lets it
        // through — a held ticket is by definition older than the recovery
        // that held it, so without the flag the job re-held the copy and
        // the reprint button could never print the one thing it exists for.
        //
        // This was described in a comment here long before it was true: the
        // dispatch carried no flag, and there was nothing in the job that
        // could have told a reprint apart from an automatic retry.
        if ($printJob->kind === PrintJob::KIND_CASHIER) {
            PrintCashierReceipt::dispatch($printJob->order, $printJob->printer, $copy->id, true);
        } else {
            PrintOrderTicket::dispatch($printJob->order, $printJob->printer, $printJob->station, $copy->id, true);
        }

        return back()->with('success', __('printing.reprinted'));
    }

    /**
     * What the cashier's banner reads.
     *
     * Deliberately thin — a count and the first few lines. The till screen
     * needs to say "something did not print" and nothing more; the detail
     * lives on this controller's index, which is an admin screen.
     */
    public function alerts(): JsonResponse
    {
        $jobs = PrintJob::query()
            ->needsAttention()
            ->with('order')
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'count' => PrintJob::query()->needsAttention()->count(),
            'jobs' => $jobs->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'order_reference' => $job->order?->reference,
                'printer_name' => $job->printer_name,
                'station' => $job->station,
                'items_count' => $job->items_count,
                'status' => $job->status,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Takes one held ticket off the list without pretending it printed.
     *
     * Refuses anything already on paper: "cancel" would then be a lie about
     * a piece of paper somebody is holding.
     */
    public function cancel(PrintJob $printJob): RedirectResponse
    {
        if ($printJob->isPrinted()) {
            return back()->withErrors(['job' => __('printing.already_printed')]);
        }

        $printJob->update(['status' => PrintJob::CANCELLED]);

        return back()->with('success', __('printing.cancelled'));
    }

    /**
     * The same decision, taken once for everything currently listed.
     *
     * Scoped to the filter the admin is looking at rather than "all
     * unprinted": the screen showed them a set, and the button clears the
     * set they were shown. A printer filter that quietly cleared every
     * other station's backlog too would be a nasty surprise.
     */
    public function cancelAll(Request $request): RedirectResponse
    {
        $count = PrintJob::query()
            ->unprinted()
            ->when($request->filled('printer_id'), fn ($query) => $query->where('printer_id', (int) $request->input('printer_id')))
            ->update(['status' => PrintJob::CANCELLED]);

        return back()->with('success', __('printing.cancelled_many', ['count' => $count]));
    }

    private function present(PrintJob $job): array

    {
        return [
            'id' => $job->id,
            'reference' => $job->reference,
            'order_id' => $job->order_id,
            'order_reference' => $job->order?->reference,
            'printer_id' => $job->printer_id,
            'printer_name' => $job->printer_name,
            'station' => $job->station,
            'kind' => $job->kind,
            'status' => $job->status,
            'attempts' => $job->attempts,
            'items_count' => $job->items_count,
            'last_attempt_at' => $job->last_attempt_at?->toIso8601String(),
            'printed_at' => $job->printed_at?->toIso8601String(),
            'last_error' => $job->last_error,
            'printed_on' => $job->printedOnPrinter?->name,
            'requested_by' => $job->requestedBy?->name,
            'created_at' => $job->created_at?->toIso8601String(),
            'can_reprint' => ! $job->isPrinted() && $job->printer_id !== null,
            'can_cancel' => ! $job->isPrinted() && $job->status !== PrintJob::CANCELLED,
        ];
    }
}
