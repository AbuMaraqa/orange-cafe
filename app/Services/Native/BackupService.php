<?php

namespace App\Services\Native;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Takes the cafe's data out of the machine and puts it back.
 *
 * Everything that matters lives in two places — one SQLite file and the
 * uploaded images beside it — both inside a per-user application-data folder
 * that nobody would think to look in. Copying them by hand means finding
 * that folder, knowing which of database.sqlite/-wal/-shm to take, and
 * doing it with the app closed. That is not a thing a cafe owner will do
 * weekly, which means in practice it never happens until the disk dies.
 *
 * One button, one file.
 */
class BackupService
{
    /**
     * Written where the owner can actually find it again, rather than into
     * the hidden data directory the backup is protecting them from.
     */
    public function backupDirectory(): string
    {
        $home = env('NATIVEPHP_USER_HOME_PATH') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return rtrim($home, '\\/').DIRECTORY_SEPARATOR.'OrangeCafe-Backups';
    }

    /**
     * Writes a restorable snapshot and returns its full path.
     *
     * @throws RuntimeException
     */
    public function export(): string
    {
        $database = $this->databasePath();

        if (! is_file($database)) {
            throw new RuntimeException('No database file to back up.');
        }

        $directory = $this->backupDirectory();

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            throw new RuntimeException("Could not create the backup folder: {$directory}");
        }

        $path = $directory.DIRECTORY_SEPARATOR.'orange-cafe-'.now()->format('Y-m-d_His').'.zip';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the backup file: {$path}");
        }

        // Checkpoint first. In WAL mode the most recent writes — an order
        // taken a minute ago — may still be sitting in the -wal file, so a
        // copy of the .sqlite alone can be silently out of date. Folding the
        // log back in means the single file being archived is complete.
        $this->checkpoint();

        $zip->addFile($database, 'database/database.sqlite');

        $images = storage_path('app/public');

        if (is_dir($images)) {
            foreach ($this->filesIn($images) as $file) {
                $zip->addFile($file, 'storage/'.$this->relativePath($images, $file));
            }
        }

        // A marker the importer checks before overwriting anything, so a zip
        // of holiday photos cannot be restored over a restaurant.
        $zip->addFromString('orange-cafe-backup.json', json_encode([
            'created_at' => now()->toIso8601String(),
            'app_version' => config('nativephp.version'),
            'tables' => $this->tableCounts(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();

        Log::info('Backup written.', ['path' => $path, 'bytes' => filesize($path)]);

        return $path;
    }

    /**
     * Restores a snapshot over the live data.
     *
     * The caller must stop the child processes first: `artisan serve`, the
     * queue worker and the scheduler all hold the SQLite file open, and
     * replacing a file underneath a reader is how a database ends up
     * "malformed" rather than restored.
     *
     * @return array<string, int> the table counts recorded in the backup
     *
     * @throws RuntimeException
     */
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No such backup file: {$path}");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file is not a readable zip archive.');
        }

        $manifest = $zip->getFromName('orange-cafe-backup.json');

        if ($manifest === false) {
            $zip->close();

            throw new RuntimeException('That zip is not an Orange Cafe backup.');
        }

        if ($zip->locateName('database/database.sqlite') === false) {
            $zip->close();

            throw new RuntimeException('The backup has no database in it.');
        }

        // The current data is moved aside, not deleted. Restoring the wrong
        // file is a mistake people make exactly once, at the worst moment,
        // and it should be undoable.
        $database = $this->databasePath();

        if (is_file($database)) {
            $aside = $database.'.replaced-'.now()->format('Ymd_His');
            @copy($database, $aside);
            Log::info('Existing database kept aside before restore.', ['path' => $aside]);
        }

        // Stale journals describe the database being replaced, not the one
        // arriving; leaving them behind corrupts the restored file.
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($database.$suffix);
        }

        $restored = $zip->getFromName('database/database.sqlite');

        if ($restored === false || file_put_contents($database, $restored) === false) {
            $zip->close();

            throw new RuntimeException('Could not write the restored database.');
        }

        $images = storage_path('app/public');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! str_starts_with($name, 'storage/')) {
                continue;
            }

            $target = $images.DIRECTORY_SEPARATOR.substr($name, strlen('storage/'));

            if (str_ends_with($name, '/')) {
                continue;
            }

            if (! is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }

            @file_put_contents($target, $zip->getFromIndex($i));
        }

        $zip->close();

        $decoded = json_decode((string) $manifest, true);

        Log::info('Backup restored.', ['path' => $path]);

        return is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [];
    }

    /**
     * Backups already on this machine, newest first.
     *
     * @return array<int, array{name: string, path: string, size: int, created_at: string}>
     */
    public function available(): array
    {
        $directory = $this->backupDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'orange-cafe-*.zip') ?: [];

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn (string $file) => [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file) ?: 0,
            'created_at' => date('c', filemtime($file) ?: time()),
        ], $files);
    }

    private function databasePath(): string
    {
        $path = config('database.connections.'.config('database.default').'.database');

        return is_string($path) ? $path : '';
    }

    private function checkpoint(): void
    {
        try {
            DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $e) {
            Log::warning('Could not checkpoint the write-ahead log before backup.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];

        foreach (['products', 'categories', 'orders', 'users', 'restaurant_tables'] as $table) {
            try {
                $counts[$table] = DB::table($table)->count();
            } catch (\Throwable) {
                // A table that isn't there yet simply isn't reported.
            }
        }

        return $counts;
    }

    /**
     * @return iterable<string>
     */
    private function filesIn(string $directory): iterable
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            if ($item->isFile()) {
                yield $item->getPathname();
            }
        }
    }

    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', ltrim(substr($file, strlen($root)), '\\/'));
    }
}
