<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRestaurantSettingRequest;
use App\Settings\RestaurantSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

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
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }
        unset($data['logo']);

        if ($request->hasFile('cover_image')) {
            if ($settings->cover_image_path) {
                Storage::disk('public')->delete($settings->cover_image_path);
            }
            $data['cover_image_path'] = $request->file('cover_image')->store('branding', 'public');
        }
        unset($data['cover_image']);

        $settings->fill($data);
        $settings->save();

        return to_route('admin.settings.edit')->with('success', __('admin.settings.updated'));
    }
}
