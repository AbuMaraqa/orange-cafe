<?php

namespace App\Http\Requests\Admin;

use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Null for a top-level section. Anything else must already be a
            // section of *this* menu, so a sub-section can never end up
            // parented into a different menu's tree.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('menu_id', $this->route('menu')->id),
            ],
            'translations' => ['required', 'array'],
        ];

        foreach ($registry->codes() as $code) {
            $rules["translations.{$code}.name"] = [$code === $default ? 'required' : 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
