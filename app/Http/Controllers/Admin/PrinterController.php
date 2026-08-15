<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePrinterRequest;
use App\Http\Requests\Admin\UpdatePrinterRequest;
use App\Models\Printer;
use App\Services\Printing\PrinterTransport;
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
        return Inertia::render('Admin/Printers/Form', ['printer' => null]);
    }

    public function store(StorePrinterRequest $request): RedirectResponse
    {
        Printer::create($request->validated());

        return to_route('admin.printers.index')->with('success', __('admin.printers.created'));
    }

    public function edit(Printer $printer): Response
    {
        return Inertia::render('Admin/Printers/Form', ['printer' => $printer]);
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
