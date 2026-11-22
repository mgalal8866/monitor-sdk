<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Trk\Monitor\EventFactory;
use Trk\Monitor\Facades\Monitor;
use Trk\Monitor\Http\Middleware\TrackRequest;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Monitors\JobMonitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;

/** Payloads sent to an endpoint (after flushing deferred delivery). */
function sentTo(string $endpoint): array
{
    app(Delivery::class)->flush();

    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/api/v1/monitor/'.$endpoint))
        ->map(fn (Request $r) => json_decode(decodedBody($r), true))
        ->values()
        ->all();
}

function reconfigure(array $config): void
{
    config($config);

    foreach ([Options::class, Tracer::class, Delivery::class, HttpTransport::class, Trk\Monitor\Monitor::class, EventFactory::class,
        JobMonitor::class, TrackRequest::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Monitor::clearResolvedInstances();
}

class TrkFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public int $tries = 2;

    public function __construct(public string $cardNumber = '4111111111111111') {}

    public function handle(): void
    {
        throw new RuntimeException('Invoice sync failed');
    }
}

class TrkWorkingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        DB::select('select 1');
    }
}

beforeEach(function () {
    fakeCentral();
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    reconfigure(['trk-monitor.sampling.transactions' => 1.0, 'trk-monitor.sampling.queries' => 1.0]);
});

