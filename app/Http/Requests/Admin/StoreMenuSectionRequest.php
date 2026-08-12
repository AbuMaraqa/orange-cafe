<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreMenuSectionRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:categories,slug'],
            'station' => ['nullable', 'string', 'max:255'],
            'translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $rules["translations.{$code}.name"] = [$code === $default ? 'required' : 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
