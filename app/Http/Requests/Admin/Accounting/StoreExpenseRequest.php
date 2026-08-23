<?php

namespace App\Http\Requests\Admin\Accounting;

use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    use NormalisesMoney;

    public function authorize(): bool
    {
        return $this->user()?->can('expenses.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseAmount('amount_cents');
        $this->defaultToToday('spent_on');
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],

            // Optional: rent is on nobody. When present it puts the expense
            // on that person's account as well as in the day's spending.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],

            // min:1 rather than min:0 — a zero-value expense records nothing
            // and would sit in every report adding nothing to it.
            'amount_cents' => ['required', 'integer', 'min:1'],

            'spent_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paid_from_cash' => ['sometimes', 'boolean'],
        ];
    }
}
