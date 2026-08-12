<?php

namespace App\Models;

use App\Enums\MenuPublishStatus;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Menu extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description'];

    protected $fillable = [
        'slug', 'publish_status', 'is_active',
        'starts_at', 'ends_at', 'start_time', 'end_time', 'days_of_week',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'publish_status' => MenuPublishStatus::class,
            'is_active' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'days_of_week' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * Exists and has been published — regardless of whether it's currently
     * within its schedule or manually disabled.
     */
    public function isAvailable(): bool
    {
        return $this->publish_status === MenuPublishStatus::Published;
    }

    /**
     * Within its configured date range, day-of-week, and time-of-day
     * window right now — regardless of publish status.
     */
    public function isWithinSchedule(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->starts_at && $at->toDateString() < $this->starts_at->toDateString()) {
            return false;
        }

        if ($this->ends_at && $at->toDateString() > $this->ends_at->toDateString()) {
            return false;
        }

        if (! empty($this->days_of_week) && ! in_array($at->dayOfWeek, $this->days_of_week, true)) {
            return false;
        }

        if ($this->start_time && $at->format('H:i:s') < $this->start_time->format('H:i:s')) {
            return false;
        }

        if ($this->end_time && $at->format('H:i:s') > $this->end_time->format('H:i:s')) {
            return false;
        }

        return true;
    }

    /**
     * What a customer would actually be shown right now: published,
     * manually active, and inside its schedule.
     */
    public function isVisible(?Carbon $at = null): bool
    {
        return $this->isAvailable() && $this->is_active && $this->isWithinSchedule($at);
    }
}
