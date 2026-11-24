<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\ScannerGuardHaproxy\HaproxySink;

// Needs Docker: SERVER_TESTS=1 vendor/bin/pest --filter=RealServer

afterEach(function () {
    File::deleteDirectory(storage_path('app/scanner-guard'));
});

it('blocks banned ips, and only those, on a real server', function (array $entries, bool $served) {
    (new HaproxySink)->sync($entries);
    File::put(storage_path('app/scanner-guard/server.conf'), <<<'CONF'
        defaults
            mode http
            timeout connect 1s
            timeout client 1s
            timeout server 1s

        frontend web
            bind :8080
            http-request deny if { src -f opt@/scanner-guard/haproxy-denylist.lst }
            http-request return status 200

        CONF);

    $result = Process::timeout(300)->run(['docker', 'run', '--rm', '-v', storage_path('app/scanner-guard').':/scanner-guard:ro', 'haproxy:lts-alpine', 'sh', '-c',
        'haproxy -D -f /scanner-guard/server.conf && sleep 1 && echo started && wget -qO /dev/null http://127.0.0.1:8080/ && echo served']);

    expect(str_contains($result->output(), 'started'))->toBeTrue($result->errorOutput())
        ->and(str_contains($result->output(), 'served'))->toBe($served)
        ->and(str_contains($result->errorOutput(), '403 Forbidden'))->toBe(! $served);
})->with([
    'nothing banned' => [[], true],
    'others banned' => [[
        ['ip' => '185.177.72.0/24', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::/32', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '203.0.113.7', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
    ], true],
    'client banned' => [[
        ['ip' => '2001:db8::1', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
        ['ip' => '127.0.0.1', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
    ], false],
])->skip(getenv('SERVER_TESTS') === false, 'set SERVER_TESTS=1 (needs Docker)');
