<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AbsenceCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'default_deduction_cents', 'affects_pay', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'default_deduction_cents' => 'integer',
            'affects_pay' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function isDeletable(): bool
    {
        return ! $this->absences()->exists();
    }
}
