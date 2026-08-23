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
        'ready' => ['color' => 'teal'],
        'completed' => ['color' => 'green'],
        'cancelled' => ['color' => 'red'],
    ],

    'default_status' => 'pending',

    // Statuses that count as "still active" on a table (i.e. the table
    // reads as occupied while any of its orders are in one of these).
    'active_statuses' => ['pending', 'confirmed', 'ready'],

    /*
    |--------------------------------------------------------------------------
    | Who may open a table
    |--------------------------------------------------------------------------
    |
    | Roles allowed to type an order in on a customer's behalf, from the floor
    | plan on their own screen. An order from any of them is born confirmed
    | and prints immediately — the person entering it is looking at the
    | customer, so there is nobody left to review it.
    |
    | A customer's own order (from the QR menu) is not this, and stays
    | 'pending' until a waiter confirms it.
    |
    | Listed here rather than repeated across the routes, OrderController and
    | the Vue screens, because a role that can reach the button and does not
    | count as staff gets the worst outcome available: the order is taken, is
    | recorded as unconfirmed, and never reaches the kitchen.
    |
    */
    'placed_by_roles' => ['waiter', 'cashier', 'admin'],

];
