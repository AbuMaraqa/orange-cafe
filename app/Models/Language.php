<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(\App\Observers\LanguageObserver::class)]
class Language extends Model
{
    use HasFactory;

    public const DIRECTION_LTR = 'ltr';
    public const DIRECTION_RTL = 'rtl';

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isRtl(): bool
    {
        return $this->direction === self::DIRECTION_RTL;
    }
}
