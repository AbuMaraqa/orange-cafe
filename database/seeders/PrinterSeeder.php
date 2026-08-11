<?php

namespace Database\Seeders;

use App\Models\Printer;
use Illuminate\Database\Seeder;

class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        // Demo IPs — not reachable from this environment; the app degrades
        // gracefully (logs a warning) if a printer can't be reached.
        $printers = [
            ['name' => 'Grill Station Printer', 'station' => 'grill', 'ip_address' => '192.168.1.101', 'port' => 9100],
            ['name' => 'Hot Kitchen Printer', 'station' => 'kitchen', 'ip_address' => '192.168.1.102', 'port' => 9100],
            ['name' => 'Bar Printer', 'station' => 'bar', 'ip_address' => '192.168.1.103', 'port' => 9100],
            ['name' => 'Dessert Station Printer', 'station' => 'dessert', 'ip_address' => '192.168.1.104', 'port' => 9100],
        ];

        foreach ($printers as $printer) {
            Printer::updateOrCreate(['name' => $printer['name']], [...$printer, 'is_active' => true]);
        }
    }
}
