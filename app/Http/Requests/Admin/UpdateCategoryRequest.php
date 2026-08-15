<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
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
            // menu_id is intentionally not editable here — menu_items rows
            // for this section's products denormalize menu_id too, and
            // moving a section between menus would orphan them. Delete and
            // recreate the section under the right menu instead.
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($this->route('category'))],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if ($parentId && (int) $parentId === $category->id) {
                $validator->errors()->add('parent_id', __('validation.custom.parent_id.not_self'));
            }

            // Reparenting under one of its own descendants would create a
            // cycle — nesting itself is otherwise unbounded.
            if ($parentId && in_array((int) $parentId, $category->descendantIds(), true)) {
                $validator->errors()->add('parent_id', __('validation.custom.parent_id.descendant'));
            }
        });
    }
}
