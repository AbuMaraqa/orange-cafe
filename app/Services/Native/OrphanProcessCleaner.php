<?php

namespace App\Services\Native;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears PHP processes left behind by a previous run.
 *
 * NativePHP tries to kill its children when the app quits, and its own
 * source says how well that goes:
 *
 *     ps.kill(process.pid); // Sometimes does not kill the subprocess of php server
 *
 * When it does not — a force-quit, a power cut, Windows closing the tray app
 * without a clean shutdown — `artisan serve` and `reverb:start` survive with
 * ports 8000 and 8080 still bound. The next launch then looks broken in the
 * most confusing way available: the window opens, the services report
 * started, and nothing works, because the new servers could not bind and
 * quietly died while the old ones answer with the previous version's code.
 *
 * Nothing else can clean these up: they are not children of the running app,
 * so its process tree does not include them.
 */
class OrphanProcessCleaner
{
    /**
     * Command-line fragments that identify a process as one of ours.
     *
     * Deliberately narrow. This kills processes, so a match has to mean "the
     * app started this", never "this is a PHP process". Anything the cafe's
     * machine runs for its own reasons — a Laragon server, a developer's
     * terminal — must not be touched.
     */
    private const SIGNATURES = [
        'reverb:start',
        'queue:work',
        'queue:listen',
        'schedule:work',
        'artisan serve',
        'artisan" serve',
    ];

    /**
     * The inner server `artisan serve` spawns, which is what actually
     * survives.
     *
     * Found the hard way: after a force-quit the process still holding port
     * 8000 was not the `artisan serve` command at all but the `php -S` it
     * had started, whose command line mentions neither "artisan" nor
     * "serve". Matching only the outer command left exactly the process
     * that breaks the next launch untouched.
     *
     * Paired with the app path so a plain `php -S` belonging to Laragon or
     * anything else on the machine is never a match.
     */
    private const INNER_SERVER = '-S ';

    /**
     * Kills whatever is holding the given ports, if it looks like ours.
     *
     * @param  int[]  $ports
     * @return array<int, array{pid: int, port: int, command: string}> what was killed
     */
    public function clear(array $ports): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $killed = [];

        foreach ($ports as $port) {
            foreach ($this->pidsOnPort($port) as $pid) {
                $command = $this->commandLine($pid);

                if ($command === null || ! $this->looksLikeOurs($command)) {
                    // Something else owns this port. Leaving it alone and
                    // letting the service fail to bind is the honest
                    // outcome: the panel will show it down, which is true,
                    // rather than this quietly killing a stranger's process.
                    Log::warning('Port is held by a process this app did not start — leaving it alone.', [
                        'port' => $port,
                        'pid' => $pid,
                        'command' => $command,
                    ]);

                    continue;
                }

                if ($this->kill($pid)) {
                    $killed[] = ['pid' => $pid, 'port' => $port, 'command' => $command];

                    Log::info('Killed a leftover service process from a previous run.', [
                        'pid' => $pid,
                        'port' => $port,
                    ]);
                }
            }
        }

        return $killed;
    }

    /**
     * @return int[]
     */
    public function pidsOnPort(int $port): array
    {
        $output = $this->run('netstat -ano -p TCP');

        if ($output === null) {
            return [];
        }

        $pids = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            // Only LISTENING: an established connection to that port is a
            // client, and killing clients is not the job here.
            if (! str_contains($line, 'LISTENING')) {
                continue;
            }

            if (! preg_match('/:(\d+)\s+\S+\s+LISTENING\s+(\d+)/', $line, $matches)) {
                continue;
            }

            if ((int) $matches[1] === $port) {
                $pids[] = (int) $matches[2];
            }
        }

        return array_values(array_unique($pids));
    }

    public function looksLikeOurs(string $commandLine): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_contains($commandLine, $signature)) {
                return true;
            }
        }

        // The inner `php -S`, but only when it belongs to this installation.
        return str_contains($commandLine, self::INNER_SERVER)
            && str_contains($commandLine, $this->applicationPath());
    }

    /**
     * The directory this app runs from, used to tell our own PHP processes
     * from every other one on the machine.
     */
    private function applicationPath(): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, base_path());
    }

    private function commandLine(int $pid): ?string
    {
        $output = $this->run(sprintf(
            'powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=%d\').CommandLine"',
            $pid
        ));

        return $output === null ? null : trim($output);
    }

    private function kill(int $pid): bool
    {
        return $this->run(sprintf('taskkill /PID %d /T /F', $pid)) !== null;
    }

    /**
     * Shells out, tolerating a runtime where that is not permitted.
     */
    private function run(string $command): ?string
    {
        try {
            if (! function_exists('shell_exec')) {
                return null;
            }

            $output = @shell_exec($command);

            return is_string($output) ? $output : null;
        } catch (Throwable $e) {
            Log::warning('Could not inspect running processes.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
