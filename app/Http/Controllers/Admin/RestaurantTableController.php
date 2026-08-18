<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PresentsTables;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantTableRequest;
use App\Http\Requests\Admin\UpdateRestaurantTableRequest;
use App\Models\RestaurantTable;
use App\Models\Service;
use App\Services\LanguageRegistry;
use App\Services\Native\PublicUrlResolver;
use App\Services\PdfService;
use App\Services\QrCodeService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RestaurantTableController extends Controller
{
    use PresentsTables;

    public function __construct(
        private readonly QrCodeService $qrCodeService,
        private readonly PublicUrlResolver $publicUrl,
    ) {}

    public function index(): Response
    {
        $tables = RestaurantTable::query()
            ->with(['orders' => fn ($query) => $query->whereIn('status', config('orders.active_statuses'))->latest()->with('items.modifiers')])
            ->get();

        return Inertia::render('Admin/Tables/Index', [
            'tables' => $tables->map(fn (RestaurantTable $table) => $this->presentTable($table)),
            'services' => Service::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Service $service) => ['id' => $service->id, 'name' => $service->name, 'icon' => $service->icon]),
        ]);
    }

    public function store(StoreRestaurantTableRequest $request): RedirectResponse
    {
        RestaurantTable::create($request->validated());

        return back()->with('success', __('admin.tables.created'));
    }

    public function update(UpdateRestaurantTableRequest $request, RestaurantTable $table): RedirectResponse
    {
        $table->update($request->validated());

        return back()->with('success', __('admin.tables.updated'));
    }

    public function updatePosition(Request $request, RestaurantTable $table): RedirectResponse
    {
        $data = $request->validate([
            'pos_x' => ['required', 'numeric', 'min:0', 'max:100'],
            'pos_y' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $table->update($data);

        return back();
    }

    public function destroy(RestaurantTable $table): RedirectResponse
    {
        $table->delete();

        return back()->with('success', __('admin.tables.deleted'));
    }

    /**
     * A printable sign for one table: scanning it opens the customer menu
     * with this table pre-selected, so orders link back to the right table
     * on the floor plan automatically.
     */
    public function qrCode(RestaurantTable $table, PdfService $pdf, RestaurantSettings $branding, LanguageRegistry $languages): HttpResponse
    {
        $mpdf = $pdf->fromView('pdf.table-qr', [
            'table' => $table,
            'branding' => $branding,
            'qrCode' => $this->qrPng($this->menuUrl($table, $languages), $branding),
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A6']);

        return response($mpdf->Output("table-{$table->label}-qr.pdf", Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="table-'.$table->label.'-qr.pdf"',
        ]);
    }

    /**
     * Every table's QR sign on one sheet, two per row — print once, cut out.
     */
    public function qrCodesAll(PdfService $pdf, RestaurantSettings $branding, LanguageRegistry $languages): HttpResponse
    {
        $tables = RestaurantTable::query()->where('is_active', true)->orderBy('label')->get();

        $qrCodes = $tables->mapWithKeys(fn (RestaurantTable $table) => [
            $table->id => $this->qrPng($this->menuUrl($table, $languages), $branding),
        ]);

        $mpdf = $pdf->fromView('pdf.table-qr-sheet', [
            'tables' => $tables,
            'qrCodes' => $qrCodes,
            'branding' => $branding,
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A4']);

        return response($mpdf->Output('table-qr-codes.pdf', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="table-qr-codes.pdf"',
        ]);
    }

    /**
     * A printable sign for one table×service pair (e.g. a "charcoal" QR
     * for a shisha table): scanning it fires the waiter call immediately
     * and shows a confirmation page — no menu browsing in between.
     */
    public function serviceQrCode(RestaurantTable $table, Service $service, PdfService $pdf, RestaurantSettings $branding, LanguageRegistry $languages): HttpResponse
    {
        $mpdf = $pdf->fromView('pdf.table-service-qr', [
            'table' => $table,
            'service' => $service,
            'branding' => $branding,
            'qrCode' => $this->qrPng($this->serviceUrl($table, $service, $languages), $branding),
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A6']);

        $filename = "table-{$table->label}-{$service->slug}-qr.pdf";

        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Both URL builders run their result through PublicUrlResolver: these
     * strings are printed onto a physical sign, so they have to work from a
     * customer's phone, not from the browser that happened to generate them
     * (typically the server machine itself, on localhost). See
     * PublicUrlResolver.
     */
    protected function menuUrl(RestaurantTable $table, LanguageRegistry $languages): string
    {
        $defaultLocale = $languages->default()?->code;
        $base = LaravelLocalization::getLocalizedURL($defaultLocale, route('menu.index', [], true));

        return $this->publicUrl->rewrite($base).'?table_id='.$table->id.'&table_label='.urlencode($table->label);
    }

    protected function serviceUrl(RestaurantTable $table, Service $service, LanguageRegistry $languages): string
    {
        $defaultLocale = $languages->default()?->code;

        return $this->publicUrl->rewrite(
            LaravelLocalization::getLocalizedURL($defaultLocale, route('waiter-calls.quick', [$table, $service], true))
        );
    }

    protected function qrPng(string $data, ?RestaurantSettings $branding = null): string
    {
        $logoPath = $branding?->logo_path && Storage::disk('public')->exists($branding->logo_path)
            ? Storage::disk('public')->path($branding->logo_path)
            : null;

        return base64_encode($this->qrCodeService->generatePng($data, $logoPath));
    }
}
