<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            RestaurantSettingsSeeder::class,
            CatalogSeeder::class,
            ImportedDishesSeeder::class,
            RestaurantTableSeeder::class,
            PrinterSeeder::class,
        ]);
    }
}
