<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A customer never types their table in — it's only ever known by
        // scanning the QR code printed on it (which resolves to table_id).
        // Free-text table_number stays available exclusively for staff
        // placing a manual order from the waiter screen.
        $isCustomerOrder = $this->routeIs('menu.orders.store');

        return [
            'table_id' => [$isCustomerOrder ? 'required' : 'nullable', 'integer', 'exists:restaurant_tables,id'],
            'table_number' => ['nullable', 'string', 'max:50'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:modifiers,id'],
        ];
    }
}
