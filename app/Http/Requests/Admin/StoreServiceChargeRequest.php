<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceChargeRequest extends FormRequest
{
    use NormalisesMoney;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The form sends what was typed, in currency units; cents are made
     * here. Same rule as every other amount in the app — see
     * NormalisesMoney for why the browser is not trusted to multiply.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseAmount('amount_cents');
    }

    public function rules(): array
    {
        $registry = app(LanguageRegistry::class);
        $default = $registry->default()?->code;

        $rules = [
            // In cents, like every other amount that reaches the database.
            // Zero is allowed: a charge worth nothing on the price list is
            // a name the till can put on a bill and price on the spot.
            'amount_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
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
