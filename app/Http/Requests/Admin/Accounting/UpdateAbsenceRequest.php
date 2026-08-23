<?php

namespace App\Http\Requests\Admin\Accounting;

class UpdateAbsenceRequest extends StoreAbsenceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('absences.update') ?? false;
    }
}
