<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Trk\Monitor\Facades\Monitor;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\CircuitBreaker;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

/** Fakes an unreachable central server and counts the connection attempts. */
function serverDown(int &$attempts): void
{
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 7: Failed to connect');
    });
}

it('contacts a dead server once, then spools everything without network calls', function () {
    $attempts = 0;
    serverDown($attempts);

    for ($i = 0; $i < 20; $i++) {
        Monitor::captureException(new RuntimeException("offline {$i}"));
    }
    app(Delivery::class)->flush();

    expect($attempts)->toBe(1)
        ->and(app(Spool::class)->count())->toBe(20)
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();

    // Later events in the same or another request skip the buffer entirely.
    Monitor::captureException(new RuntimeException('still offline'));
    expect(app(Delivery::class)->pending())->toBe(0)
        ->and(app(Spool::class)->count())->toBe(21)
        ->and($attempts)->toBe(1);
});

it('never throws into the application while the server is down', function () {
    $attempts = 0;
    serverDown($attempts);

    expect(function () {
        Monitor::captureException(new RuntimeException('a'));
        Monitor::captureMessage('b');
        app(Delivery::class)->flush();
        app()->terminate();
    })->not->toThrow(Throwable::class);
});

it('shares the open circuit between processes through the state file', function () {
    $attempts = 0;
    serverDown($attempts);
    app(HttpTransport::class)->post('events', ['event_id' => 'x']);

    $otherProcess = new CircuitBreaker(storage_path('framework/trk-monitor/circuit.json'));

    expect($otherProcess->isOpen())->toBeTrue()->and($otherProcess->allowsRequest())->toBeFalse();
});

it('probes again after the cooldown and closes on success, with exponential backoff while down', function () {
    $now = 1000.0;
    $breaker = new CircuitBreaker(storage_path('framework/trk-monitor/circuit-test.json'), cooldown: 30, maxCooldown: 300, clock: function () use (&$now) {
        return $now;
    });
    $breaker->reset();

    $breaker->recordFailure();
    expect($breaker->allowsRequest())->toBeFalse()->and($breaker->retryIn())->toBe(30);

    $now += 31;
    expect($breaker->allowsRequest())->toBeTrue()   // this process probes
        ->and($breaker->allowsRequest())->toBeFalse(); // others wait while it does

    $breaker->recordFailure();                       // probe failed: cooldown doubles
    expect($breaker->retryIn())->toBe(60);

    $now += 61;
    expect($breaker->allowsRequest())->toBeTrue();
    $breaker->recordSuccess();
    expect($breaker->isOpen())->toBeFalse()->and($breaker->allowsRequest())->toBeTrue();

    $breaker->reset();
});

it('caps the cooldown and honours Retry-After', function () {
    $breaker = new CircuitBreaker(storage_path('framework/trk-monitor/circuit-test.json'), cooldown: 30, maxCooldown: 120, clock: fn () => 0.0);
    $breaker->reset();

    for ($i = 0; $i < 10; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->retryIn())->toBe(120);

    $breaker->recordFailure(retryAfter: 400);
    expect($breaker->retryIn())->toBe(400);

    $breaker->reset();
});

it('does not open the circuit for permanent 4xx errors', function () {
    Http::fake(['monitor.test/*' => Http::response(['message' => 'Validation failed'], 422)]);

    app(HttpTransport::class)->post('events', ['event_id' => 'x']);

    expect(app(CircuitBreaker::class)->isOpen())->toBeFalse();
});

it('spools queued payloads immediately instead of keeping the worker busy', function () {
    $attempts = 0;
    serverDown($attempts);

    $job = new SendPayload('events', ['event_id' => 'queued']);
    $job->handle(app(HttpTransport::class), app(Spool::class));

    expect(app(Spool::class)->count())->toBe(1)->and($attempts)->toBe(2); // one HTTP retry inside the worker

    $job2 = new SendPayload('events', ['event_id' => 'queued-2']);
    $job2->handle(app(HttpTransport::class), app(Spool::class));

    expect(app(Spool::class)->count())->toBe(2)->and($attempts)->toBe(2);
});

it('does not dispatch jobs while the circuit is open', function () {
    Queue::fake();
    config([
        'trk-monitor.queue.enabled' => true,
        'queue.connections.monitoring-db' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'],
        'trk-monitor.queue.connection' => 'monitoring-db',
    ]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Delivery::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);
    app(CircuitBreaker::class)->recordFailure();

    Monitor::captureException(new RuntimeException('down'));

    Queue::assertNothingPushed();
    expect(app(Spool::class)->count())->toBe(1);
});

it('keeps the spool bounded', function () {
    $spool = new Spool(storage_path('framework/trk-monitor/spool-bounded'), maxFiles: 3);
    array_map('unlink', glob($spool->directory().'/*.json') ?: []);

    foreach (range(1, 5) as $i) {
        $spool->store('events', ['n' => $i]);
    }

    expect($spool->count())->toBe(3);
    array_map('unlink', glob($spool->directory().'/*.json') ?: []);
});

it('skips the scheduled flush while the circuit is open and sends once the server is back', function () {
    $online = false;
    $attempts = 0;
    Http::fake(function () use (&$online, &$attempts) {
        $attempts++;
        if (! $online) {
            throw new ConnectionException('down');
        }

        return Http::response(['success' => true, 'data' => ['accepted' => 1]], 202);
    });

    Monitor::captureException(new RuntimeException('offline'));
    app(Delivery::class)->flush();
    expect($attempts)->toBe(1);

    $this->artisan('trk-monitor:flush --scheduled')->assertSuccessful();
    expect($attempts)->toBe(1)->and(app(Spool::class)->count())->toBe(1);

    $online = true;
    app(CircuitBreaker::class)->reset();
    $this->artisan('trk-monitor:flush --scheduled')->assertSuccessful();

    expect(app(Spool::class)->count())->toBe(0);
});

it('registers the flush command with the scheduler', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'trk-monitor:flush'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

it('does not fail a deployment when the server is down', function () {
    $attempts = 0;
    serverDown($attempts);

    $this->artisan('trk-monitor:release', ['version' => '9.9.9', '--commit' => 'abc', '--branch' => 'main'])
        ->expectsOutputToContain('queued')
        ->assertSuccessful();

    $entry = json_decode(file_get_contents(app(Spool::class)->files()[0]), true);
    expect($entry['endpoint'])->toBe('releases')->and($entry['payload']['version'])->toBe('9.9.9');

    $this->artisan('trk-monitor:release', ['version' => '9.9.9', '--commit' => 'abc', '--branch' => 'main', '--strict' => true])
        ->assertFailed();
});

it('reports the circuit state in trk-monitor:status', function () {
    $attempts = 0;
    serverDown($attempts);
    app(CircuitBreaker::class)->recordFailure();

    $this->artisan('trk-monitor:status')->expectsOutputToContain('open')->assertSuccessful();
});
