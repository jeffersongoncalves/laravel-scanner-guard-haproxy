<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardHaproxy;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\ScannerGuard\Contracts\DenylistSink;
use Throwable;

/**
 * Writes the denylist as an HAProxy ACL file (one IP or CIDR per line) and reloads
 * HAProxy when the file changed. Entries arrive validated and their reason
 * sanitized (core 2.2.1+).
 */
class HaproxySink implements DenylistSink
{
    public function sync(array $entries): void
    {
        $path = base_path((string) config('scanner-guard-haproxy.path', 'storage/app/scanner-guard/haproxy-denylist.lst'));
        $contents = $this->contents($entries);

        // Left behind while a reload failed or never ran, so the next sync tries again.
        $pending = $path.'.reload';

        if (! File::exists($path) || File::get($path) !== $contents) {
            File::ensureDirectoryExists(dirname($path));

            if (! File::exists($pending)) {
                File::put($pending, '');
            }

            // Temp file + rename: an HAProxy reload mid-write never reads a truncated file.
            $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            File::put($temp, $contents);
            File::move($temp, $path);
        } elseif (! File::exists($pending)) {
            return;
        }

        if ($this->reload()) {
            File::delete($pending);
        }
    }

    /**
     * @param  list<array{ip: string, reason: string, expires_at: string|null}>  $entries
     */
    protected function contents(array $entries): string
    {
        if ($entries === []) {
            return "# nothing banned\n";
        }

        // Comments on their own line: HAProxy reads a trailing one as part of the IP.
        return implode('', array_map(
            fn (array $entry): string => ($entry['expires_at'] === null ? "# {$entry['reason']}" : "# {$entry['reason']}, expires {$entry['expires_at']}")
                ."\n{$entry['ip']}\n",
            $entries,
        ));
    }

    /**
     * false when the command failed and must run again.
     */
    protected function reload(): bool
    {
        $command = config('scanner-guard-haproxy.reload_command');

        if ($command === null || $command === '') {
            return true;
        }

        try {
            $result = Process::run((string) $command);
            $error = $result->failed() ? 'exit '.$result->exitCode().': '.trim($result->errorOutput() ?: $result->output()) : null;
        } catch (Throwable $e) {
            // Timeout or the command can't start: the file is already written, so only report it.
            $error = $e->getMessage();
        }

        if ($error !== null) {
            Log::error('scanner-guard: HAProxy reload command failed', ['command' => $command, 'error' => $error]);
        }

        return $error === null;
    }
}
