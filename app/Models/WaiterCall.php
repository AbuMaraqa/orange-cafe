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
    protected $fillable = ['table_id', 'table_number', 'service_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => WaiterCallStatus::class,
        ];
    }

    // Named restaurantTable() rather than table() — the latter collides
    // with Eloquent's own protected Model::$table property (the DB table
    // name), and $this->table from inside this class silently resolves to
    // that string instead of the relation, breaking tableLabel() below.
    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function tableLabel(): ?string
    {
        return $this->restaurantTable?->label ?? $this->table_number;
    }
}
