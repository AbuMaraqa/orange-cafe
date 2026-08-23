<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing that creates or closes a print job.
 *
 * Everything about not printing twice lives here. `claim()` is written so
 * that two workers racing for the same ticket cannot both win, and
 * `reserve()` so that a repeat click, a lifecycle hook firing twice, or a
 * retry after a crash all resolve to the row that already exists rather
 * than a second piece of paper. A kitchen that receives the same order
 * twice cooks it twice, so this is the part that has to be right even when
 * everything else is on fire.
 */
class PrintQueue
{
    /**
     * Finds or creates the job for one ticket.
     *
     * Returns null when the ticket has already been printed — which is not
     * an error and not something to report: it is the system correctly
     * declining to print a second copy.
     *
     * `$requestedByHuman` is the exception, and the reason this parameter
     * exists at all. Every guard in this class is built to stop a *repeat*
     * from becoming a second piece of paper: a double click, a lifecycle
     * hook firing twice, a worker that printed and died before recording
     * it. None of those is a person deciding they want another copy — and
     * for a long time the two were indistinguishable here, so pressing
     * print on an order whose ticket had already come out, or whose printer
     * had been switched off long enough to spend its attempts, did nothing
     * whatsoever. No paper, no new row, no error: the guard against
     * accidents was silently swallowing the deliberate case as well.
     *
     * So a human request turns both of those dead ends into a fresh copy.
     * Note it is a *new row* (see requeue) and not a reset of the old one:
     * the first attempt genuinely happened, and its failure is the evidence
     * that the printer was down.
     */
    public function reserve(
        Order $order,
        Printer $printer,
        string $station,
        string $kind,
        int $itemsCount = 0,
        int $copy = 1,
        ?int $requestedBy = null,
        bool $requestedByHuman = false,
        /**
         * Distinguishes one piece of paper from another that is otherwise
         * identical — see PrintJob::keyFor(). Used for a round of items
         * added to an order that has already printed.
         */
        ?string $scope = null,
    ): ?PrintJob {
        $key = PrintJob::keyFor($order->id, $printer->id, $station, $kind, $copy, $scope);

        $existing = PrintJob::query()->where('idempotency_key', $key)->first();

        if ($existing) {
            // The two states a person can be stuck behind: it already
            // printed, or claim() will refuse it because the attempts are
            // spent. Both mean "pressing the button achieves nothing"
            // unless somebody asked on purpose.
            $settled = $existing->isPrinted() || $existing->attempts >= PrintJob::MAX_ATTEMPTS;

            if ($requestedByHuman && $settled) {
                return $this->requeue($existing, $requestedBy);
            }

            // Already on paper. Handing back null rather than the row makes
            // it impossible for a caller to "just send it anyway".
            return $existing->isPrinted() ? null : $existing;
        }

        try {
            return PrintJob::create([
                'reference' => $this->reference(),
                'order_id' => $order->id,
                'printer_id' => $printer->id,
                'station' => $station,
                'printer_name' => $printer->name,
                'kind' => $kind,
                'status' => PrintJob::PENDING,
                'items_count' => $itemsCount,
                'requested_by' => $requestedBy,
                'idempotency_key' => $key,
            ]);
        } catch (QueryException $e) {
            // Lost the race: another process inserted the same key between
            // the read above and this write. The unique index is what makes
            // that safe — the loser simply adopts the winner's row instead
            // of creating a duplicate ticket.
            $winner = PrintJob::query()->where('idempotency_key', $key)->first();

            if (! $winner) {
                throw $e;
            }

            return $winner->isPrinted() ? null : $winner;
        }
    }

