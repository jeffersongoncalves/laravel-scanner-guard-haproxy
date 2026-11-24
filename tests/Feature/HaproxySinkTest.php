<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\ScannerGuardHaproxy\HaproxySink;

beforeEach(function () {
    config(['scanner-guard-haproxy.path' => 'storage/app/scanner-guard/haproxy-denylist-test.lst']);
    $this->file = storage_path('app/scanner-guard/haproxy-denylist-test.lst');
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/scanner-guard'));
});

it('writes static entries and bans as an ACL file, comments on their own line', function () {
    (new HaproxySink)->sync([
        ['ip' => '185.177.72.0/24', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::1', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
    ]);

    expect(File::get($this->file))->toBe(
        "# static\n185.177.72.0/24\n"
        ."# scanner_path, expires 2026-11-20T10:00:00+00:00\n2001:db8::1\n"
    )->and(File::glob(storage_path('app/scanner-guard/*.tmp')))->toBe([]);
});

it('writes only a comment when nothing is banned', function () {
    (new HaproxySink)->sync([]);

    expect(File::get($this->file))->toBe("# nothing banned\n");
});

it('runs the reload command only when the file content changed', function () {
    Process::fake();
    config(['scanner-guard-haproxy.reload_command' => 'sudo systemctl reload haproxy']);
    $entries = [['ip' => '10.20.20.23', 'reason' => 'static', 'expires_at' => null]];

    (new HaproxySink)->sync($entries);
    (new HaproxySink)->sync($entries);
    (new HaproxySink)->sync([]);

    Process::assertRanTimes('sudo systemctl reload haproxy', 2);
});

it('logs instead of failing when the reload command fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Job for haproxy.service failed', exitCode: 1)]);
    Log::spy();
    config(['scanner-guard-haproxy.reload_command' => 'sudo systemctl reload haproxy']);

    (new HaproxySink)->sync([]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'scanner-guard: HAProxy reload command failed'
        && str_contains($context['error'], 'haproxy.service failed'));
});

it('logs instead of failing when the reload command throws (e.g. a timeout)', function () {
    Process::fake(fn () => throw new RuntimeException('The process exceeded the timeout of 60 seconds.'));
    Log::spy();
    config(['scanner-guard-haproxy.reload_command' => 'sudo systemctl reload haproxy']);

    (new HaproxySink)->sync([]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($context['error'], 'timeout'));
});

it('runs a failed reload again on the next sync, until it succeeds', function () {
    Process::fake(['*' => Process::sequence()->push(Process::result(exitCode: 1))->push(Process::result())]);
    config(['scanner-guard-haproxy.reload_command' => 'sudo systemctl reload haproxy']);

    (new HaproxySink)->sync([]); // fails
    (new HaproxySink)->sync([]); // retried
    (new HaproxySink)->sync([]); // up to date

    Process::assertRanTimes('sudo systemctl reload haproxy', 2);
    expect(File::glob(storage_path('app/scanner-guard/*.reload')))->toBe([]);
});

it('is driven by scanner-guard:sync-denylist', function () {
    config([
        'scanner-guard.driver' => 'http', // no database needed: bans come from the (empty) last pull
        'scanner-guard.sinks' => [HaproxySink::class],
        'scanner-guard.ip_blocklist' => ['185.177.72.69', 'not-an-ip'],
    ]);

    $this->artisan('scanner-guard:sync-denylist')->assertSuccessful();

    expect(File::get($this->file))->toBe("# static\n185.177.72.69\n");
});
