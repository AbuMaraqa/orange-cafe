<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'name'];
}
