<?php

declare(strict_types=1);

namespace Trk\Monitor\Performance;

/** One timed operation inside a transaction (e.g. a database query). */
final class Span
{
    public readonly string $spanId;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $parentSpanId,
        public readonly string $op,
        public readonly string $description,
        public readonly float $startTimestamp,
        public readonly float $durationMs,
        public readonly string $status = 'ok',
        public readonly array $data = [],
    ) {
        $this->spanId = bin2hex(random_bytes(8));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'op' => $this->op,
            'description' => $this->description,
            'start_timestamp' => round($this->startTimestamp, 6),
            'timestamp' => round($this->startTimestamp + $this->durationMs / 1000, 6),
            'duration_ms' => round($this->durationMs, 3),
            'status' => $this->status,
            'data' => $this->data,
        ];
    }
}
