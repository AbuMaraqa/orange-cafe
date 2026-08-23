<?php

namespace App\Http\Requests\Admin\Accounting;

use App\Http\Requests\Admin\Accounting\Concerns\NormalisesMoney;
use Illuminate\Foundation\Http\FormRequest;

class StoreAbsenceRequest extends FormRequest
{
    use NormalisesMoney;

    public function authorize(): bool
    {
        return $this->user()?->can('absences.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseAmount('deduction_cents', 'deduction');
        $this->defaultToToday('absent_on');
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'absence_category_id' => ['required', 'integer', 'exists:absence_categories,id'],

            // min:0, unlike an expense: an absence that cost the employee
            // nothing is a real record worth keeping — إجازة مرضية happened
            // and was not charged for, and that is different from it never
            // having been filed.
            'deduction_cents' => ['required', 'integer', 'min:0'],

            'absent_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
