<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff don't have email addresses, and their names aren't in English. An
 * admin types "محمد مرقة" and a password, and that is the whole account.
 */
class StaffLogInWithAUsernameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_an_admin_creates_an_arabic_username_with_no_email_and_it_logs_in(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'محمد مرقة',
                'username' => 'محمد مرقة',
                'password' => '123456789',
                'role' => 'waiter',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('username', 'محمد مرقة')->firstOrFail();
        $this->assertNull($user->email);
        $this->assertTrue($user->hasRole('waiter'));

        Auth::logout();

        $this->post('/login', ['username' => 'محمد مرقة', 'password' => '123456789']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_stray_spaces_around_and_inside_the_username_still_match(): void
    {
        $user = User::factory()->create(['username' => 'محمد مرقة']);

        $this->post('/login', ['username' => '  محمد   مرقة ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_latin_usernames_ignore_case(): void
    {
        $user = User::factory()->create(['username' => 'Ahmad']);

        $this->post('/login', ['username' => 'ahmad', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused_against_the_username_field(): void
    {
        User::factory()->create(['username' => 'محمد مرقة']);

        $this->post('/login', ['username' => 'محمد مرقة', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_two_people_cannot_share_a_username(): void
    {
        User::factory()->create(['username' => 'محمد مرقة']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'محمد آخر',
                'username' => 'محمد  مرقة',
                'password' => '123456789',
                'role' => 'waiter',
            ])
            ->assertSessionHasErrors('username');

        $this->assertSame(1, User::where('username', 'محمد مرقة')->count());
    }

    public function test_editing_a_user_can_rename_them_without_touching_the_password(): void
    {
        $user = User::factory()->create(['username' => 'waiter@orange.test']);
        $user->assignRole('waiter');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'محمد مرقة',
                'username' => 'محمد مرقة',
                'password' => '',
                'role' => 'waiter',
            ])
            ->assertSessionHasNoErrors();

        Auth::logout();

        $this->post('/login', ['username' => 'محمد مرقة', 'password' => 'password']);

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_accounts_copied_in_from_before_usernames_log_in_with_their_old_email(): void
    {
        // As the MySQL import and the legacy seeder write them: straight
        // into the table, no username column in the source.
        DB::table('users')->insert([
            'name' => 'Old Cashier',
            'email' => 'cashier@orange.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::fillMissingUsernamesFromEmail();

        $this->post('/login', ['username' => 'cashier@orange.test', 'password' => 'password']);

        $this->assertAuthenticated();
    }
}
