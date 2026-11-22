<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Trk\Monitor\EventFactory;
use Trk\Monitor\Facades\Monitor;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

function rebuild(): void
{
    foreach ([Options::class, Delivery::class, HttpTransport::class, Trk\Monitor\Monitor::class, EventFactory::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

it('dispatches to the configured queue connection and name', function () {
    Queue::fake();
    config([
        'queue.connections.monitoring-db' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'],
        'trk-monitor.queue.enabled' => true,
        'trk-monitor.queue.connection' => 'monitoring-db',
        'trk-monitor.queue.name' => 'trk-events',
    ]);
    rebuild();

    Monitor::captureException(new RuntimeException('queued'));

    Queue::assertPushed(SendPayload::class, fn (SendPayload $job) => $job->connection === 'monitoring-db'
        && $job->queue === 'trk-events'
        && $job->endpoint === 'events'
        && $job->payload['exception']['values'][0]['value'] === 'queued');
});

it('does not block requests on a sync queue', function () {
    Queue::fake();
    fakeCentral();
    config(['trk-monitor.queue.enabled' => true, 'queue.default' => 'sync']);
    rebuild();

    Monitor::captureException(new RuntimeException('deferred'));

    Queue::assertNothingPushed();
    expect(app(Delivery::class)->pending())->toBe(1);
    Http::assertNothingSent();

    expect(sentEvents())->toHaveCount(1);
});

it('sends deferred events when the application terminates', function () {
    fakeCentral();
    Monitor::captureException(new RuntimeException('at shutdown'));
    Http::assertNothingSent();

    app()->terminate();

    Http::assertSentCount(1);
});

it('retries retryable failures with backoff and gives up after the limit', function () {
    $sleeps = [];
    Http::fakeSequence('monitor.test/*')
        ->pushStatus(503)
        ->pushStatus(429)
        ->push(['success' => true, 'data' => ['accepted' => 1]], 202);
    config(['trk-monitor.server.retries' => 3]);
    rebuild();
    $transport = new HttpTransport(app(Options::class), app(Factory::class), function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    });

    $result = $transport->post('events', ['event_id' => str_repeat('a', 32), 'message' => 'x']);

    expect($result->successful())->toBeTrue()
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(5000);
    Http::assertSentCount(3);
});

it('does not retry permanent failures', function () {
    Http::fake(['monitor.test/*' => Http::response(['success' => false, 'message' => 'Validation failed'], 422)]);
    config(['trk-monitor.server.retries' => 3]);
    rebuild();

    $result = app(HttpTransport::class)->post('events', ['event_id' => 'x']);

    expect($result->permanentFailure())->toBeTrue()->and($result->error)->toBe('Validation failed');
    Http::assertSentCount(1);
});

it('treats timeouts and connection errors as retryable without throwing', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });
    $transport = new HttpTransport(app(Options::class), app(Factory::class), fn () => null);

    $result = $transport->post('events', ['event_id' => 'x'], retries: 2);

    expect($result->successful())->toBeFalse()->and($result->retryable)->toBeTrue()
        ->and($result->error)->toContain('timed out')
        ->and($attempts)->toBe(3);
});

it('applies the configured timeouts', function () {
    config(['trk-monitor.server.timeout' => 1.5, 'trk-monitor.server.connect_timeout' => 0.5]);
    rebuild();

    expect(app(Options::class)->timeout())->toBe(1.5)->and(app(Options::class)->connectTimeout())->toBe(0.5);
});

it('spools undeliverable events and flushes them later', function () {
    $dir = storage_path('framework/trk-monitor/spool');
    array_map('unlink', glob($dir.'/*.json') ?: []);

    $online = false;
    Http::fake(function () use (&$online) {
        if (! $online) {
            throw new ConnectionException('down');
        }

        return Http::response(['success' => true, 'data' => ['accepted' => 1]], 202);
    });
    Monitor::captureException(new RuntimeException('while offline'));
    app(Delivery::class)->flush();

    expect(app(Spool::class)->count())->toBe(1);

    $online = true;
    $this->artisan('trk-monitor:flush')->expectsOutputToContain('Sent 1')->assertSuccessful();

    expect(app(Spool::class)->count())->toBe(0);
});

it('compresses large payloads', function () {
    fakeCentral();
    Monitor::addBreadcrumb(['message' => str_repeat('a', 5000)]);
    Monitor::captureException(new RuntimeException('large'));
    app(Delivery::class)->flush();

    $request = Http::recorded()[0][0];
    expect($request->hasHeader('Content-Encoding', 'gzip'))->toBeTrue()
        ->and(json_decode(decodedBody($request), true)['exception']['values'][0]['value'])->toBe('large');
});

it('resets the scope between queue jobs', function () {
    Monitor::setUser(['id' => 1]);
    Monitor::setTag('tenant', 'a');

    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->shouldReceive('payload')->andReturn([]);
    event(new JobProcessing('sync', $job));

    expect(Monitor::scope()->user())->toBeNull()->and(Monitor::scope()->tags())->not->toHaveKey('tenant');
});
