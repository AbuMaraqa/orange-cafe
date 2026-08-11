<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantTableRequest;
use App\Http\Requests\Admin\UpdateRestaurantTableRequest;
use App\Models\RestaurantTable;
use App\Services\LanguageRegistry;
use App\Services\PdfService;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RestaurantTableController extends Controller
{
    public function index(): Response
    {
        $tables = RestaurantTable::query()
            ->with(['orders' => fn ($query) => $query->whereIn('status', config('orders.active_statuses'))->latest()->with('items')])
            ->get();

        return Inertia::render('Admin/Tables/Index', [
            'tables' => $tables->map(fn (RestaurantTable $table) => $this->present($table)),
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
            'qrCode' => $this->qrPng($this->menuUrl($table, $languages)),
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A6']);

        return response($mpdf->Output("table-{$table->label}-qr.pdf", \Mpdf\Output\Destination::STRING_RETURN), 200, [
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
            $table->id => $this->qrPng($this->menuUrl($table, $languages)),
        ]);

        $mpdf = $pdf->fromView('pdf.table-qr-sheet', [
            'tables' => $tables,
            'qrCodes' => $qrCodes,
            'branding' => $branding,
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A4']);

        return response($mpdf->Output('table-qr-codes.pdf', \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="table-qr-codes.pdf"',
        ]);
    }

    protected function menuUrl(RestaurantTable $table, LanguageRegistry $languages): string
    {
        $defaultLocale = $languages->default()?->code;
        $base = LaravelLocalization::getLocalizedURL($defaultLocale, route('menu.index', [], true));

        return $base.'?table_id='.$table->id.'&table_label='.urlencode($table->label);
    }

    protected function qrPng(string $data): string
    {
        return DNS2D::getBarcodePNG($data, 'QRCODE', 6, 6);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(RestaurantTable $table): array
    {
        $activeOrder = $table->orders->first();

        return [
            'id' => $table->id,
            'label' => $table->label,
            'pos_x' => $table->pos_x,
            'pos_y' => $table->pos_y,
            'shape' => $table->shape,
            'seats' => $table->seats,
            'is_active' => $table->is_active,
            'is_occupied' => $activeOrder !== null,
            'active_order' => $activeOrder ? [
                'id' => $activeOrder->id,
                'reference' => $activeOrder->reference,
                'status' => $activeOrder->status,
                'status_color' => $activeOrder->statusColor(),
                'guest_count' => $activeOrder->guest_count,
                'total_cents' => $activeOrder->total_cents,
                'is_paid' => $activeOrder->isPaid(),
                'items_count' => $activeOrder->items->sum('quantity'),
                'created_at' => $activeOrder->created_at->toIso8601String(),
            ] : null,
        ];
    }
}
