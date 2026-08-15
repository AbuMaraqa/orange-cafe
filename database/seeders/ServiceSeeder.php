<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Seeds the request types shown on the customer menu's "call waiter"
 * sheet. "call-waiter" is the direct equivalent of the old single-purpose
 * button — kept first and un-deletable in spirit (nothing stops an admin
 * removing it, but the generic WaiterCall fallback still works with no
 * service at all either way). The rest demonstrate the dynamic system
 * with requests real front-of-house staff actually get asked for.
 */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['slug' => 'call-waiter', 'icon' => '🔔', 'sort_order' => 0, 'ar' => 'نداء النادل', 'en' => 'Call Waiter'],
            ['slug' => 'request-bill', 'icon' => '🧾', 'sort_order' => 1, 'ar' => 'طلب الفاتورة', 'en' => 'Request the Bill'],
            ['slug' => 'extra-chair', 'icon' => '🪑', 'sort_order' => 2, 'ar' => 'كرسي إضافي', 'en' => 'Extra Chair'],
            ['slug' => 'clean-table', 'icon' => '🧹', 'sort_order' => 3, 'ar' => 'تنظيف الطاولة', 'en' => 'Clean the Table'],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['slug' => $service['slug']], [
                'icon' => $service['icon'],
                'is_active' => true,
                'sort_order' => $service['sort_order'],
                'ar' => ['name' => $service['ar']],
                'en' => ['name' => $service['en']],
            ]);
        }
    }
}
