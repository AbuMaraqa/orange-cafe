<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->demoUser('Admin User', 'admin@orange.test', 'admin', 'ar');
        $this->demoUser('Waiter (Arabic)', 'waiter@orange.test', 'waiter', 'ar');
        $this->demoUser('Cashier (English)', 'cashier@orange.test', 'cashier', 'en');
        $this->demoUser('Kitchen Staff', 'kitchen@orange.test', 'kitchen', 'ar');
    }

    protected function demoUser(string $name, string $email, string $role, string $locale): void
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => 'password',
            'locale' => $locale,
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$role]);
    }
}
