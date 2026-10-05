<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The real Orange Cafe catalog and staff, captured from the MySQL database
 * the cafe ran before the desktop app — 148 products with their Arabic and
 * English translations, the categories and menus they hang off, modifier
 * groups, tags, printers, tables, services and the four staff accounts.
 *
 * This exists because `app:import-from-mysql` cannot help on the machine
 * that actually needs the data: the packaged .exe ships without MySQL, and
 * the cafe's server has no terminal to run an import from anyway. The rows
 * are frozen into data/legacy-orange-data.json at build time instead, so a
 * fresh install comes up with the real menu already in it.
 *
 * Regenerate the JSON from a machine that can still reach the old database
 * (see the export script referenced in docs/desktop-build.md) — it is data,
 * not code, and is meant to be replaced wholesale rather than edited.
 */
class LegacyOrangeDataSeeder extends Seeder
{
    /**
     * Where the export lands. Kept beside the seeder rather than in
     * storage/: storage is relocated to a per-user data directory in the
     * packaged app, while database/ is shipped inside it.
     */
    private const DATA_FILE = __DIR__.'/data/legacy-orange-data.json';

    public function run(): void
    {
        if (! is_file(self::DATA_FILE)) {
            throw new RuntimeException('Legacy export not found: '.self::DATA_FILE);
        }

        $data = json_decode((string) file_get_contents(self::DATA_FILE), true);

        if (! is_array($data)) {
            throw new RuntimeException('Legacy export is not valid JSON: '.self::DATA_FILE);
        }

        // Off for the whole run rather than relying on the export's table
        // order alone: `categories.parent_id` points at the same table, so a
        // child can precede its parent within one table's rows and no
        // ordering of tables can fix that.
        $this->withoutForeignKeys(function () use ($data) {
            foreach ($data as $table => $rows) {
                $this->seedTable((string) $table, (array) $rows);
            }
        });

        // The export predates usernames.
        User::fillMissingUsernamesFromEmail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function seedTable(string $table, array $rows): void
    {
        if ($rows === [] || ! Schema::hasTable($table)) {
            $this->command?->getOutput()->writeln("  <fg=gray>skipped {$table} (no such table here)</>");

            return;
        }

        // Column-by-column rather than wholesale, so an export taken from an
        // older schema (missing a column a later migration added) still loads
        // instead of failing on the first row.
        $columns = Schema::getColumnListing($table);
        $hasId = in_array('id', $columns, true);

        // Rows this seeder has already handed over once, whatever became of
        // them since. A product the cafe deleted must stay deleted: it is
        // absent from the table, so without this record the next run would
        // read that absence as "new" and put the dish back on the menu.
        $alreadyImported = $hasId ? $this->importedIds($table) : [];

        $prepared = [];
        $freshIds = [];

        foreach ($rows as $row) {
            $id = $hasId ? (string) ($row['id'] ?? '') : null;

            if ($id !== null && $id !== '' && isset($alreadyImported[$id])) {
                continue;
            }

            $prepared[] = array_intersect_key($row, array_flip($columns));

            if ($id !== null && $id !== '') {
                $freshIds[] = $id;
            }
        }

        if ($prepared === []) {
            $this->command?->getOutput()->writeln(sprintf('  <fg=gray>%s — nothing new</>', $table));

            return;
        }

        // insertOrIgnore, never update and never delete: by now the cafe may
        // have corrected a price or renamed a dish, and a row that is already
        // there must keep exactly the values it has. upsert would silently
        // revert every one of those edits to the frozen export.
        foreach (array_chunk($prepared, 100) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }

        $this->logImported($table, $freshIds);

        $this->command?->getOutput()->writeln(sprintf('  <info>%s</info> %d new rows', $table, count($prepared)));
    }

    /**
     * Source ids already imported for a table, as a lookup map.
     *
     * @return array<string, true>
     */
    private function importedIds(string $table): array
    {
        if (! Schema::hasTable('legacy_import_log')) {
            return [];
        }

        return DB::table('legacy_import_log')
            ->where('source_table', $table)
            ->pluck('source_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * @param  string[]  $ids
     */
    private function logImported(string $table, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable('legacy_import_log')) {
            return;
        }

        $now = now();

        $rows = array_map(fn (string $id) => [
            'source_table' => $table,
            'source_id' => $id,
            'imported_at' => $now,
        ], $ids);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('legacy_import_log')->insertOrIgnore($chunk);
        }
    }

    private function withoutForeignKeys(callable $work): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            Schema::disableForeignKeyConstraints();
        }

        try {
            $work();
        } finally {
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
}
