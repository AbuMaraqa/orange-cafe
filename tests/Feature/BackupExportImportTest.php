<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Native\BackupService;
use Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A backup has to survive the only test that matters: destroy the data, put
 * the file back, and find the cafe exactly as it was.
 */
class BackupExportImportTest extends TestCase
{
    /**
     * A real file on disk, not the suite's usual :memory: database — the
     * thing under test is copying a database file, and an in-memory one has
     * no file to copy.
     */
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backup-test-'.uniqid().'.sqlite';
        touch($this->databaseFile);

        config(['database.connections.sqlite.database' => $this->databaseFile]);
        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect();

        foreach (glob(app(BackupService::class)->backupDirectory().'/orange-cafe-*.zip') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->databaseFile.'*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function product(string $name): Product
    {
        return Product::create([
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 2500,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    public function test_a_backup_file_is_written(): void
    {
        $this->seed(LanguageSeeder::class);
        $this->product('شاورما');

        $path = app(BackupService::class)->export();

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.zip', $path);
        $this->assertGreaterThan(1000, filesize($path));
    }

    public function test_the_backup_carries_a_manifest_and_the_database(): void
    {
        $this->seed(LanguageSeeder::class);
        $this->product('فلافل');

        $zip = new \ZipArchive;
        $zip->open(app(BackupService::class)->export());

        $manifest = json_decode((string) $zip->getFromName('orange-cafe-backup.json'), true);

        $this->assertNotFalse($zip->locateName('database/database.sqlite'));
        $this->assertSame(1, $manifest['tables']['products']);
        $this->assertArrayHasKey('created_at', $manifest);

        $zip->close();
    }

    public function test_restoring_brings_deleted_data_back(): void
    {
        $this->seed(LanguageSeeder::class);
        $this->product('حمص');
        $this->product('متبل');

        $backups = app(BackupService::class);
        $path = $backups->export();

        // The disaster: someone empties the catalog.
        DB::table('products')->delete();
        $this->assertSame(0, Product::count());

        // SQLite keeps the file open for this connection; a restore in the
        // packaged app stops the services first, which the test stands in
        // for by dropping the connection.
        DB::disconnect();

        $tables = $backups->import($path);

        $this->assertSame(2, DB::table('products')->count(), 'both products must be back');
        $this->assertSame(2, $tables['products'], 'the manifest reports what was in the backup');
    }

    public function test_a_zip_that_is_not_a_backup_is_refused(): void
    {
        $path = sys_get_temp_dir().'/not-a-backup-'.uniqid().'.zip';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('holiday.txt', 'photos');
        $zip->close();

        $this->expectException(\RuntimeException::class);

        try {
            app(BackupService::class)->import($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        app(BackupService::class)->import(sys_get_temp_dir().'/does-not-exist.zip');
    }

    public function test_available_lists_the_newest_first(): void
    {
        $this->seed(LanguageSeeder::class);
        $backups = app(BackupService::class);

        $first = $backups->export();
        sleep(1);
        $second = $backups->export();

        $listed = array_column($backups->available(), 'name');

        $this->assertSame(basename($second), $listed[0]);
        $this->assertContains(basename($first), $listed);
    }
}
