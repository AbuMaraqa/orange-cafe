<?php

namespace App\Models;

use App\Enums\WaiterCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer tapping "Call Waiter" on the table's menu page — a lightweight,
 * short-lived record just so a call still shows up if a waiter's screen
 * was closed at the moment it was raised, not a permanent log.
 */
class WaiterCall extends Model
{
    protected $fillable = ['table_id', 'table_number', 'status'];

    protected function casts(): array
    {
        return [
            'status' => WaiterCallStatus::class,
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function tableLabel(): ?string
    {
        return $this->table?->label ?? $this->table_number;
    }
}
