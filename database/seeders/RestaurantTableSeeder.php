<?php

namespace Database\Seeders;

use App\Models\RestaurantTable;
use Illuminate\Database\Seeder;

class RestaurantTableSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [
            ['label' => '1', 'pos_x' => 15, 'pos_y' => 20, 'shape' => 'square', 'seats' => 2],
            ['label' => '2', 'pos_x' => 35, 'pos_y' => 20, 'shape' => 'square', 'seats' => 4],
            ['label' => '3', 'pos_x' => 55, 'pos_y' => 20, 'shape' => 'round', 'seats' => 6],
            ['label' => '4', 'pos_x' => 75, 'pos_y' => 20, 'shape' => 'square', 'seats' => 4],
            ['label' => '5', 'pos_x' => 15, 'pos_y' => 55, 'shape' => 'round', 'seats' => 2],
            ['label' => '6', 'pos_x' => 35, 'pos_y' => 55, 'shape' => 'square', 'seats' => 4],
            ['label' => '7', 'pos_x' => 55, 'pos_y' => 55, 'shape' => 'square', 'seats' => 8],
            ['label' => '8', 'pos_x' => 75, 'pos_y' => 55, 'shape' => 'round', 'seats' => 4],
        ];

        // firstOrCreate, not updateOrCreate: this only fills in the demo
        // layout the first time (or for any label an admin hasn't created
        // yet) — re-running db:seed must never snap a table an admin has
        // since dragged, resized, or renamed back to these defaults.
        foreach ($tables as $table) {
            RestaurantTable::firstOrCreate(['label' => $table['label']], [...$table, 'is_active' => true]);
        }
    }
}
