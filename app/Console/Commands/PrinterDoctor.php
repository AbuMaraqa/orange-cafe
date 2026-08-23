<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Services\CashierReceiptPrinterService;
use App\Services\Printing\PopplerBinary;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrinterTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answers "why is nothing printing?" on the machine it is actually
 * happening on.
 *
 * Every failure this checks for has the same symptom — no paper — and the
 * causes are indistinguishable from the till: a station with no printer, a
 * queue worker that is not running, a printer that answers nothing, a
 * missing rasterizer, a backlog held after a recovery. Diagnosing them
 * meant reading code and hand-writing tinker queries against a database
 * that is usually on someone else's PC.
 *
 * Read-only by design: it probes, counts and reports. Nothing here prints,
 * requeues, or changes a printer's state, so it is safe to run mid-service
 * on a machine that is busy.
 */
class PrinterDoctor extends Command
{
    protected $signature = 'printers:doctor';

    protected $description = 'Diagnose why printing is not working: stations, printers, queue worker and held tickets.';

    /** A queue that has not moved in this long is a worker that is not running. */
    private const STALE_QUEUE_SECONDS = 120;

    private int $problems = 0;

    public function handle(PrinterTransport $transport, PopplerBinary $poppler, PrinterHealth $health): int
    {
        $this->components->info('Printing diagnosis');

        $this->checkStations();
        $this->checkCashierPrinter();
        $this->checkReachability($transport);
        $this->checkQueueWorker();
        $this->checkOutstandingJobs();
        $this->checkHeldByRecovery($health);
        $this->checkRasterizer($poppler);

        $this->newLine();

        if ($this->problems === 0) {
            $this->components->info('Nothing wrong found. If a specific ticket did not print, look it up on the print jobs screen.');

            return self::SUCCESS;
        }

        $this->components->warn("{$this->problems} problem(s) found — each one above is a reason paper does not come out.");

        return self::FAILURE;
    }

    /**
     * A station named on the menu with no printer behind it is the quietest
     * failure in the app: those items are cooked by nobody and the order
     * looks completely normal everywhere on screen.
     */
    private function checkStations(): void
    {
        $onTheMenu = Category::query()->whereNotNull('station')->where('station', '!=', '')->distinct()->pluck('station')
            ->merge(Product::query()->whereNotNull('station')->where('station', '!=', '')->distinct()->pluck('station'))
            ->reject(fn (string $s) => $s === CashierReceiptPrinterService::STATION)
            ->unique()
            ->sort()
            ->values();

        if ($onTheMenu->isEmpty()) {
            $this->line('  <fg=gray>·</> No kitchen stations are defined on the menu yet.');

            return;
        }

        $covered = Printer::query()->where('is_active', true)->pluck('station')->unique();
        $orphaned = $onTheMenu->reject(fn (string $station) => $covered->contains($station));

        if ($orphaned->isEmpty()) {
            $this->ok('Every kitchen station on the menu has an active printer.');

            return;
        }

        $this->bad('Stations with no active printer: '.$orphaned->implode(', '));
        $this->hint('Items routed there never reach a kitchen. Add a printer for each, or move those categories to a station that has one.');
    }

    private function checkCashierPrinter(): void
    {
        $count = Printer::query()
            ->where('station', CashierReceiptPrinterService::STATION)
            ->where('is_active', true)
            ->count();

        if ($count > 0) {
            $this->ok('A cashier printer is configured.');

            return;
        }

        $this->bad('No active printer on the "cashier" station.');
        $this->hint('Invoices and the cash drawer both key off that exact station name — anything else looks configured and prints nothing.');
    }

    private function checkReachability(PrinterTransport $transport): void
    {
        $printers = Printer::query()->where('is_active', true)->orderBy('station')->get();

        if ($printers->isEmpty()) {
            $this->bad('No active printers at all.');

            return;
        }

        $rows = [];

        foreach ($printers as $printer) {
            $started = microtime(true);
            $reachable = true;
            $error = '';

            try {
                $transport->probe($printer);
            } catch (Throwable $e) {
                $reachable = false;
                $error = $e->getMessage();
            }

            $rows[] = [
                $printer->name,
                $printer->station,
                $printer->connection_type === 'network'
                    ? $printer->ip_address.':'.$printer->port
                    : ($printer->windows_share ?: $printer->connection_type),
                $reachable ? 'reachable' : 'UNREACHABLE',
                number_format(microtime(true) - $started, 2).'s',
                mb_substr($error, 0, 60),
            ];

            if (! $reachable) {
                $this->problems++;
            }
        }

        $this->newLine();
        $this->table(['Printer', 'Station', 'Address', 'Probe', 'Took', 'Error'], $rows);
    }

