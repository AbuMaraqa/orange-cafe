<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Staff roles
    |--------------------------------------------------------------------------
    |
    | Lightweight role registry used to route a user to their interface after
    | login and to guard admin/waiter/cashier/kitchen routes. Not part of the
    | multilingual architecture — kept dynamic here (rather than scattered
    | if/else checks) so a new role only needs an entry in this file.
    |
    */
    'available' => [
        'admin' => ['label' => 'Administrator', 'home_route' => 'dashboard'],
        'waiter' => ['label' => 'Waiter', 'home_route' => 'waiter.index'],
        'cashier' => ['label' => 'Cashier', 'home_route' => 'cashier.index'],
        'kitchen' => ['label' => 'Kitchen', 'home_route' => 'kitchen.index'],
    ],

    'default' => 'admin',

];
