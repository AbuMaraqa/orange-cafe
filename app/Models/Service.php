<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A quick front-of-house request a customer can raise from the table's
 * menu page (e.g. "Call Waiter", "Bring the bill", "Extra chair") —
 * admin-defined and dynamic, unlike the single hardcoded "call waiter"
 * button this generalizes. Requesting one creates a WaiterCall row with
 * this service attached (see WaiterCall::service()); a null service_id on
 * that table still means the plain generic call.
 */
class Service extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name'];

    protected $fillable = ['slug', 'icon', 'image_path', 'color', 'is_active', 'sort_order'];

    protected $appends = ['image_url'];

    /**
     * Assigned to services that don't set an explicit color, keyed by id
     * so two services never land on the same one by chance — the point is
     * telling call types apart at a glance on the waiter board, not
     * letting an admin skip picking a color and end up with everything
     * amber again.
     */
    private const PALETTE = ['#f59e0b', '#ef4444', '#3b82f6', '#14b8a6', '#8b5cf6', '#ec4899', '#22c55e', '#f97316'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        );
    }

    public function displayColor(): string
    {
        return $this->color ?: self::PALETTE[$this->id % count(self::PALETTE)];
    }

    public function waiterCalls(): HasMany
    {
        return $this->hasMany(WaiterCall::class);
    }
}
