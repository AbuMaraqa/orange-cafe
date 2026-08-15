<?php

namespace App\Services\Printing\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Dedup based directly on what's actually in the `jobs` table, rather
 * than a separate cache-based lock (Laravel's ShouldBeUnique). That lock
 * is acquired at dispatch and only released when the job finishes
 * normally — if the worker process itself dies mid-job (crashed, killed,
 * lost its DB connection), the lock is orphaned and silently blocks every
 * future print attempt for that exact (order, printer) pair for as long
 * as the lock's TTL, with no error anywhere. That happened in production
 * here (a MySQL blip took the worker down mid-job) and looked exactly
 * like "the print button just doesn't work sometimes". Checking the jobs
 * table directly has no separate state to desync: if the row is gone —
 * crashed, completed, whatever — a new dispatch is immediately allowed.
 */
trait SkipsAlreadyQueuedPrints
{
    protected function hasPendingJob(string $jobClass, int $orderId, int $printerId): bool
    {
        return DB::table('jobs')->pluck('payload')->contains(function (string $rawPayload) use ($jobClass, $orderId, $printerId) {
            $payload = json_decode($rawPayload, true);

            if (($payload['displayName'] ?? null) !== $jobClass) {
                return false;
            }

            try {
                $command = unserialize($payload['data']['command']);
            } catch (\Throwable) {
                return false;
            }

            return isset($command->order, $command->printer)
                && $command->order->id === $orderId
                && $command->printer->id === $printerId;
        });
    }
}
