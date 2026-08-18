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
            // A product is still standalone (no category_id column) — this
            // just optionally creates menu placements (MenuItems), the same
            // link Menu Builder manages afterward. A product can belong to
            // several categories/menus at once; removing a placement is
            // still done from Menu Builder, not here.
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:products,slug'],
            'station' => ['nullable', 'string', 'max:100', 'exists:printers,station'],
            'image' => ['nullable', 'image', 'max:2048'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'modifier_group_ids' => ['nullable', 'array'],
            'modifier_group_ids.*' => ['integer', 'exists:modifier_groups,id'],
            'modifier_overrides' => ['nullable', 'array'],
            'modifier_overrides.*.modifier_id' => ['required', 'integer', 'exists:modifiers,id'],
            'modifier_overrides.*.price_cents' => ['nullable', 'integer', 'min:0'],
            'modifier_overrides.*.is_active' => ['nullable', 'boolean'],
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
