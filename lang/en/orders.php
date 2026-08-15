<?php

return [
    'status_updated' => 'Order status updated.',
    'marked_paid' => 'Order marked as paid.',
    'item_removed' => 'Item removed from the order.',
    'confirmed' => 'Order confirmed and sent to the kitchen.',
    'print_queued' => 'Order sent to the kitchen printer — if it\'s offline right now, it will be resent automatically once it reconnects.',
    'table_moved' => 'Order moved to the new table.',
    'tables_merged' => 'Order merged into the destination table\'s order.',
    'merged_into' => 'merged into :reference',
    'cannot_move_paid' => 'This order is already paid and can\'t be moved or merged — settle a new table instead.',
    'cannot_merge_into_paid' => 'The destination table\'s order is already paid — it can\'t receive merged items.',
    'move_table_same' => 'That order is already on this table.',
];
