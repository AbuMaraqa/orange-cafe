<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
