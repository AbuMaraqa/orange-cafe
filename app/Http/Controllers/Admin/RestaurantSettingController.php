<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRestaurantSettingRequest;
use App\Services\Images\ImageOptimizer;
use App\Services\PdfService;
use App\Services\WifiQrCode;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class RestaurantSettingController extends Controller
{
    public function edit(RestaurantSettings $settings): Response
    {
        return Inertia::render('Admin/Settings/Edit', [
            'setting' => [
                'name' => $settings->name,
                'tagline' => $settings->tagline,
                'address' => $settings->address,
                'logo_url' => $settings->logo_path ? Storage::disk('public')->url($settings->logo_path) : null,
                'cover_image_url' => $settings->cover_image_path ? Storage::disk('public')->url($settings->cover_image_path) : null,
                'primary_color' => $settings->primary_color,
                'secondary_color' => $settings->secondary_color,
                'font_family' => $settings->font_family,
                'currency_code' => $settings->currency_code,
                'currency_symbol' => $settings->currency_symbol,
                'phone' => $settings->phone,
                'whatsapp' => $settings->whatsapp,
                'instagram' => $settings->instagram,
                'facebook' => $settings->facebook,
                'wifi_ssid' => $settings->wifi_ssid,
                'wifi_password' => $settings->wifi_password,
                'receipt_footer_text' => $settings->receipt_footer_text,
                'business_day_starts_at' => $settings->business_day_starts_at,
            ],
        ]);
    }

    public function update(UpdateRestaurantSettingRequest $request, RestaurantSettings $settings): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $data['logo_path'] = app(ImageOptimizer::class)->store($request->file('logo'), 'branding');
        }
        unset($data['logo']);

        if ($request->hasFile('cover_image')) {
            if ($settings->cover_image_path) {
                Storage::disk('public')->delete($settings->cover_image_path);
            }
            $data['cover_image_path'] = app(ImageOptimizer::class)->store($request->file('cover_image'), 'branding');
        }
        unset($data['cover_image']);

        $settings->fill($data);
        $settings->save();

        return to_route('admin.settings.edit')->with('success', __('admin.settings.updated'));
    }

    /**
     * A printable "join our Wi-Fi" sign, independent of any table.
     *
     * The network belongs to the venue rather than to a seat, so this is one
     * sign for the counter or the door — changing the password means
     * reprinting this single page, not every table sign in the restaurant.
     */
    public function wifiQrCode(RestaurantSettings $settings, PdfService $pdf, WifiQrCode $wifi): HttpResponse
    {
        abort_unless($wifi->isConfigured($settings), 404);

        $mpdf = $pdf->fromView('pdf.wifi-qr', [
            'branding' => $settings,
            'qrCode' => $wifi->png($settings),
            'labels' => __('pdf'),
        ], 'ltr', ['format' => 'A6']);

        return response($mpdf->Output('wifi-qr.pdf', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="wifi-qr.pdf"',
        ]);
    }
}
