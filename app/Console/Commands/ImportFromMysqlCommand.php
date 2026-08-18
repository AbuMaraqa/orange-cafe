<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-off migration of an existing MySQL installation's data into the
 * SQLite database this app now runs on.
 *
 * Only tables that exist on BOTH sides are copied, column-by-column, so a
 * source database from an older version (missing a column the current schema
 * added) imports cleanly instead of failing on the first INSERT. The
 * destination schema is created by the normal migrations, which run first —
 * this command never touches DDL.
 *
 * Usage (source connection comes from the IMPORT_DB_* keys in .env):
 *   php artisan app:import-from-mysql
 *   php artisan app:import-from-mysql --pretend
 */
class ImportFromMysqlCommand extends Command
{
    protected $signature = 'app:import-from-mysql
                            {--connection=mysql_import : Source connection name}
                            {--chunk=500 : Rows to read and insert per batch}
                            {--pretend : Report what would be copied without writing anything}';

    protected $description = 'Copy data from an existing MySQL database into this installation\'s SQLite database.';

    /**
     * Written by the migration runner itself. Copying it would replay the
     * source's history over a database that already has its own correct
     * history, and could make a later `migrate` skip a migration that never
     * actually ran here.
     */
    private const SKIPPED_TABLES = ['migrations'];

    /**
     * Order matters: a child row can't be inserted before the parent it
     * references while foreign keys are enforced. Anything not listed here
     * is copied afterwards, alphabetically.
     */
    private const ORDERED_TABLES = [
        'users', 'languages', 'roles', 'permissions',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'menus', 'categories', 'category_translations',
        'products', 'product_translations',
        'modifier_groups', 'modifier_group_translations',
        'modifiers', 'modifier_translations',
        'product_modifier_group', 'product_modifier_overrides',
        'tags', 'tag_translations', 'product_tag',
        'menu_items',
        'services', 'service_translations',
        'printers', 'restaurant_tables',
        'orders', 'order_items', 'order_item_modifiers',
        'waiter_calls', 'feedback', 'settings',
    ];

    public function handle(): int
    {
        $source = (string) $this->option('connection');
        $pretend = (bool) $this->option('pretend');
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            DB::connection($source)->getPdo();
        } catch (Throwable $e) {
            $this->error("Can't reach the source database on connection [{$source}]: {$e->getMessage()}");
            $this->line('Check the IMPORT_DB_* keys in .env, and that MySQL is running.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('The destination connection is not SQLite — refusing to run.');
            $this->line('Set DB_CONNECTION=sqlite in .env first; see docs/desktop-build.md.');

            return self::FAILURE;
        }

        $tables = $this->tablesToCopy($source);

        if ($tables === []) {
            $this->warn('No tables found on both sides — nothing to copy.');

            return self::SUCCESS;
        }

        if (! $pretend && ! $this->confirmDestructiveRun($tables)) {
            return self::FAILURE;
        }

        $summary = [];

        // Foreign keys are switched off for the duration rather than relying
        // purely on ORDERED_TABLES: self-referencing tables (categories'
        // parent_id) can't be satisfied by any table ordering, since the
        // parent row may sort after the child within the same table.
        $this->withoutForeignKeys(function () use ($tables, $source, $chunk, $pretend, &$summary) {
            foreach ($tables as $table) {
                $summary[] = $this->copyTable($table, $source, $chunk, $pretend);
            }
        });

        $this->newLine();
        $this->table(['Table', 'Rows', 'Columns skipped'], $summary);

        if ($pretend) {
            $this->comment('Pretend run — nothing was written.');
        } else {
            $this->info('Import complete.');
            $this->line('Log in with the accounts from the old installation; passwords carried over unchanged.');
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function tablesToCopy(string $source): array
    {
        $sourceTables = collect(Schema::connection($source)->getTables())
            ->pluck('name')
            ->reject(fn (string $table) => in_array($table, self::SKIPPED_TABLES, true))
            // Only tables the current schema still has. A table dropped by a
            // later migration has nowhere to go and is not an error.
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->values();

        $ordered = collect(self::ORDERED_TABLES)->filter(fn (string $t) => $sourceTables->contains($t));
        $rest = $sourceTables->diff($ordered)->sort()->values();

        return $ordered->merge($rest)->all();
    }

    /**
     * @param  string[]  $tables
     */
    private function confirmDestructiveRun(array $tables): bool
    {
        $existing = collect($tables)->filter(fn (string $table) => DB::table($table)->exists());

        if ($existing->isEmpty()) {
            return true;
        }

        $this->warn('These destination tables already contain rows and will be emptied first:');
        $this->line('  '.$existing->implode(', '));

        return $this->confirm('Continue?', false);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function copyTable(string $table, string $source, int $chunk, bool $pretend): array
    {
        $sourceColumns = Schema::connection($source)->getColumnListing($table);
        $targetColumns = Schema::getColumnListing($table);

        $shared = array_values(array_intersect($sourceColumns, $targetColumns));
        $dropped = array_values(array_diff($sourceColumns, $targetColumns));

        if ($shared === []) {
            return [$table, 'skipped (no shared columns)', implode(', ', $dropped)];
        }

        $total = DB::connection($source)->table($table)->count();

        if ($pretend) {
            return [$table, $total.' (pretend)', $dropped === [] ? '—' : implode(', ', $dropped)];
        }

        DB::table($table)->delete();

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(" {$table}: %current%/%max% [%bar%]");
        $bar->start();

        // orderBy is required for chunk() to page deterministically. Not
        // every table has an `id` (pivots such as product_tag don't), so the
        // first shared column is the fallback — any stable ordering works.
        $cursorColumn = in_array('id', $shared, true) ? 'id' : $shared[0];

        DB::connection($source)
            ->table($table)
            ->select($shared)
            ->orderBy($cursorColumn)
            ->chunk($chunk, function ($rows) use ($table, $bar) {
                DB::table($table)->insert(
                    $rows->map(fn ($row) => (array) $row)->all()
                );

                $bar->advance($rows->count());
            });

        $bar->finish();
        $this->newLine();

        return [$table, (string) $total, $dropped === [] ? '—' : implode(', ', $dropped)];
    }

    private function withoutForeignKeys(callable $work): void
    {
        // SQLite only honours this pragma outside a transaction, so this
        // deliberately does not wrap the import in one. A failed import is
        // recoverable by deleting the SQLite file and re-running; a pragma
        // that silently did nothing is not.
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $work();
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
