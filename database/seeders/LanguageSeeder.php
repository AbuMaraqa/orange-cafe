<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        Language::updateOrCreate(['code' => 'ar'], [
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'direction' => Language::DIRECTION_RTL,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Language::updateOrCreate(['code' => 'en'], [
            'name' => 'English',
            'native_name' => 'English',
            'direction' => Language::DIRECTION_LTR,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