it('records http transactions with route, status and query measurements', function () {
    Route::get('/orders/{id}', function (string $id) {
        DB::table('orders')->insert(['email' => 'a@b.c']);
        DB::table('orders')->where('id', $id)->first();

        return response('ok', 201);
    })->name('orders.show');

    $this->get('/orders/5')->assertStatus(201)->assertSee('ok');

    $tx = sentTo('transactions')[0];

    expect($tx['name'])->toBe('GET /orders/{id}')
        ->and($tx['op'])->toBe('http.server')
        ->and($tx['status'])->toBe('ok')
        ->and($tx['http'])->toMatchArray(['method' => 'GET', 'route' => '/orders/{id}', 'route_name' => 'orders.show', 'status_code' => 201])
        ->and($tx['measurements']['query_count'])->toBe(2)
        ->and($tx['duration_ms'])->toBeGreaterThan(0)
        ->and($tx['trace_id'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($tx['spans'])->toHaveCount(2)
        ->and($tx['spans'][0]['op'])->toBe('db.query')
        ->and($tx['spans'][0]['description'])->not->toContain('a@b.c')
        ->and($tx['spans'][0]['data']['category'])->toBe('insert');
});

it('does not modify responses', function () {
    Route::get('/json', fn () => response()->json(['a' => 1], 200, ['X-Custom' => 'yes']));

    $response = $this->get('/json');

    $response->assertOk()->assertExactJson(['a' => 1])->assertHeader('X-Custom', 'yes');
    expect($response->headers->has('traceparent'))->toBeFalse();
});

it('reports slow queries even when the transaction is not sampled', function () {
    reconfigure(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.performance.slow_query_threshold_ms' => 0]);
    Route::get('/report', fn () => DB::table('orders')->where('email', 'secret@x.y')->count());

    $this->get('/report')->assertOk();

    $tx = sentTo('transactions')[0];
    expect($tx['sampled'])->toBeFalse()
        ->and($tx['measurements']['slow_query_count'])->toBe(1)
        ->and($tx['spans'][0]['data']['slow'])->toBeTrue()
        ->and(json_encode($tx))->not->toContain('secret@x.y');
});

it('drops normal unsampled transactions', function () {
    reconfigure(['trk-monitor.sampling.transactions' => 0.0]);
    Route::get('/fast', fn () => 'ok');

    $this->get('/fast')->assertOk();

    expect(sentTo('transactions'))->toBe([]);
});

it('always reports failed requests and slow requests', function () {
    reconfigure(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.performance.slow_request_threshold_ms' => 0]);
    Route::get('/slow', fn () => 'ok');
    Route::get('/boom', fn () => throw new RuntimeException('x'));

    $this->get('/slow');
    $this->get('/boom');

    $tx = collect(sentTo('transactions'))->keyBy('name');
    expect($tx['GET /slow']['tags']['slow'])->toBe('true')
        ->and($tx['GET /boom']['status'])->toBe('error')
        ->and($tx['GET /boom']['http']['status_code'])->toBe(500);
});

it('ignores excluded paths, routes and static assets', function () {
    reconfigure(['trk-monitor.exclude.routes' => ['admin.*', '/internal/*']]);
    Route::get('/up', fn () => 'ok');
    Route::get('/admin/stats', fn () => 'ok')->name('admin.stats');
    Route::get('/internal/ping', fn () => 'ok');
    Route::get('/app.css', fn () => 'body{}');
    Route::get('/kept', fn () => 'ok');

    foreach (['/up', '/admin/stats', '/internal/ping', '/app.css', '/kept'] as $uri) {
        $this->get($uri)->assertOk();
    }

    expect(collect(sentTo('transactions'))->pluck('name')->all())->toBe(['GET /kept']);
});

it('never sends query bindings unless enabled, and sanitizes them when enabled', function () {
    Route::get('/q', fn () => DB::table('orders')->where('email', 'bob@example.com')->where('id', 4111111111111111)->get());

    $this->get('/q');
    expect(sentTo('transactions')[0]['spans'][0]['data'])->not->toHaveKey('bindings');

    Http::swap(new Factory);
    fakeCentral();
    reconfigure(['trk-monitor.sampling.transactions' => 1.0, 'trk-monitor.sampling.queries' => 1.0, 'trk-monitor.database.send_bindings' => true]);
    $this->get('/q');

    expect(sentTo('transactions')[0]['spans'][0]['data']['bindings'][0])->toBe('bob@example.com');
});

it('skips excluded tables and connections', function () {
    reconfigure(['trk-monitor.sampling.transactions' => 1.0, 'trk-monitor.sampling.queries' => 1.0, 'trk-monitor.database.exclude_tables' => ['orders']]);
    Route::get('/q', fn () => DB::table('orders')->count());

    $this->get('/q');

    expect(sentTo('transactions')[0]['measurements']['query_count'])->toBe(0);
});

it('adds query, log and http client breadcrumbs to error events', function () {
    Http::fake([
        'monitor.test/*' => Http::response(['success' => true, 'data' => ['accepted' => 1]], 202),
        'api.payments.test/*' => Http::response(['ok' => false], 502),
    ]);
    Route::get('/pay', function () {
        DB::table('orders')->count();
        Log::warning('Gateway slow');
        Http::get('https://api.payments.test/charge?card=4111');

        throw new RuntimeException('payment failed');
    });

    $this->get('/pay');

    $crumbs = collect(sentTo('events')[0]['breadcrumbs'])->keyBy('category');
    expect($crumbs['query']['message'])->toContain('select count(*)')
        ->and($crumbs['log']['message'])->toBe('Gateway slow')
        ->and($crumbs['log']['level'])->toBe('warning')
        ->and($crumbs['http']['message'])->toBe('GET https://api.payments.test/charge')
        ->and($crumbs['http']['data']['status_code'])->toBe(502);
});

it('does not record breadcrumbs for its own requests to the monitor', function () {
    Monitor::captureMessage('first');
    app(Delivery::class)->flush();
    Monitor::captureException(new RuntimeException('second'));

    $crumbs = sentTo('events')[1]['breadcrumbs'] ?? [];
    expect(collect($crumbs)->where('category', 'http'))->toBeEmpty();
});

it('reports a failed job once, with job context and without its payload', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
    Schema::create('jobs', function (Blueprint $t) {
        $t->id();
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $t) {
        $t->id();
        $t->string('uuid')->unique();
        $t->text('connection');
        $t->text('queue');
        $t->longText('payload');
        $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'testing', 'queue.failed.table' => 'failed_jobs']);
    reconfigure(['trk-monitor.sampling.transactions' => 1.0]);

    TrkFailingJob::dispatch();
    $this->artisan('queue:work', ['--once' => true, '--queue' => 'default']);
    $this->artisan('queue:work', ['--once' => true, '--queue' => 'default', '--backoff' => 0]);

    $events = sentTo('events');
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['event_type'])->toBe('job')
        ->and($event['exception']['values'][0]['value'])->toBe('Invoice sync failed')
        ->and($event['tags']['job'])->toBe(TrkFailingJob::class)
        ->and($event['contexts']['job'])->toMatchArray(['name' => TrkFailingJob::class, 'queue' => 'default', 'attempts' => 2, 'max_tries' => 2])
        ->and(collect($event['breadcrumbs'])->pluck('message')->implode(' '))->toContain('Attempt 2 failed')
        ->and(json_encode($event))->not->toContain('4111111111111111');

    $tx = collect(sentTo('transactions'))->where('op', 'queue.job')->values();
    expect($tx->pluck('status')->all())->toBe(['retrying', 'failed'])
        ->and($tx[1]['contexts']['job']['attempts'])->toBe(2);
});

it('can report every failed attempt when configured', function () {
    reconfigure(['trk-monitor.jobs.report_retries' => true]);
    config(['queue.default' => 'sync']);

    try {
        TrkFailingJob::dispatch();
    } catch (RuntimeException) {
    }

    expect(sentTo('events'))->toHaveCount(1);
});

it('records successful jobs as transactions and isolates their scope', function () {
    config(['queue.default' => 'sync']);
    Monitor::setTag('request_tag', 'leak');

    TrkWorkingJob::dispatch();

    $tx = collect(sentTo('transactions'))->firstWhere('op', 'queue.job');
    expect($tx['name'])->toBe(TrkWorkingJob::class)
        ->and($tx['status'])->toBe('ok')
        ->and($tx['measurements']['query_count'])->toBe(1)
        ->and($tx['tags'])->not->toHaveKey('request_tag');
});

it('never monitors its own delivery job', function () {
    config(['queue.default' => 'sync']);

    SendPayload::dispatch('events', ['event_id' => str_repeat('a', 32), 'message' => 'x']);

    expect(collect(sentTo('transactions'))->where('op', 'queue.job'))->toBeEmpty();
});

it('sanitizes database errors', function () {
    Route::get('/dup', fn () => DB::table('missing_table')->where('email', 'jane@example.com')->get());

    $this->get('/dup')->assertStatus(500);

    $value = sentTo('events')[0]['exception']['values'][0]['value'];
    expect($value)->toContain('missing_table')
        ->and($value)->toContain('where "email" = ?')
        ->and($value)->not->toContain('jane@example.com');
});

it('does not create events from instrumentation itself', function () {
    Route::get('/q', fn () => DB::table('orders')->count());

    $this->get('/q');

    expect(sentTo('events'))->toBe([]);
});
