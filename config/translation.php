<?php

use App\Models\Category;
use App\Models\Menu;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;

return [

    /*
    |--------------------------------------------------------------------------
    | Translatable models
    |--------------------------------------------------------------------------
    |
    | Every Eloquent model that uses Astrotomic\Translatable\Translatable and
    | should be covered by the admin "missing translations" audit and by the
    | safe-delete check on languages (a language can't be deleted while any
    | of these models still has rows translated into it). Register new
    | translatable models here — no other code needs to change.
    |
    */
    'translatable_models' => [
        Menu::class,
        Category::class,
        Product::class,
        ModifierGroup::class,
        Modifier::class,
        Tag::class,
    ],

];
