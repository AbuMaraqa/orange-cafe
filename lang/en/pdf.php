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

    // How the money arrived, printed on the customer's copy. The reference is
    // the card terminal's own number for the charge, so a disputed line on a
    // statement can be traced back to this bill without a day's paperwork.
    'payment_method' => 'Payment Method',
    'payment_reference' => 'Reference No.',
    'payment_methods' => [
        'cash' => 'Cash',
        'card' => 'Visa / Card',
    ],

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
        'collected' => 'Cash from orders',
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
        'card_collected' => 'Card takings',
        'bill_discounts' => 'Bill discounts',
        'not_in_drawer' => 'For information — not taken from the drawer',

        // What went out of the kitchen and the bar tonight, read after the
        // drawer has been counted. Computed in Cashbox::itemsSold() from the
        // bills settled today.
        'items_sold' => 'Items sold',
        'items_none' => 'No items on this day’s bills',
        'items_quantity' => 'Total quantity',
        'gross_sales' => 'Gross sales',
        'sales_discount' => 'Bill discounts',
        'net_sales' => 'Net sales',
        'bills_count' => 'Bills',

        // Every bill that paid today, one by one, with how it was paid. The
        // totals under the list are the cash box's own figures (cash = cash
        // from orders, card = card takings), read off the same payments —
        // Cashbox::ordersCollected().
        'orders_detail' => 'Orders',
        'orders_none' => 'No orders were paid on this day',
        'split_payment' => 'Split',
        'part_payment' => 'Part payment',
        'refund' => 'Refund',
        'payment_summary' => 'Totals by payment method',
        'orders_total' => 'Total collected',

        'day_closed' => 'Day closed',
        'day_open' => 'Day still open',
        'closed_by' => 'Closed by',
        'closing_notes' => 'Closing notes',

        'provisional' => 'Provisional — the day has not been closed yet',
    ],
];
