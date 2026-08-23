<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePrinterRequest;
use App\Http\Requests\Admin\UpdatePrinterRequest;
use App\Models\Category;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\CashierReceiptPrinterService;
use App\Services\Printing\PrinterDiscovery;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\TestPagePrinter;
use App\Services\Printing\UsbPrinterTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrinterController extends Controller
{
    public function index(): Response
    {
        $printers = Printer::query()->orderBy('station')->orderBy('name')->get();

        // Which sections feed each printer. Read from the catalogue rather
        // than stored on the printer: the routing rule is صنف → فئة → محطة →
        // طابعة, so the printer is the *last* link and duplicating the list
        // onto it is how the two get out of step.
        $categoriesByStation = Category::query()
            ->with('translations')
            ->whereNotNull('station')
            ->get()
            ->groupBy('station')
            ->map(fn ($group) => $group->map(fn (Category $category) => $category->name)->values());

        $unprintedByPrinter = PrintJob::query()
            ->unprinted()
            ->selectRaw('printer_id, count(*) as total')
            ->groupBy('printer_id')
            ->pluck('total', 'printer_id');

        return Inertia::render('Admin/Printers/Index', [
            'printers' => $printers->map(fn (Printer $printer) => [
                ...$printer->only([
                    'id', 'name', 'station', 'connection_type', 'ip_address', 'port',
                    'windows_share', 'is_active', 'status',
                ]),
                'status_checked_at' => $printer->status_checked_at?->toIso8601String(),
                'recovered_at' => $printer->recovered_at?->toIso8601String(),
                'offline_since' => $printer->offline_since?->toIso8601String(),
                'last_error' => $printer->last_error,
                'is_cashier' => $printer->isCashier(),
                'categories' => $categoriesByStation->get($printer->station, collect())->all(),
                'unprinted_count' => (int) ($unprintedByPrinter[$printer->id] ?? 0),
            ]),
            'unprintedTotal' => PrintJob::query()->needsAttention()->count(),
            'uncoveredStations' => $this->uncoveredStations(),
        ]);
    }

    /**
     * Stations the menu routes items to that no active printer serves.
     *
     * The quietest failure this app has: those items are cooked by nobody,
     * and the order looks completely normal on every screen. It is only
     * visible by comparing two lists that live in different places, which is
     * what this does — and it is why an install can look finished while a
     * whole section of the menu never reaches a kitchen.
     *
     * @return array<int, string>
     */
    private function uncoveredStations(): array
    {
        $onTheMenu = Category::query()
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->distinct()
            ->pluck('station');

        $served = Printer::query()->where('is_active', true)->pluck('station');

        return $onTheMenu
            ->reject(fn (string $station) => $served->contains($station))
            ->values()
            ->all();
    }

    /**
     * The printers answering on this network, for the "find them for me"
     * button on the printers screen.
     *
     * Returns JSON rather than an Inertia redirect: the sweep takes a couple
     * of seconds and the screen shows it happening, which a full page
     * response cannot do.
     */
    public function discover(PrinterDiscovery $discovery): JsonResponse
    {
        return response()->json(['printers' => $discovery->scan()]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Printers/Form', [
            'printer' => null,
            'usbTransportSupported' => UsbPrinterTransport::supported(),
            'stations' => $this->stations(),
            // Carried over from a discovered printer, so the address found
            // by the sweep is never retyped from a screen onto a keyboard.
            'prefill' => [
                'ip_address' => $request->string('ip_address')->toString() ?: null,
                'port' => $request->integer('port') ?: null,
            ],
        ]);
    }

    /**
     * The stations a printer can serve, as something to pick from rather
     * than type.
     *
     * 'cashier' is a magic string: CashierReceiptPrinterService matches it
     * exactly to decide which printer gets invoices and opens the cash
     * drawer, and KitchenPrinterService falls back to it when a kitchen
     * printer is unreachable. It used to be a free-text box, so "Cashier",
     * "كاشير" or a trailing space produced a printer that looked configured
     * and silently printed nothing — no invoice, no drawer, no error.
     *
     * The rest come from the categories themselves, which is where a kitchen
     * station name is actually defined (KitchenPrinterService matches
     * Printer::station against Category::station verbatim). Typing one by
     * hand and misspelling it produced the same silent failure.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function stations(): array
    {
        $fromCategories = Category::query()
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->distinct()
            ->pluck('station')
            ->reject(fn (string $station) => $station === CashierReceiptPrinterService::STATION)
            ->sort()
            ->values();

        return collect([[
            'value' => CashierReceiptPrinterService::STATION,
            'label' => 'الكاشير — الفواتير ودرج النقود',
        ]])->merge(
            $fromCategories->map(fn (string $station) => ['value' => $station, 'label' => $station])
        )->all();
    }

    public function store(StorePrinterRequest $request): RedirectResponse
    {
        Printer::create($request->validated());

        return to_route('admin.printers.index')->with('success', __('admin.printers.created'));
    }

    /**
     * usbTransportSupported tells the form whether this server can actually
     * reach a USB printer at all — the packaged desktop app's PHP runtime
     * has no FFI extension, so offering USB there silently produces a
     * printer that never prints. See UsbPrinterTransport.
     */
    public function edit(Printer $printer): Response
    {
        return Inertia::render('Admin/Printers/Form', [
            'printer' => $printer,
            'usbTransportSupported' => UsbPrinterTransport::supported(),
            'stations' => $this->stations(),
        ]);
    }

    public function update(UpdatePrinterRequest $request, Printer $printer): RedirectResponse
    {
        $printer->update($request->validated());

        return to_route('admin.printers.index')->with('success', __('admin.printers.updated'));
    }

    public function destroy(Printer $printer): RedirectResponse
    {
        $printer->delete();

        return to_route('admin.printers.index')->with('success', __('admin.printers.deleted'));
    }

    /**
     * Connectivity only — opens the socket and closes it again.
     *
     * Kept alongside testPrint() because they answer different questions: a
     * printer can answer its socket perfectly while being out of paper, and
     * a probe that passes is not proof anything will come out.
     */
    public function test(Printer $printer, PrinterTransport $transport): RedirectResponse
    {
        try {
            $transport->probe($printer);

            return back()->with('success', __('admin.printers.test_ok'));
        } catch (\Throwable $e) {
            return back()->withErrors(['printer' => __('admin.printers.test_failed', ['error' => $e->getMessage()])]);
        }
    }

    /**
     * Puts an actual page through the printer.
     *
     * The only test that proves the thing works end to end: paper, ribbon,
     * spooler, encoding and all. It deliberately does not go through the
     * print queue — it belongs to no order, so giving it a PrintJob row
     * would put a test page on the "unprinted orders" list.
     */
    public function testPrint(Printer $printer, TestPagePrinter $tester): RedirectResponse
    {
        if ($tester->print($printer)) {
            return back()->with('success', __('printing.test_succeeded', ['printer' => $printer->name]));
        }

        return back()->withErrors([
            'printer' => __('printing.test_failed', [
                'printer' => $printer->name,
                'error' => $tester->lastError(),
            ]),
        ]);
    }
}
