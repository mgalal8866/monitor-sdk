<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Trk\Monitor\Options;
use Trk\Monitor\Tests\TestCase;
use Trk\Monitor\Transport\HttpTransport;

beforeEach(function () {
    Http::fake([
        'monitor.test/api/v1/monitor/health' => Http::response(['success' => true, 'data' => ['status' => 'ok']]),
        'monitor.test/api/v1/monitor/config' => Http::response(['success' => true, 'data' => ['project' => ['name' => 'CRM']]]),
        'monitor.test/api/v1/monitor/events' => Http::response(['success' => true, 'data' => ['accepted' => 1]], 202),
        'monitor.test/api/v1/monitor/releases' => Http::response(['success' => true], 201),
    ]);
});

it('publishes the configuration', function () {
    @unlink(config_path('trk-monitor.php'));

    $this->artisan('vendor:publish', ['--tag' => 'trk-monitor-config'])->assertSuccessful();

    expect(config_path('trk-monitor.php'))->toBeFile();
    @unlink(config_path('trk-monitor.php'));
});

it('installs config and environment variables without overwriting existing values', function () {
    $env = base_path('.env');
    file_put_contents($env, "APP_NAME=Demo\nTRK_MONITOR_URL=https://existing.test\n");

    $this->artisan('trk-monitor:install', ['--key' => 'trk_pk_x', '--secret' => 'trk_sk_y'])->assertSuccessful();

    $contents = file_get_contents($env);
    expect($contents)->toContain('TRK_MONITOR_URL=https://existing.test')
        ->toContain('TRK_MONITOR_PROJECT_KEY=trk_pk_x')
        ->toContain('TRK_MONITOR_PROJECT_SECRET=trk_sk_y')
        ->toContain('TRK_MONITOR_ENABLED=true')
        ->and(config_path('trk-monitor.php'))->toBeFile();

    @unlink($env);
    @unlink(config_path('trk-monitor.php'));
});

it('tests the connection end to end without printing the secret', function () {
    $this->artisan('trk-monitor:test')
        ->expectsOutputToContain('reachable')
        ->expectsOutputToContain('CRM')
        ->expectsOutputToContain('accepted')
        ->doesntExpectOutputToContain(TestCase::SECRET)
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/events')
        && json_decode($r->hasHeader('Content-Encoding', 'gzip') ? gzdecode($r->body()) : $r->body(), true)['tags']['trk_monitor_test'] === 'true');
});

it('reports configuration problems in the test command', function () {
    config(['trk-monitor.server.url' => null]);
    app()->forgetInstance(Options::class);

    $this->artisan('trk-monitor:test')->expectsOutputToContain('TRK_MONITOR_URL is not set')->assertFailed();
    Http::assertNothingSent();
});

it('reports authentication failures', function () {
    // Replace the fakes registered in beforeEach (earlier stubs take precedence).
    Http::swap(new Factory);
    app()->forgetInstance(HttpTransport::class);
    Http::fake([
        'monitor.test/api/v1/monitor/health' => Http::response(['success' => true]),
        'monitor.test/api/v1/monitor/config' => Http::response(['success' => false, 'message' => 'Credentials have been revoked'], 403),
    ]);

    $this->artisan('trk-monitor:test')->expectsOutputToContain('Credentials have been revoked')->assertFailed();
});

it('shows status without exposing the secret', function () {
    $this->artisan('trk-monitor:status')
        ->expectsOutputToContain('1.0.0')
        ->expectsOutputToContain('configured')
        ->expectsOutputToContain('available')
        ->doesntExpectOutputToContain(TestCase::SECRET)
        ->assertSuccessful();
});

it('registers releases', function () {
    $this->artisan('trk-monitor:release', ['version' => '2.0.0', '--commit' => 'abc123', '--branch' => 'main'])
        ->expectsOutputToContain('Release 2.0.0 registered')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/releases')
        && $r['version'] === '2.0.0' && $r['commit_hash'] === 'abc123' && $r['branch'] === 'main' && $r['environment'] === 'testing');
});
