<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $registry = app(LanguageRegistry::class);
        $default = $registry->default()?->code;

        $rules = [
            // No category/menu here on purpose — a product is standalone;
            // it's attached to sections/menus (possibly several, each with
            // its own price) from the Menu Builder instead.
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:products,slug'],
            'station' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'max:2048'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'modifier_group_ids' => ['nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $rules["translations.{$code}.name"] = [$code === $default ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["translations.{$code}.description"] = ['nullable', 'string'];
        }

        return $rules;
    }
}
