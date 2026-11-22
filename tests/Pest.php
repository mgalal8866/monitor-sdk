<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Trk\Monitor\Monitor;
use Trk\Monitor\Tests\TestCase;
use Trk\Monitor\Transport\Delivery;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/** Fakes the central API, accepting every request. */
function fakeCentral(int $status = 202, array $body = ['success' => true, 'data' => ['accepted' => 1]]): void
{
    Http::fake(['monitor.test/*' => Http::response($body, $status)]);
}

/** Sends buffered events (delivery is deferred in tests) and returns the payloads sent to /events. */
function sentEvents(): array
{
    app(Delivery::class)->flush();

    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/api/v1/monitor/events'))
        ->map(fn (Request $r) => json_decode(decodedBody($r), true))
        ->values()
        ->all();
}

function decodedBody(Request $request): string
{
    $body = $request->body();

    return $request->hasHeader('Content-Encoding', 'gzip') ? (string) gzdecode($body) : $body;
}

function monitor(): Monitor
{
    return app(Monitor::class);
}
