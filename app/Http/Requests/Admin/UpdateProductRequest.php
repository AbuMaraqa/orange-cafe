<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
            // Optional: adds new menu placements (MenuItems) for this
            // product, the same link Menu Builder manages — it does not
            // replace or remove existing placements.
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('products', 'slug')->ignore($this->route('product'))],
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