    /**
     * Records a ticket that has no printer to go to at all.
     *
     * A station with no active printer configured used to produce a log
     * line and nothing else — so an order whose drinks nobody was ever told
     * to make looked, from every screen in the app, exactly like an order
     * that printed fine. This gives it a row, which is what puts it on the
     * unprinted list where somebody will see it.
     *
     * `printer_id` is null and the status starts terminal: there is nothing
     * to retry against, so pretending otherwise would leave it cycling
     * forever.
     */
    public function reserveUnroutable(Order $order, string $station, string $kind, int $itemsCount = 0, ?string $scope = null): ?PrintJob
    {
        // Printer id 0 stands in for "no printer", so the key is still
        // stable and a second attempt at the same order does not create a
        // second row.
        //
        // $scope carries the same meaning as it does in reserve(): a later
        // round is different paper. Without it a table ordering twice into a
        // station that has no printer left one record for the first round
        // and nothing at all for the second — the one case where a record is
        // the only thing there is, because no paper was ever going to come
        // out.
        $key = PrintJob::keyFor($order->id, 0, $station, $kind, scope: $scope);

        if (PrintJob::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return PrintJob::create([
                'reference' => $this->reference(),
                'order_id' => $order->id,
                'printer_id' => null,
                'station' => $station,
                'printer_name' => __('printing.no_printer'),
                'kind' => $kind,
                'status' => PrintJob::FAILED,
                'items_count' => $itemsCount,
                'last_error' => __('printing.no_printer_for_station', ['station' => $station]),
                'idempotency_key' => $key,
            ]);
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Takes ownership of a job immediately before sending.
     *
     * The update is conditional on the row still not being printed, and the
     * database decides who wins. Reading the status and then writing it
     * would leave a gap in which a second worker reads the same "pending"
     * and both proceed to print.
     */
    public function claim(PrintJob $job): bool
    {
        $claimed = PrintJob::query()
            ->whereKey($job->getKey())
            ->whereIn('status', [PrintJob::PENDING, PrintJob::FAILED, PrintJob::PRINTER_OFFLINE])
            // Exhausted is exhausted. Without this the row stays claimable
            // forever: a settled PRINTER_OFFLINE job would be picked up by
            // any late retry and its attempt count would climb past the
            // limit, which is exactly the endless hammering the limit
            // exists to prevent. A deliberate reprint is not affected — it
            // creates a new row (see requeue()).
            ->where('attempts', '<', PrintJob::MAX_ATTEMPTS)
            ->update([
                'status' => PrintJob::PRINTING,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $job->refresh();

        return true;
    }

    /** Paper came out. Terminal: nothing retries a printed job. */
    public function markPrinted(PrintJob $job, ?Printer $printedOn = null): void
    {
        $job->forceFill([
            'status' => PrintJob::PRINTED,
            'printed_at' => now(),
            'last_error' => null,
            // Only recorded when it was somewhere other than intended, so
            // the column answers "where is the paper actually?".
            'printed_on_printer_id' => $printedOn && $printedOn->id !== $job->printer_id
                ? $printedOn->id
                : null,
        ])->save();
    }

    /**
     * The attempt failed.
     *
     * Stays retryable until MAX_ATTEMPTS, then settles into a state a human
     * can see and act on — PRINTER_OFFLINE when nothing answered, FAILED
     * for anything else, because those send whoever is fixing it to two
     * different places.
     */
    public function markFailed(PrintJob $job, string $error, bool $printerUnreachable = true): void
    {
        $exhausted = $job->attempts >= PrintJob::MAX_ATTEMPTS;

        $job->forceFill([
            'status' => $exhausted
                ? ($printerUnreachable ? PrintJob::PRINTER_OFFLINE : PrintJob::FAILED)
                : PrintJob::PENDING,
            'last_error' => mb_substr($error, 0, 500),
        ])->save();
    }

    /**
     * A deliberate reprint.
     *
     * A fresh row with the next copy number rather than a reset of the old
     * one: the first attempt genuinely happened and its failure is part of
     * the record. Overwriting it would erase the evidence that the printer
     * was down, which is exactly what someone reviewing the night needs.
     */
    public function requeue(PrintJob $job, ?int $requestedBy = null): PrintJob
    {
        $copy = $this->nextCopy($job);

        return PrintJob::create([
            'reference' => $this->reference(),
            'order_id' => $job->order_id,
            'printer_id' => $job->printer_id,
            'station' => $job->station,
            'printer_name' => $job->printer_name,
            'kind' => $job->kind,
            'status' => PrintJob::PENDING,
            'items_count' => $job->items_count,
            'requested_by' => $requestedBy,
            'idempotency_key' => PrintJob::keyFor(
                $job->order_id,
                (int) $job->printer_id,
                $job->station,
                $job->kind,
                $copy,
            ),
        ]);
    }

    /**
     * How many copies of this ticket already exist, plus one.
     *
     * Counted from the rows rather than held on the job, so two people
     * pressing reprint at the same time cannot both compute copy 2 — the
     * unique index rejects the second and reserve()'s race handling takes
     * over.
     */
    private function nextCopy(PrintJob $job): int
    {
        return PrintJob::query()
            ->where('order_id', $job->order_id)
            ->where('printer_id', $job->printer_id)
            ->where('station', $job->station)
            ->where('kind', $job->kind)
            ->count() + 1;
    }

    /**
     * Short, and readable out loud over a noisy pass.
     */
    private function reference(): string
    {
        return 'P-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
    }
}
