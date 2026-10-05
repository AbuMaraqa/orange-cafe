<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantSettingRequest extends FormRequest
{
    use NormalisesMoney;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The float arrives as typed, in currency units, like every other amount
     * in the app — see NormalisesMoney for why the browser is not trusted to
     * multiply.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseAmount('cashbox_float_cents', 'cashbox_float');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:15360'],
            'cover_image' => ['nullable', 'image', 'max:15360'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_family' => ['required', 'in:tajawal,almarai,plex-arabic,system'],
            'currency_code' => ['required', 'string', 'max:3'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'wifi_ssid' => ['nullable', 'string', 'max:255'],
            'wifi_password' => ['nullable', 'string', 'max:255'],
            'receipt_footer_text' => ['nullable', 'string', 'max:500'],
            // "HH:MM" as the browser's time input writes it. See BusinessDay.
            'business_day_starts_at' => ['required', 'date_format:H:i'],
            // What the drawer opens on every day. Zero is allowed — a café
            // that empties the till completely at night opens on nothing.
            'cashbox_float_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
