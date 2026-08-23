<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The handful of section switches the builder exposes inline. Names,
 * slug, image and parent live on the full category form — this is only
 * what an administrator needs to flip while looking at the menu as a
 * whole, without losing their place.
 */
class UpdateMenuSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'station' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
