<?php

declare(strict_types=1);

namespace Trk\Monitor\Performance;

/**
 * One timed operation inside a transaction (database query, outgoing HTTP call, queue dispatch…).
 *
 * Spans are either recorded after the fact with a known duration (queries) or started and
 * finished around an operation (HTTP calls, jobs). Timing uses the monotonic clock.
 */
final class Span
{
    public readonly string $spanId;

    public string $status;

    /** @var array<string, mixed> */
    public array $data;

    private ?float $durationMs;

    private readonly int $startHr;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $parentSpanId,
        public readonly string $op,
        public string $description,
        public readonly float $startTimestamp,
        ?float $durationMs = null,
        string $status = 'ok',
        array $data = [],
        ?string $spanId = null,
    ) {
        $this->spanId = $spanId ?? bin2hex(random_bytes(8));
        $this->durationMs = $durationMs === null ? null : max(0.0, $durationMs);
        $this->status = $status;
        $this->data = $data;
        $this->startHr = hrtime(true);
    }

    /** Finishes the span (idempotent). */
    public function finish(?string $status = null): void
    {
        if ($this->durationMs !== null) {
            return;
        }

        $this->durationMs = (hrtime(true) - $this->startHr) / 1_000_000;

        if ($status !== null) {
            $this->status = $status;
        }
    }

    public function isFinished(): bool
    {
        return $this->durationMs !== null;
    }

    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $duration = $this->durationMs ?? 0.0;

        return [
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'op' => $this->op,
            'description' => $this->description,
            'start_timestamp' => round($this->startTimestamp, 6),
            'timestamp' => round($this->startTimestamp + $duration / 1000, 6),
            'duration_ms' => round($duration, 3),
            'status' => $this->status,
            'data' => $this->data,
        ];
    }
}
