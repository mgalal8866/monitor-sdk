<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Trk\Monitor\EventFactory;
use Trk\Monitor\Facades\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Tests\TestCase;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\TrkMonitorServiceProvider;

beforeEach(fn () => fakeCentral());

it('registers the service provider and facade', function () {
    expect(app()->getProviders(TrkMonitorServiceProvider::class))->not->toBeEmpty()
        ->and(Monitor::getFacadeRoot())->toBeInstanceOf(Trk\Monitor\Monitor::class);
});

it('captures exceptions manually with full context', function () {
    $e = new RuntimeException('Payment gateway failed', 0, new LogicException('previous'));

    Monitor::setTag('module', 'payments');
    Monitor::setContext('payment', ['gateway' => 'example', 'card_token' => 'secret']);
    Monitor::addBreadcrumb(['category' => 'payment', 'message' => 'Payment processing started']);
    Monitor::setUser(['id' => 42, 'email' => 'user@example.com']);

    $id = Monitor::captureException($e);
    $event = sentEvents()[0];

    expect($id)->toMatch('/^[0-9a-f]{32}$/')
        ->and($event['event_id'])->toBe($id)
        ->and($event['environment'])->toBe('testing')
        ->and($event['release'])->toBe('1.2.3')
        ->and($event['exception']['values'])->toHaveCount(2)
        ->and($event['exception']['values'][0]['type'])->toBe(RuntimeException::class)
        ->and($event['exception']['values'][1]['type'])->toBe(LogicException::class)
        ->and($event['tags'])->toBe(['module' => 'payments'])
        ->and($event['contexts']['payment']['card_token'])->toBe(Sanitizer::REDACTED)
        ->and($event['contexts']['runtime']['php'])->toBe(PHP_VERSION)
        ->and($event['breadcrumbs'][0]['message'])->toBe('Payment processing started')
        ->and($event['user'])->toBe(['id' => 42])
        ->and($event['trace']['trace_id'])->toMatch('/^[0-9a-f]{32}$/');
});

it('builds frames innermost first with in-app detection and source context', function () {
    $e = (fn () => new RuntimeException('boom'))();
    Monitor::captureException($e);

    $frames = sentEvents()[0]['exception']['values'][0]['stacktrace']['frames'];

    expect($frames[0]['line'])->toBe(__LINE__ - 5)
        ->and($frames[0]['file'])->not->toContain(base_path())
        ->and(collect($frames)->contains(fn ($f) => ($f['in_app'] ?? false) === false))->toBeTrue()
        ->and(collect($frames)->every(fn ($f) => ! isset($f['args'])))->toBeTrue();
});

it('captures messages', function () {
    Monitor::captureMessage('Payment gateway response was delayed', 'warning');

    $event = sentEvents()[0];
    expect($event['message'])->toBe('Payment gateway response was delayed')
        ->and($event['level'])->toBe('warning')
        ->and($event)->not->toHaveKey('exception');
});

it('captures unhandled exceptions through the Laravel exception handler', function () {
    Route::get('/boom', fn () => throw new DomainException('Unhandled failure'));
    Log::spy();

    $this->get('/boom')->assertStatus(500);

    $events = sentEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]['exception']['values'][0]['type'])->toBe(DomainException::class)
        ->and($events[0]['request']['method'])->toBe('GET')
        ->and($events[0]['request']['route'])->toBe('boom');

    // Laravel's own reporting still runs.
    Log::shouldHaveReceived('error')->once();
});

it('does not modify the original response', function () {
    Route::get('/boom', fn () => throw new DomainException('Unhandled failure'));

    $with = $this->get('/boom');
    config(['trk-monitor.enabled' => false]);

    expect($with->status())->toBe(500);
});

it('does not report the same exception twice', function () {
    $e = new RuntimeException('once');

    app(ExceptionHandler::class)->report($e);
    app(ExceptionHandler::class)->report($e);
    Monitor::captureException($e);

    expect(sentEvents())->toHaveCount(1);
});

it('respects exception exclusions', function () {
    config(['trk-monitor.exclude.exceptions' => [InvalidArgumentException::class]]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);

    Monitor::captureException(new InvalidArgumentException('ignored'));

    expect(sentEvents())->toBe([]);
});

it('does not transmit anything when disabled', function () {
    config(['trk-monitor.enabled' => false]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);

    expect(Monitor::captureException(new RuntimeException('x')))->toBeNull();
    app(Delivery::class)->flush();

    Http::assertNothingSent();
});