    /**
     * The button only ever queues; a worker is what prints. When one is not
     * running the till behaves exactly as if every printer were broken.
     */
    private function checkQueueWorker(): void
    {
        if (config('queue.default') === 'sync') {
            $this->ok('Queue is synchronous — printing happens in the request, no worker needed.');

            return;
        }

        $pending = DB::table('jobs')->count();

        if ($pending === 0) {
            $this->ok('No jobs waiting in the queue.');

            return;
        }

        $oldest = DB::table('jobs')->orderBy('id')->value('created_at');
        $waitingSeconds = $oldest ? now()->getTimestamp() - (int) $oldest : 0;

        if ($waitingSeconds < self::STALE_QUEUE_SECONDS) {
            $this->ok("{$pending} job(s) queued and moving.");

            return;
        }

        $minutes = (int) round($waitingSeconds / 60);

        $this->bad("{$pending} job(s) stuck in the queue; the oldest has waited {$minutes} minute(s).");
        $this->hint('Nothing is consuming the queue. Start the worker (php artisan queue:work) — in the packaged app it is started at boot, see config/nativephp.php.');
    }

    private function checkOutstandingJobs(): void
    {
        $needsAttention = PrintJob::query()->needsAttention()->count();

        if ($needsAttention === 0) {
            $this->ok('No print jobs waiting for someone to look at them.');

            return;
        }

        $this->bad("{$needsAttention} print job(s) failed or found the printer offline.");
        $this->hint('They are on the print jobs screen with the reason, and can be reprinted from there.');

        $recent = PrintJob::query()->needsAttention()->latest('id')->limit(5)->get();

        foreach ($recent as $job) {
            $this->line(sprintf(
                '      <fg=gray>%s · %s · %s · %s</>',
                $job->kind,
                $job->station,
                $job->status,
                mb_substr((string) $job->last_error, 0, 70)
            ));
        }
    }

    /**
     * A printer that came back mid-service will not print anything older
     * than the moment it returned. That is deliberate, and it is also the
     * one rule that looks exactly like a broken printer to whoever is
     * pressing the button.
     */
    private function checkHeldByRecovery(PrinterHealth $health): void
    {
        $recovered = Printer::query()->whereNotNull('recovered_at')->get();

        if ($recovered->isEmpty()) {
            return;
        }

        $held = 0;

        foreach ($recovered as $printer) {
            $held += PrintJob::query()
                ->where('printer_id', $printer->id)
                ->unprinted()
                ->whereHas('order', fn ($q) => $q->where('created_at', '<', $printer->recovered_at))
                ->count();
        }

        if ($held === 0) {
            return;
        }

        $this->warnOnly("{$held} ticket(s) predate their printer's recovery and will not print automatically.");
        $this->hint('This is intended — a printer coming back must not dump the orders it missed. Reprint the ones still wanted from the print jobs screen.');
    }

    private function checkRasterizer(PopplerBinary $poppler): void
    {
        if ($poppler->path() !== null) {
            $this->ok('The thermal rasterizer (pdftoppm) was found.');

            return;
        }

        $this->bad('pdftoppm (poppler) not found — no thermal ticket can be rendered at all.');
        $this->hint('Expected a bundled copy at '.base_path('bin/poppler').', poppler-utils on PATH, or POPPLER_PDFTOPPM_PATH set.');
    }

    private function ok(string $message): void
    {
        $this->line("  <fg=green>✔</> {$message}");
    }

    private function bad(string $message): void
    {
        $this->problems++;
        $this->line("  <fg=red>✘</> {$message}");
    }

    /** Worth saying, but working as designed — not counted as a problem. */
    private function warnOnly(string $message): void
    {
        $this->line("  <fg=yellow>!</> {$message}");
    }

    private function hint(string $message): void
    {
        $this->line("      <fg=gray>{$message}</>");
    }
}
