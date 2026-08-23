<?php

namespace App\Console\Commands;

use App\Services\TableTicketConsolidator;
use Illuminate\Console\Command;

/**
 * Repairs tables left holding more than one live ticket.
 *
 * Only useful against data written before OrderController stopped opening a
 * second ticket on an occupied table. Safe to run afterwards — it simply
 * finds nothing — which is why it reports rather than asks when there is
 * nothing to do.
 *
 * Shows what it would do and stops, unless told to commit. Merging bills is
 * not reversible from the outside, and the person running it should see the
 * money involved before it moves.
 */
class ConsolidateTableTickets extends Command
{
    protected $signature = 'orders:consolidate-tables {--apply : Perform the merge instead of only reporting it}';

    protected $description = 'Merge stray tickets on tables holding more than one live order';

    public function handle(TableTicketConsolidator $consolidator): int
    {
        $apply = (bool) $this->option('apply');
        $report = $consolidator->consolidate(apply: $apply);

        if ($report === []) {
            $this->info('Every table holds at most one live ticket. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Kept', 'Absorbed', 'Money recovered'],
            array_map(fn (array $row) => [
                $row['table_id'],
                '#'.$row['kept'],
                implode(', ', array_map(fn ($id) => '#'.$id, $row['absorbed'])),
                number_format($row['moved_cents'] / 100, 2),
            ], $report),
        );

        $total = array_sum(array_column($report, 'moved_cents'));

        $this->line(sprintf(
            '%s %s across %d table(s), previously invisible on every screen.',
            $apply ? 'Recovered' : 'Would recover',
            number_format($total / 100, 2),
            count($report),
        ));

        if (! $apply) {
            $this->comment('Nothing was changed. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
