<?php

declare(strict_types=1);

namespace Trk\Monitor\Performance;

/**
 * A complete monitored operation: an HTTP request or a queue job.
 *
 * Counters (query count, database time, slow queries) are always maintained. Spans are kept
 * for sampled transactions; unsampled transactions keep only slow spans so that a slow
 * request can still be reported with its culprit query.
 */
final class Transaction
{
    public readonly string $spanId;

    public readonly float $startTimestamp;

    private readonly int $startHr;

    private ?float $durationMs = null;

    /** @var list<Span> */
    private array $spans = [];

    private int $droppedSpans = 0;

    private int $queryCount = 0;

    private float $dbTimeMs = 0.0;

    private int $slowQueryCount = 0;

    private bool $keep = false;

    public string $status = 'ok';

    /** @var array<string, mixed> */
    public array $http = [];

    /** @var array<string, scalar|null> */
    public array $tags = [];

    /** @var array<string, mixed> */
    public array $contexts = [];

    /** @var array<string, mixed>|null */
    public ?array $user = null;

    public function __construct(
        public string $name,
        public readonly string $op,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly bool $sampled,
        private readonly int $maxSpans = 100,
    ) {
        $this->spanId = bin2hex(random_bytes(8));
        $this->startTimestamp = microtime(true);
        $this->startHr = hrtime(true);
    }

    public function recordQuery(string $sql, float $durationMs, bool $slow, array $data, bool $keepSpan): void
    {
        $this->queryCount++;
        $this->dbTimeMs += $durationMs;

        if ($slow) {
            $this->slowQueryCount++;
            $this->keep = true;
        }

        if (! $slow && (! $this->sampled || ! $keepSpan)) {
            return;
        }

        if (count($this->spans) >= $this->maxSpans) {
            $this->droppedSpans++;

            return;
        }

        $this->spans[] = new Span(
            parentSpanId: $this->spanId,
            op: 'db.query',
            description: $sql,
            startTimestamp: microtime(true) - $durationMs / 1000,
            durationMs: $durationMs,
            data: $data + ['slow' => $slow],
        );
    }

    /** Forces the transaction to be reported even if it was not sampled. */
    public function keep(): void
    {
        $this->keep = true;
    }

    public function shouldSend(): bool
    {
        return $this->sampled || $this->keep;
    }

    public function finish(): float
    {
        return $this->durationMs ??= (hrtime(true) - $this->startHr) / 1_000_000;
    }

    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function slowQueryCount(): int
    {
        return $this->slowQueryCount;
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @return array<string, mixed> */
    public function toPayload(string $environment, ?string $release, string $sdkVersion): array
    {
        $duration = $this->finish();

        return array_filter([
            'transaction_id' => $this->spanId,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => mb_substr($this->name, 0, 512),
            'op' => $this->op,
            'status' => $this->status,
            'start_timestamp' => round($this->startTimestamp, 6),
            'timestamp' => round($this->startTimestamp + $duration / 1000, 6),
            'duration_ms' => round($duration, 3),
            'environment' => $environment,
            'release' => $release,
            'sampled' => $this->sampled,
            'http' => $this->http,
            'measurements' => [
                'query_count' => $this->queryCount,
                'db_time_ms' => round($this->dbTimeMs, 3),
                'slow_query_count' => $this->slowQueryCount,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'dropped_spans' => $this->droppedSpans,
            ],
            'tags' => $this->tags,
            'contexts' => $this->contexts,
            'user' => $this->user,
            'spans' => array_map(fn (Span $s) => $s->toArray(), $this->spans),
            'sdk' => ['name' => 'trk/laravel-monitor', 'version' => $sdkVersion],
        ], fn ($v) => $v !== null && $v !== []);
    }
}
