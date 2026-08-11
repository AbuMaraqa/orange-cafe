<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Order statuses
    |--------------------------------------------------------------------------
    |
    | The workflow an order moves through. Kept in config (not a DB enum) so
    | a new status doesn't require a migration. 'color' is a Tailwind badge
    | color used consistently across the waiter/cashier/kitchen UIs.
    |
    */
    'statuses' => [
        'pending' => ['color' => 'amber'],
        'confirmed' => ['color' => 'blue'],
        'preparing' => ['color' => 'indigo'],
        'ready' => ['color' => 'teal'],
        'completed' => ['color' => 'green'],
        'cancelled' => ['color' => 'red'],
    ],

    'default_status' => 'pending',

    // Statuses that count as "still active" on a table (i.e. the table
    // reads as occupied while any of its orders are in one of these).
    'active_statuses' => ['pending', 'confirmed', 'preparing', 'ready'],

];
