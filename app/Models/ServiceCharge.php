<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a table is charged for that nobody cooks.
 *
 * A seat held past two hours, a service charge, corkage — money that
 * belongs on the bill with no plate behind it. Kept apart from products
 * because a product is browsable by a customer, printable at a station and
 * orderable from the menu, and none of that is true here: the only way one
 * of these reaches a bill is a member of staff deciding it should.
 *
 * Not to be confused with Service, which is the call bell on the customer's
 * menu page ("bring the bill") and carries no money at all.
 */
class ServiceCharge extends Model implements TranslatableContract
{
    use Translatable;

    public $translatedAttributes = ['name'];

    protected $fillable = ['amount_cents', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
