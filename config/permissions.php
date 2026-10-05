<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Money permissions
    |--------------------------------------------------------------------------
    |
    | The rest of this app is guarded by role (`role:admin`), which was enough
    | while every admin screen was equally safe to open. Money is not: a
    | manager needs to file expenses and count the till, and must not be able
    | to reopen a day that has already been signed off and quietly change what
    | it said.
    |
    | So the accounting screens are guarded by permission rather than by role.
    | Spatie is already installed and its tables already migrated; this is the
    | list, and PermissionSeeder grants it to the roles below. Nothing outside
    | app/Http/Controllers/Admin/Accounting* consults it, so no existing route
    | changes behaviour.
    |
    */
    'available' => [
        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.delete',
        'expense_categories.manage',

        'absences.view',
        'absences.create',
        'absences.update',
        'absences.delete',
        'absence_categories.manage',

        'cashbox.view',
        'cashbox.collect',
        'cashbox.discount',
        'cashbox.close',

        // The dangerous one, and the reason this file exists at all.
        'cashbox.reopen',

        'staff_accounts.view',
        'staff_accounts.post',

        'reports.view',

        // The catalog: menus, sections, products, add-ons, tags, the call
        // bell's services and the service-charge price list. Not money in
        // the sense above — nothing here touches the takings or the drawer —
        // but guarded the same way, so the till can be given it without
        // being given the rest of the admin area.
        'catalog.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Who gets what
    |--------------------------------------------------------------------------
    |
    | '*' means every permission above. A role missing from this list gets
    | none, which is the safe default for waiter and kitchen — neither has any
    | reason to see the payroll.
    |
    */
    'roles' => [
        'admin' => '*',

        // The catalog, and nothing on the money side. The money side of the
        // business is the owner's, and the till is not a window into it.
        //
        // The catalog is the till's because the person at the till is the
        // one who hears "we're out of the kunafa" and "the new juice is 12
        // now" first, and is standing next to a screen when they do. Adding
        // a product or changing a price does not touch anything already on a
        // bill — a line keeps the price it was rung up at.
        //
        // This does not touch what a cashier does all evening: taking
        // payment and applying a discount are guarded by *role*
        // (`role:cashier|admin` on orders/{order}/pay and
        // orders/{order}/discount), not by anything in this file, so the
        // till works exactly as it did.
        //
        // What it does take away is the accounting section — the cash box
        // screen, expenses, staff absences, the reports, and the money strip
        // on the dashboard (DashboardController reads `cashbox.view` for
        // that, and nothing else).
        //
        // Worth knowing rather than discovering: `cashbox.close` is in here
        // too, so the nightly closing is now an admin's job. Somebody with
        // the admin role has to be present, or sign the day off afterwards.
        'cashier' => ['catalog.manage'],
    ],

];
