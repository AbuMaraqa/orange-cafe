<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->demoUser('Admin User', 'admin', 'admin@orange.test', 'admin', 'ar');
        $this->demoUser('Waiter (Arabic)', 'waiter', 'waiter@orange.test', 'waiter', 'ar');
        $this->demoUser('Cashier (English)', 'cashier', 'cashier@orange.test', 'cashier', 'en');
        $this->demoUser('Kitchen Staff', 'kitchen', 'kitchen@orange.test', 'kitchen', 'ar');
    }

    /**
     * Keyed on the email the demo accounts have always had, so re-seeding a
     * database from before usernames updates those rows instead of adding a
     * second set beside them.
     */
    protected function demoUser(string $name, string $username, string $email, string $role, string $locale): void
    {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'username' => $username,
            'password' => 'password',
            'locale' => $locale,
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$role]);
    }
}