it('does not break the application when credentials are missing', function () {
    config(['trk-monitor.project_secret' => null]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);
    Route::get('/ok', fn () => 'fine');
    Route::get('/boom', fn () => throw new RuntimeException('x'));

    $this->get('/ok')->assertOk()->assertSee('fine');
    $this->get('/boom')->assertStatus(500);
    expect(Monitor::captureException(new RuntimeException('x')))->toBeNull();

    Http::assertNothingSent();
});

it('swallows transport failures without affecting the application', function () {
    Http::fake(fn () => throw new ConnectionException('down'));
    Route::get('/ok', function () {
        Monitor::captureException(new RuntimeException('while server is down'));

        return 'still works';
    });

    $this->get('/ok')->assertOk()->assertSee('still works');

    expect(fn () => app(Delivery::class)->flush())->not->toThrow(Throwable::class);
});

it('isolates SDK-internal failures', function () {
    // Errors thrown inside SDK code never reach the application.
    Monitor::configureScope(fn () => throw new RuntimeException('internal SDK failure'));

    expect(Trk\Monitor\Monitor::isSuppressed())->toBeFalse();
    Http::assertNothingSent();
});

it('does not capture exceptions raised while the SDK itself is working', function () {
    // e.g. an exception reported by the HTTP client while sending an event.
    Trk\Monitor\Monitor::withoutCapturing(function () {
        app(ExceptionHandler::class)->report(new RuntimeException('raised inside the SDK'));
        Monitor::captureException(new RuntimeException('raised inside the SDK'));
    });

    expect(sentEvents())->toBe([]);
});

it('samples errors independently', function () {
    config(['trk-monitor.sampling.errors' => 0.0, 'trk-monitor.sampling.transactions' => 1.0]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);

    Monitor::captureException(new RuntimeException('dropped by sampling'));
    expect(sentEvents())->toBe([]);

    config(['trk-monitor.sampling.errors' => 1.0, 'trk-monitor.sampling.transactions' => 0.0]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);
    Monitor::clearResolvedInstances();

    Monitor::captureException(new RuntimeException('kept even with zero transaction sampling'));
    expect(sentEvents())->toHaveCount(1);
});

it('signs requests without sending the secret', function () {
    Monitor::captureException(new RuntimeException('signed'));
    app(Delivery::class)->flush();

    $request = Http::recorded()[0][0];
    $expected = HttpTransport::sign(
        TestCase::SECRET,
        $request->header('X-Trk-Timestamp')[0],
        'POST',
        '/api/v1/monitor/events',
        decodedBody($request),
    );

    expect($request->header('X-Trk-Key')[0])->toBe('trk_pk_test')
        ->and($request->header('X-Trk-Signature')[0])->toBe($expected)
        ->and($request->hasHeader('Authorization'))->toBeFalse()
        ->and($request->body())->not->toContain(TestCase::SECRET);
});

it('filters sensitive request data and does not send bodies, cookies or IPs by default', function () {
    Route::post('/login', fn () => throw new RuntimeException('login failed'));

    $this->withHeaders(['Authorization' => 'Bearer abcdefghijkl', 'Cookie' => 'session=1'])
        ->post('/login?token=abc&page=2', ['email' => 'a@b.c', 'password' => 'hunter2']);

    $event = sentEvents()[0];

    expect($event['request'])->not->toHaveKey('data')
        ->and($event['request']['headers'])->not->toHaveKey('cookie')
        ->and($event['request']['headers']['authorization'])->toBe(Sanitizer::REDACTED)
        ->and($event['request']['query']['token'])->toBe(Sanitizer::REDACTED)
        ->and($event['request']['query']['page'])->toBe('2')
        ->and($event)->not->toHaveKey('user');
});

it('keeps payloads under the size limit', function () {
    config(['trk-monitor.max_payload_size' => 16 * 1024]);
    app()->forgetInstance(Options::class);
    app()->forgetInstance(EventFactory::class);
    app()->forgetInstance(Trk\Monitor\Monitor::class);

    for ($i = 0; $i < 50; $i++) {
        Monitor::addBreadcrumb(['category' => 'x', 'message' => str_repeat('y', 1500)]);
    }
    Monitor::captureException(new RuntimeException('big'));

    $request = Http::recorded()[0][0] ?? null;
    app(Delivery::class)->flush();
    $request = Http::recorded()[0][0];

    expect(strlen(decodedBody($request)))->toBeLessThanOrEqual(16 * 1024);
});
