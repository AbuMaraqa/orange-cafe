<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free-text feedback a customer leaves about the cafe from the menu page —
 * not tied to an order, unlike order notes. Kept as a simple inbox for
 * admins to read rather than anything requiring a real-time alert.
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = ['table_id', 'table_number', 'message', 'is_read'];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function tableLabel(): ?string
    {
        return $this->restaurantTable?->label ?? $this->table_number;
    }
}
