<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModifierGroupRequest extends FormRequest
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
            'is_required' => ['sometimes', 'boolean'],
            'is_variation' => ['sometimes', 'boolean'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['nullable', 'integer', 'min:1', 'gte:min_select'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['required', 'array'],

            'modifiers' => ['required', 'array', 'min:1'],
            'modifiers.*.id' => [
                'nullable', 'integer',
                Rule::exists('modifiers', 'id')->where('modifier_group_id', $this->route('modifier_group')->id),
            ],
            'modifiers.*.price_cents' => ['required', 'integer', 'min:0'],
            'modifiers.*.is_active' => ['sometimes', 'boolean'],
            'modifiers.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'modifiers.*.translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $required = $code === $default ? 'required' : 'nullable';
            $rules["translations.{$code}.name"] = [$required, 'string', 'max:255'];
            $rules["modifiers.*.translations.{$code}.name"] = [$required, 'string', 'max:255'];
        }

        return $rules;
    }
}
