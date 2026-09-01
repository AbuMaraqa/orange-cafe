<?php

return [

    // Printed beside each dish on the kitchen ticket.
    'minutes' => 'min',
    'invoice' => 'Invoice',
    'date' => 'Date',
    'table' => 'Table',
    'guests' => 'Guests',
    'customer' => 'Customer',
    'item' => 'Item',
    'qty' => 'Qty',
    'unit_price' => 'Unit Price',
    'subtotal' => 'Subtotal',
    'total' => 'Total',
    'payment_status' => 'Payment Status',
    'paid' => 'Paid',
    'unpaid' => 'Unpaid',
    'amount_paid' => 'Amount Paid',
    'change_due' => 'Change Due',
    'order_discount' => 'Discount',
    'discount' => 'Discount',
    'thank_you' => 'Thank you for your visit!',
    'scan_instruction' => 'Scan to view the menu and order',
    'scan_service_instruction' => 'Scan to request :service — no need to open the menu',
    'wifi_heading' => 'Free Wi-Fi',
    'wifi_network' => 'Network',
    'wifi_instruction' => 'Scan to join our Wi-Fi',
    'note' => 'Note',
    'added_round' => 'Added to an earlier order',
    'order_note' => 'Order note',

    // The printable customer menu (pdf.customer-menu) — the version of
    // the catalog that gets sent to someone who is not in the room.
    'page' => 'Page',
    'phone' => 'Phone',
    'menu_empty' => 'No published menu items yet.',

    // The day's cash box on the till printer (pdf.day-report-thermal). The
    // wording is lifted from the cash box screen: the person holding the
    // paper is the person who read the screen, and two names for one figure
    // makes them doubt one of them.
    'day_report' => [
        'title' => 'Cash box report',
        'business_date' => 'Business day',
        'printed_at' => 'Printed at',
        'printed_by' => 'Printed by',

        'opening' => 'Opening float',
        'collected' => 'Collected from orders',
        'manual_in' => 'Cash in',
        'total_in' => 'Total in',
        'expenses' => 'Cash expenses',
        'manual_out' => 'Cash out',
        'total_out' => 'Total out',
        'expected' => 'Expected in drawer',

        'counted' => 'Counted',
        'difference' => 'Difference',
        'over' => 'over',
        'short' => 'short',
        'balanced' => 'balanced',

        'orders_count' => 'Orders',
        'staff_deductions' => 'Staff deductions',
        'bill_discounts' => 'Bill discounts',
        'not_in_drawer' => 'For information — not taken from the drawer',

        'day_closed' => 'Day closed',
        'day_open' => 'Day still open',
        'closed_by' => 'Closed by',
        'closing_notes' => 'Closing notes',

        'provisional' => 'Provisional — the day has not been closed yet',
    ],
];
