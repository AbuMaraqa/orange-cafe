<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:modifiers,id'],
        ];
    }
}
