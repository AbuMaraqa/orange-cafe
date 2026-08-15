<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'station' => ['required', 'string', 'max:100'],
            'connection_type' => ['required', Rule::in(['network', 'usb'])],
            'ip_address' => ['required_if:connection_type,network', 'nullable', 'ip'],
            'port' => ['required_if:connection_type,network', 'nullable', 'integer', 'min:1', 'max:65535'],
            // Standard USB vendor/product ID hex format, e.g. "154f".
            'usb_vendor_id' => ['required_if:connection_type,usb', 'nullable', 'regex:/^[0-9a-fA-F]{4}$/'],
            'usb_product_id' => ['required_if:connection_type,usb', 'nullable', 'regex:/^[0-9a-fA-F]{4}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
