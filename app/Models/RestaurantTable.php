<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
     * The bill this table is running, if any.
     *
     * A reviewed ticket wins over a newer unreviewed one. A customer
     * ordering a second round creates a pending order of its own that waits
     * for a member of staff to accept it (see
     * OrderController::joinableTableOrder), and while it waits the table has
     * two live tickets. Answering with the newest would hand every caller
     * the near-empty one instead of the bill the table has actually run up —
     * WaiterOrderController uses this to decide whether to add to a running
     * ticket, and it would have added to the wrong one.
     *
     * The pending round is not lost by being passed over here: it is in
     * `pending_orders`, it raises the floor alert, and confirming it folds
     * it into this bill.
     */
    public function activeOrder(): ?Order
    {
        return $this->orders()
            ->whereIn('status', config('orders.active_statuses'))
            // false sorts first in SQLite and MySQL alike, so reviewed
            // tickets lead; `latest()` then breaks the tie as it always did.
            ->orderBy(DB::raw("status = 'pending'"))
            ->latest()
            ->first();
    }
}
