<?php

declare(strict_types=1);

namespace Trk\Monitor\Monitors;

use Illuminate\Queue\Events\JobQueued;
use Throwable;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Span;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Support\TraceContext;

/**
 * Queue dispatch instrumentation:
 *  - a `queue.publish` span for every job pushed while a transaction is running;
 *  - trace context stored in the job payload (`trk_trace`) so the worker's `queue.job`
 *    transaction continues the trace as a child of the dispatch span.
 *
 * Only identifiers are added to the payload; job data is never read or transmitted.
 */
final class QueueMonitor
{
    public const PAYLOAD_KEY = 'trk_trace';

    /** @var array<string, Span> */
    private array $pending = [];

    public function __construct(
        private readonly Options $options,
        private readonly Tracer $tracer,
        private readonly TraceContext $trace,
    ) {}

    /**
     * Queue::createPayloadUsing() callback.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPayload(?string $connection, ?string $queue, array $payload): array
    {
        if (Monitor::isSuppressed() || ! $this->options->isUsable()) {
            return [];
        }

        try {
            $name = (string) ($payload['displayName'] ?? 'job');

            if ($name === SendPayload::class || isset($payload[self::PAYLOAD_KEY])) {
                return [];
            }

            $span = null;
            $transaction = $this->tracer->current();

            if ($transaction !== null && filter_var($this->options->tracing('queue_spans', true), FILTER_VALIDATE_BOOL)) {
                $span = $transaction->startSpan('queue.publish', $name, array_filter([
                    'connection' => $connection,
                    'queue' => $queue,
                ], fn ($v) => $v !== null && $v !== ''));
                $this->pending[$span->spanId] = $span;

                // Bounded: drivers that never fire JobQueued must not leak memory in long-running workers.
                if (count($this->pending) > 500) {
                    array_shift($this->pending);
                }
            }

            if (! filter_var($this->options->tracing('propagate', true), FILTER_VALIDATE_BOOL)) {
                return [];
            }

            return [self::PAYLOAD_KEY => array_filter([
                'traceparent' => $this->trace->traceparent($span->spanId ?? $this->tracer->activeSpanId(), $this->tracer->sampledFlag()),
                'tracestate' => $this->trace->traceState(),
            ], fn ($v) => $v !== null)];
        } catch (Throwable) {
            return [];
        }
    }

    public function queued(JobQueued $event): void
    {
        try {
            $payload = json_decode((string) $event->payload, true);
            $span = $this->claim(self::spanIdFrom(is_array($payload) ? $payload : []));

            if ($span !== null) {
                $this->tracer->current()?->finishSpan($span);
                $span->finish();
            }
        } catch (Throwable) {
        }
    }

    /** Takes ownership of a still-open dispatch span (used for jobs executed synchronously). */
    public function claim(?string $spanId): ?Span
    {
        if ($spanId === null || ! isset($this->pending[$spanId])) {
            return null;
        }

        $span = $this->pending[$spanId];
        unset($this->pending[$spanId]);

        return $span;
    }

    /** @param array<string, mixed> $payload */
    public static function spanIdFrom(array $payload): ?string
    {
        $parsed = TraceContext::parse(self::traceparentFrom($payload));

        return $parsed[1] ?? null;
    }

    /** @param array<string, mixed> $payload */
    public static function traceparentFrom(array $payload): ?string
    {
        $value = $payload[self::PAYLOAD_KEY]['traceparent'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    public static function tracestateFrom(array $payload): ?string
    {
        $value = $payload[self::PAYLOAD_KEY]['tracestate'] ?? null;

        return is_string($value) ? $value : null;
    }
}
