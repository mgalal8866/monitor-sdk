<?php

use Trk\Monitor\Support\TraceContext;

it('generates valid W3C identifiers', function () {
    $trace = new TraceContext;

    expect($trace->traceId())->toMatch('/^[0-9a-f]{32}$/')
        ->and($trace->spanId())->toMatch('/^[0-9a-f]{16}$/')
        ->and($trace->traceparent())->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/');
});

it('continues a valid incoming traceparent', function () {
    $trace = new TraceContext;

    expect($trace->continueFrom('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))->toBeTrue()
        ->and($trace->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($trace->parentSpanId())->toBe('00f067aa0ba902b7')
        ->and($trace->spanId())->not->toBe('00f067aa0ba902b7');
});

it('ignores invalid trace context safely', function (string $header) {
    $trace = new TraceContext;
    $original = $trace->traceId();

    expect($trace->continueFrom($header))->toBeFalse()->and($trace->traceId())->toBe($original);
})->with([
    'garbage' => ['not-a-traceparent'],
    'zero trace' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'zero parent' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'bad version' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'short' => ['00-4bf92f35-00f067aa0ba902b7-01'],
]);

it('isolates traces between executions', function () {
    $trace = new TraceContext;
    $first = $trace->traceId();
    $trace->reset();

    expect($trace->traceId())->not->toBe($first);
});
