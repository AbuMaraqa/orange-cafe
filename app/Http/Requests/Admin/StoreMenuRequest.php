<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreMenuRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:menus,slug'],
            'publish_status' => ['required', new Enum(\App\Enums\MenuPublishStatus::class)],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
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
