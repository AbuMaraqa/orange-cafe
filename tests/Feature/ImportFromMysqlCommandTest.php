<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The import runs exactly once per customer, against data that has no second
 * copy, so its edge cases are worth pinning down.
 *
 * The source connection here is SQLite rather than MySQL — the command reads
 * the source purely through the schema builder and the query builder, so what
 * is under test (which tables, which columns, what order, what happens to
 * existing rows) behaves identically, and the test needs no database server.
 */
class ImportFromMysqlCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = tempnam(sys_get_temp_dir(), 'import-src').'.sqlite';
        touch($this->sourcePath);

        config(['database.connections.import_test' => [
            'driver' => 'sqlite',
            'database' => $this->sourcePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
    }

    protected function tearDown(): void
    {
        DB::purge('import_test');
        @unlink($this->sourcePath);

        parent::tearDown();
    }

    /**
     * Deliberately not the current schema: `legacy_note` is a column this
     * version no longer has, and several current columns are absent — which
     * is what an older installation actually looks like.
     */
    private function seedSource(): void
    {
        Schema::connection('import_test')->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('legacy_note')->nullable();
            $table->timestamps();
        });

        DB::connection('import_test')->table('users')->insert([
            ['id' => 1, 'name' => 'Owner', 'email' => 'owner@cafe.test', 'password' => 'hash-1', 'legacy_note' => 'gone', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Waiter', 'email' => 'waiter@cafe.test', 'password' => 'hash-2', 'legacy_note' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_it_copies_rows_and_drops_columns_the_current_schema_no_longer_has(): void
    {
        $this->seedSource();

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test'])
            ->assertSuccessful();

        $this->assertSame(2, User::count());

        $owner = User::find(1);
        $this->assertSame('owner@cafe.test', $owner->email);
        // Password hashes carry over verbatim — staff keep their logins.
        $this->assertSame('hash-1', $owner->getAuthPassword());
    }

    public function test_pretend_reports_without_writing_anything(): void
    {
        $this->seedSource();

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test', '--pretend' => true])
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    /**
     * Re-running must not silently double every order in the cafe's books.
     */
    public function test_it_asks_before_replacing_rows_that_are_already_there(): void
    {
        $this->seedSource();
        User::factory()->create();

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test'])
            ->expectsConfirmation('Continue?', 'no')
            ->assertFailed();

        $this->assertSame(1, User::count());

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test'])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertSuccessful();

        // Replaced, not appended.
        $this->assertSame(2, User::count());
    }

    public function test_it_refuses_a_source_it_cannot_reach(): void
    {
        config(['database.connections.import_test.database' => 'Z:\\nowhere\\missing.sqlite']);

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test'])
            ->assertFailed();
    }

    /**
     * A table the source has and this version dropped is not an error — it
     * just has nowhere to go.
     */
    public function test_it_ignores_source_tables_the_current_schema_dropped(): void
    {
        $this->seedSource();

        Schema::connection('import_test')->create('legacy_coupons', function ($table) {
            $table->id();
            $table->string('code');
        });
        DB::connection('import_test')->table('legacy_coupons')->insert(['id' => 1, 'code' => 'OLD']);

        $this->artisan('app:import-from-mysql', ['--connection' => 'import_test'])
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('legacy_coupons'));
        $this->assertSame(2, User::count());
    }
}
