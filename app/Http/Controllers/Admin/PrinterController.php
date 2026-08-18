<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePrinterRequest;
use App\Http\Requests\Admin\UpdatePrinterRequest;
use App\Models\Printer;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\UsbPrinterTransport;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PrinterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Printers/Index', [
            'printers' => Printer::query()->orderBy('station')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Printers/Form', [
            'printer' => null,
            'usbTransportSupported' => UsbPrinterTransport::supported(),
        ]);
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

    public function test(Printer $printer, PrinterTransport $transport): RedirectResponse
    {
        try {
            $transport->probe($printer);

            return back()->with('success', __('admin.printers.test_ok'));
        } catch (\Throwable $e) {
            return back()->withErrors(['printer' => __('admin.printers.test_failed', ['error' => $e->getMessage()])]);
        }
    }
}
