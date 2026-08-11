<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantTable extends Model
{
    use HasFactory;

    protected $fillable = ['label', 'pos_x', 'pos_y', 'shape', 'seats', 'is_active'];

    protected function casts(): array
    {
        return [
            'pos_x' => 'float',
            'pos_y' => 'float',
            'seats' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /**
     * The order currently occupying this table, if any — the most recent
     * order still in an active (not completed/cancelled) status.
     */
    public function activeOrder(): ?Order
    {
        return $this->orders()
            ->whereIn('status', config('orders.active_statuses'))
            ->latest()
            ->first();
    }
}
