<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Trk\Monitor\EventFactory;
use Trk\Monitor\Facades\Monitor;
use Trk\Monitor\Http\Middleware\TrackRequest;
use Trk\Monitor\Monitors\HttpClientMonitor;
use Trk\Monitor\Monitors\JobMonitor;
use Trk\Monitor\Monitors\QueueMonitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Support\TraceContext;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;

const INCOMING_TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
const INCOMING_PARENT = '00f067aa0ba902b7';

class TrkTracedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        DB::table('orders')->count();
    }
}

/** Requests the application sent to services other than TrkMonitor. */
function outgoing(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->reject(fn (Request $r) => str_starts_with($r->url(), 'https://monitor.test/'))
        ->values()->all();
}

function monitorPayloads(string $endpoint): array
{
    app(Delivery::class)->flush();

    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/api/v1/monitor/'.$endpoint))
        ->map(fn (Request $r) => json_decode(decodedBody($r), true))
        ->values()->all();
}

function retrace(array $config = []): void
{
    config($config);

    foreach ([Options::class, Tracer::class, Delivery::class, HttpTransport::class, Trk\Monitor\Monitor::class, EventFactory::class,
        JobMonitor::class, TrackRequest::class, QueueMonitor::class, HttpClientMonitor::class, TraceContext::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Monitor::clearResolvedInstances();
}

function useDatabaseQueue(): void
{
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
}

beforeEach(function () {
    Http::fake([
        'monitor.test/*' => Http::response(['success' => true, 'data' => ['accepted' => 1]], 202),
        'payments.example.com/*' => Http::response(['ok' => true], 200),
        'inventory.internal/*' => Http::response('down', 503),
    ]);
    config(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    retrace(['trk-monitor.sampling.transactions' => 1.0, 'trk-monitor.sampling.queries' => 1.0]);
});

it('records a transaction with database, http client and queue spans, correctly parented', function () {
    useDatabaseQueue();
    Route::post('/checkout', function () {
        DB::table('orders')->insert(['email' => 'buyer@example.com']);
        Http::get('https://payments.example.com/charge?card=4111111111111111');
        TrkTracedJob::dispatch();

        return response('ok');
    });

    $this->post('/checkout')->assertOk();

    $tx = monitorPayloads('transactions')[0];
    $ops = collect($tx['spans'])->pluck('op')->all();

    expect($ops)->toContain('db.query', 'http.client', 'queue.publish');

    foreach ($tx['spans'] as $span) {
        expect($span['parent_span_id'])->toBe($tx['span_id'])
            ->and($span['span_id'])->toMatch('/^[0-9a-f]{16}$/')
            ->and($span['start_timestamp'])->toBeGreaterThanOrEqual($tx['start_timestamp'])
            ->and($span['timestamp'])->toBeLessThanOrEqual($tx['timestamp'] + 0.001);
    }

    $http = collect($tx['spans'])->firstWhere('op', 'http.client');
    expect($http['description'])->toBe('GET https://payments.example.com/charge')
        ->and($http['data'])->toMatchArray(['status_code' => 200, 'host' => 'payments.example.com'])
        ->and($http['status'])->toBe('ok')
        ->and(json_encode($tx))->not->toContain('4111111111111111');

    $publish = collect($tx['spans'])->firstWhere('op', 'queue.publish');
    expect($publish['description'])->toBe(TrkTracedJob::class)
        ->and($publish['data'])->toMatchArray(['connection' => 'database', 'queue' => 'default']);
});

it('continues the trace in the queue worker as a child of the dispatch span', function () {
    useDatabaseQueue();
    Route::post('/dispatch', function () {
        TrkTracedJob::dispatch();

        return response('ok');
    });

    $this->post('/dispatch')->assertOk();
    $request = monitorPayloads('transactions')[0];
    $publish = collect($request['spans'])->firstWhere('op', 'queue.publish');

    $stored = json_decode(DB::table('jobs')->value('payload'), true);
    expect($stored[QueueMonitor::PAYLOAD_KEY]['traceparent'])->toBe('00-'.$request['trace_id'].'-'.$publish['span_id'].'-01')
        ->and($stored['data']['command'] ?? '')->not->toContain('trk_trace');

    $this->artisan('queue:work', ['--once' => true]);

    $job = collect(monitorPayloads('transactions'))->firstWhere('op', 'queue.job');
    expect($job['trace_id'])->toBe($request['trace_id'])
        ->and($job['parent_span_id'])->toBe($publish['span_id'])
        ->and($job['span_id'])->not->toBe($request['span_id'])
        ->and($job['measurements']['query_count'])->toBe(1);
});

it('starts a fresh trace for jobs with missing or invalid trace context', function () {
    $monitor = app(JobMonitor::class);
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->shouldReceive('payload')->andReturn([QueueMonitor::PAYLOAD_KEY => ['traceparent' => 'garbage']]);
    $job->shouldReceive('resolveName')->andReturn(TrkTracedJob::class);
    $job->shouldReceive('uuid')->andReturn('job-1');

    $monitor->processing(new JobProcessing('database', $job));

    expect(app(TraceContext::class)->parentSpanId())->toBeNull()
        ->and(app(Tracer::class)->current()?->parentSpanId)->toBeNull();
});

it('propagates W3C trace context on outgoing requests from the active span', function () {
    Route::get('/proxy', function () {
        Http::get('https://payments.example.com/a');

        return 'ok';
    });

    $this->get('/proxy', ['traceparent' => '00-'.INCOMING_TRACE.'-'.INCOMING_PARENT.'-01', 'tracestate' => 'vendor=abc'])->assertOk();

    $tx = monitorPayloads('transactions')[0];
    $span = collect($tx['spans'])->firstWhere('op', 'http.client');
    $sent = outgoing()[0];

    expect($tx['trace_id'])->toBe(INCOMING_TRACE)
        ->and($tx['parent_span_id'])->toBe(INCOMING_PARENT)
        ->and($sent->header('traceparent')[0])->toBe('00-'.INCOMING_TRACE.'-'.$span['span_id'].'-01')
        ->and($sent->header('tracestate')[0])->toBe('vendor=abc');
});

it('never overrides trace headers set by the application', function () {
    Route::get('/custom', function () {
        Http::withHeaders(['traceparent' => 'app-owned', 'X-Other' => 'kept'])->get('https://payments.example.com/a');

        return 'ok';
    });

    $this->get('/custom')->assertOk();

    expect(outgoing()[0]->header('traceparent'))->toBe(['app-owned'])
        ->and(outgoing()[0]->header('X-Other'))->toBe(['kept']);
});

it('only propagates to configured hosts and never to the monitor server', function () {
    retrace(['trk-monitor.sampling.transactions' => 1.0, 'trk-monitor.tracing.propagation_targets' => ['*.internal']]);
    Route::get('/two', function () {
        Http::get('https://payments.example.com/a');
        Http::get('https://inventory.internal/stock');

        return 'ok';
    });

    $this->get('/two')->assertOk();
    [$external, $internal] = outgoing();

    expect($external->hasHeader('traceparent'))->toBeFalse()
        ->and($internal->hasHeader('traceparent'))->toBeTrue();

    monitorPayloads('transactions');
    $toMonitor = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_starts_with($r->url(), 'https://monitor.test/'));
    expect($toMonitor)->not->toBeEmpty()
        ->and($toMonitor->every(fn (Request $r) => ! $r->hasHeader('traceparent')))->toBeTrue();
});

it('marks failed outgoing calls on their span', function () {
    Route::get('/fails', function () {
        Http::get('https://inventory.internal/stock');

        return 'ok';
    });

    $this->get('/fails')->assertOk();

    $span = collect(monitorPayloads('transactions')[0]['spans'])->firstWhere('op', 'http.client');
    expect($span['status'])->toBe('error')->and($span['data']['status_code'])->toBe(503);
});

it('nests spans of a job executed synchronously inside the request', function () {
    config(['queue.default' => 'sync']);
    Route::get('/sync', function () {
        Monitor::setTag('tenant', 'acme');
        TrkTracedJob::dispatch();
        DB::table('orders')->count();

        return 'ok';
    });

    $this->get('/sync')->assertOk();

    $txs = monitorPayloads('transactions');
    expect($txs)->toHaveCount(1); // no separate transaction for the sync job

    $tx = $txs[0];
    $job = collect($tx['spans'])->first(fn ($s) => in_array($s['op'], ['queue.publish', 'queue.process'], true));
    $queries = collect($tx['spans'])->where('op', 'db.query')->values();

    expect($job)->not->toBeNull()
        ->and($job['parent_span_id'])->toBe($tx['span_id'])
        ->and($job['status'])->toBe('ok')
        ->and($queries)->toHaveCount(2)
        ->and($queries[0]['parent_span_id'])->toBe($job['span_id'])   // query inside the job
        ->and($queries[1]['parent_span_id'])->toBe($tx['span_id'])    // query after the job
        ->and(Monitor::scope()->tags())->toHaveKey('tenant');         // request scope kept
});

it('does not leak trace context between requests', function () {
    Route::get('/a', fn () => 'ok');

    $this->get('/a', ['traceparent' => '00-'.INCOMING_TRACE.'-'.INCOMING_PARENT.'-01']);
    $this->get('/a');

    [$first, $second] = monitorPayloads('transactions');

    expect($first['trace_id'])->toBe(INCOMING_TRACE)
        ->and($second['trace_id'])->not->toBe(INCOMING_TRACE)
        ->and($second)->not->toHaveKey('parent_span_id');
});

it('handles invalid incoming trace context safely', function (string $header) {
    Route::get('/a', fn () => 'ok');

    $this->get('/a', ['traceparent' => $header, 'tracestate' => str_repeat('x', 600)])->assertOk();

    $tx = monitorPayloads('transactions')[0];
    expect($tx['trace_id'])->toMatch('/^[0-9a-f]{32}$/')->not->toBe(INCOMING_TRACE)
        ->and($tx)->not->toHaveKey('parent_span_id');
})->with([
    'garbage' => ['<script>'],
    'uppercase junk' => ['00-ZZZ-'.INCOMING_PARENT.'-01'],
    'zero trace' => ['00-'.str_repeat('0', 32).'-'.INCOMING_PARENT.'-01'],
    'v00 with extra field' => ['00-'.INCOMING_TRACE.'-'.INCOMING_PARENT.'-01-extra'],
    'too long' => [str_repeat('a', 300)],
]);

describe('sampling', function () {
    it('follows the parent sampled flag', function () {
        retrace(['trk-monitor.sampling.transactions' => 1.0]);
        Route::get('/a', fn () => 'ok');

        $this->get('/a', ['traceparent' => '00-'.INCOMING_TRACE.'-'.INCOMING_PARENT.'-00']);

        expect(monitorPayloads('transactions'))->toBeEmpty();
    });

    it('applies route rules before environment and default rates', function () {
        retrace([
            'trk-monitor.sampling.transactions' => 0.0,
            'trk-monitor.sampling.environments' => ['testing' => 0.0],
            'trk-monitor.sampling.routes' => ['api/orders*' => 1.0, 'GET /health-ish' => 0.0],
        ]);
        Route::get('/api/orders/{id}', fn () => 'ok');
        Route::get('/other', fn () => 'ok');

        $this->get('/api/orders/1');
        $this->get('/other');

        expect(collect(monitorPayloads('transactions'))->pluck('name')->all())->toBe(['GET /api/orders/{id}']);
    });

    it('applies environment rules', function () {
        retrace(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.sampling.environments' => ['testing' => 1.0]]);
        Route::get('/a', fn () => 'ok');

        $this->get('/a');

        expect(monitorPayloads('transactions'))->toHaveCount(1);
    });

    it('applies job rules', function () {
        retrace(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.sampling.jobs' => ['TrkTraced*' => 1.0]]);

        expect(app(Options::class)->transactionSampleRate('queue.job', [TrkTracedJob::class]))->toBe(1.0)
            ->and(app(Options::class)->transactionSampleRate('queue.job', ['App\\Jobs\\Other']))->toBe(0.0);
    });

    it('keeps an unsampled transaction when an error is captured, without its normal spans', function () {
        retrace(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.sampling.errors' => 1.0]);
        Route::get('/boom', function () {
            DB::table('orders')->count();
            Monitor::captureException(new RuntimeException('handled'));

            return 'ok';
        });

        $this->get('/boom')->assertOk();

        $tx = monitorPayloads('transactions');
        $events = monitorPayloads('events');

        expect($tx)->toHaveCount(1)
            ->and($tx[0]['sampled'])->toBeFalse()
            ->and($tx[0]['tags']['has_error'])->toBe('true')
            ->and($tx[0]['measurements']['query_count'])->toBe(1)
            ->and($events)->toHaveCount(1)
            ->and($events[0]['trace']['trace_id'])->toBe($tx[0]['trace_id'])
            ->and($events[0]['trace']['span_id'])->toBe($tx[0]['span_id']);
    });

    it('keeps capturing errors when transaction sampling is zero', function () {
        retrace(['trk-monitor.sampling.transactions' => 0.0, 'trk-monitor.sampling.errors' => 1.0]);

        Monitor::captureException(new RuntimeException('independent'));

        expect(monitorPayloads('events'))->toHaveCount(1);
    });

    it('propagates the unsampled decision downstream', function () {
        retrace(['trk-monitor.sampling.transactions' => 0.0]);
        Route::get('/a', function () {
            Http::get('https://payments.example.com/a');

            return 'ok';
        });

        $this->get('/a');

        expect(outgoing()[0]->header('traceparent')[0])->toEndWith('-00');
    });
});

it('produces accurate, consistent timings', function () {
    Route::get('/slow', function () {
        usleep(30_000);
        Http::get('https://payments.example.com/a');

        return 'ok';
    });

    $this->get('/slow');

    $tx = monitorPayloads('transactions')[0];
    $span = collect($tx['spans'])->firstWhere('op', 'http.client');

    expect($tx['duration_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and(round(($tx['timestamp'] - $tx['start_timestamp']) * 1000, 1))->toEqualWithDelta($tx['duration_ms'], 1.0)
        ->and(($span['start_timestamp'] - $tx['start_timestamp']) * 1000)->toBeGreaterThanOrEqual(29.0);
});
