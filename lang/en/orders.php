<?php

return [
    'status_updated' => 'Order status updated.',
    'marked_paid' => 'Order marked as paid.',
    'partially_paid' => 'Received :paid — :remaining still owing. The table stays open.',
    'discount_updated' => 'Discount updated.',
    'service_charge_added' => 'Service charge added to the bill.',
    'item_price_updated' => 'Item price updated.',
    'item_removed' => 'Item removed from the order.',
    'confirmed' => 'Order confirmed and sent to the kitchen.',
    'print_queued' => 'Order sent to the kitchen printer — if it\'s offline right now, it will be resent automatically once it reconnects.',
    'table_moved' => 'Order moved to the new table.',
    'tables_merged' => 'Order merged into the destination table\'s order.',
    'merged_into' => 'merged into :reference',
    'cannot_move_paid' => 'This order is already paid and can\'t be moved or merged — settle a new table instead.',
    'cannot_merge_into_paid' => 'The destination table\'s order is already paid — it can\'t receive merged items.',
    'move_table_same' => 'That order is already on this table.',

    // Printing outcomes. 'print_queued' is the only one of the three that
    // means paper is coming.
    'print_no_printer' => 'No active printer for station: :stations — these items will not reach the kitchen. Check the printer settings.',
    'print_nothing_to_send' => 'There is nothing to send for this order.',

    // Cancelling a table: clearing it without charging for it.
    'cancel_order' => 'Cancel table',
    'cancelled' => 'Order cancelled and the table is free.',
    'cancelled_after_printing' => 'Order cancelled — but its ticket already reached the kitchen, so tell them yourself.',
    'cannot_cancel_paid' => 'This order is already paid and cannot be cancelled — a correction after payment is a refund.',
    'already_cancelled' => 'This order is already cancelled.',
    'cannot_edit_closed' => 'Lines cannot be changed on a closed order — it is completed or cancelled. Use a discount, or cancel the table.',
];
