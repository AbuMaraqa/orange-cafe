<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
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
            // Any existing category can be a parent — nesting is
            // unbounded (category -> subcategory -> ...).
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            // Required only for a top-level section; a subcategory always
            // inherits its parent's menu (the controller enforces this
            // regardless of what's submitted here).
            'menu_id' => ['required_without:parent_id', 'nullable', 'integer', 'exists:menus,id'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:categories,slug'],
            // Must match a real Printer's station exactly (the form
            // submits it from a dropdown of configured printers) —
            // KitchenPrinterService looks printers up by this string.
            'station' => ['nullable', 'string', 'max:100', 'exists:printers,station'],
            'image' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $rules["translations.{$code}.name"] = [$code === $default ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["translations.{$code}.description"] = ['nullable', 'string'];
        }

        return $rules;
    }
}
