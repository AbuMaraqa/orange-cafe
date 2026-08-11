<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live order feed for staff dashboards (waiter/cashier/kitchen/admin) —
// customers place orders over plain HTTP, never subscribe to this channel.
Broadcast::channel('orders', function ($user) {
    return $user->hasAnyRole(['waiter', 'cashier', 'kitchen', 'admin']);
});
