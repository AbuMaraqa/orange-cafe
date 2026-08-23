<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('services', 'slug')->ignore($this->route('service'))],
            'icon' => ['nullable', 'string', 'max:10'],
            'image' => ['nullable', 'image', 'max:15360'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $rules["translations.{$code}.name"] = [$code === $default ? 'required' : 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
