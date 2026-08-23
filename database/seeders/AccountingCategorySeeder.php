<?php

namespace Database\Seeders;

use App\Models\AbsenceCategory;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * The starting list of categories, so the first expense can be filed
 * without a detour through a settings screen.
 *
 * firstOrCreate on the name, so re-running never duplicates and never
 * overwrites a category the cafe has since renamed or switched off.
 */
class AccountingCategorySeeder extends Seeder
{
    public function run(): void
    {
        $expenses = [
            'رواتب',
            'مشتريات',
            'إيجار',
            'كهرباء وماء',
            'صيانة',
            'مواصلات',
            'أدوات ومستلزمات',
            'تسويق',
            'سلفة',
            'أخرى',
        ];

        foreach ($expenses as $index => $name) {
            ExpenseCategory::firstOrCreate(['name' => $name], ['sort_order' => $index]);
        }

        // default_deduction_cents is only a suggestion the form pre-fills;
        // zero here means "ask every time", which is the honest default for
        // categories whose cost is always a judgement call.
        $absences = [
            ['غياب بدون عذر', 0, true],
            ['غياب بعذر', 0, false],
            ['إجازة مرضية', 0, false],
            ['إجازة شخصية', 0, false],
            ['تأخير', 0, true],
            ['مغادرة مبكرة', 0, true],
            ['أخرى', 0, true],
        ];

        foreach ($absences as $index => [$name, $default, $affectsPay]) {
            AbsenceCategory::firstOrCreate(['name' => $name], [
                'default_deduction_cents' => $default,
                'affects_pay' => $affectsPay,
                'sort_order' => $index,
            ]);
        }
    }
}
