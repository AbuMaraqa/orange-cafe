<?php

return [

    'expense' => [
        'created' => 'Expense recorded.',
        'updated' => 'Expense updated.',
        'deleted' => 'Expense deleted.',
    ],

    'expense_category' => [
        'created' => 'Category added.',
        'updated' => 'Category updated.',
        'deleted' => 'Category deleted.',
        'in_use' => 'This category cannot be deleted while expenses are filed under it.',
    ],

    'absence' => [
        'created' => 'Absence recorded.',
        'updated' => 'Absence updated.',
        'deleted' => 'Absence deleted.',
    ],

    'absence_category' => [
        'created' => 'Absence category added.',
        'updated' => 'Absence category updated.',
        'deleted' => 'Absence category deleted.',
        'in_use' => 'This category cannot be deleted while absences are filed under it.',
    ],

    'cash' => [
        'recorded' => 'Movement recorded.',
        'deleted' => 'Movement deleted.',
        'linked_cannot_delete' => 'This movement belongs to an expense — delete the expense itself.',
    ],

    'staff' => [
        'posted' => 'Posted to the staff account.',
        'deleted' => 'Removed from the staff account.',
        'linked_cannot_delete' => 'This entry comes from an absence or an expense — delete the original record.',
    ],

    'day_closed' => 'Day closed.',
    'day_reopened' => 'Day reopened.',
    'day_already_closed' => 'This day is already closed.',
    'day_report_printed' => 'The day report was sent to the till printer.',
    // Carries the reason: "printing failed" on its own sends a cashier
    // looking for paper rather than for the printer.
    'day_report_failed' => 'The day report could not be printed — :error',
    'day_not_closed' => 'This day is not closed.',
    'day_is_closed' => 'A closed day cannot be edited. Reopen it first.',

];
