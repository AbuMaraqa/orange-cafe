<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attaching one or many products to a menu section in a single request.
 * The builder's picker is multi-select, so the common case here is a
 * batch — "add the whole hot-drinks shortlist to this section" — not the
 * single line the previous one-product-per-post endpoint assumed.
 */
class StoreMenuItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sectionId = $this->route('section')->id;

        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                'distinct',
                'exists:products,id',
                // A product can only appear once per section — attaching it
                // again would just be editing the existing price override.
                // It may still appear in *other* sections of the same menu.
                Rule::unique('menu_items', 'product_id')->where('category_id', $sectionId),
            ],
            // Only honoured when a single product is being added; see
            // MenuBuilderController::storeItems.
            'price_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_ids.*' => __('admin.products.title'),
        ];
    }
}
