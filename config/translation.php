<?php

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
        \App\Models\Category::class,
        \App\Models\Product::class,
        \App\Models\ModifierGroup::class,
        \App\Models\Modifier::class,
        \App\Models\Tag::class,
    ],

];
