<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => [
                'required', 'integer', 'exists:products,id',
                // A product can only appear once per section — attaching it
                // again would just be editing the existing price override.
                'unique:menu_items,product_id,NULL,id,category_id,'.$this->route('section')->id,
            ],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
