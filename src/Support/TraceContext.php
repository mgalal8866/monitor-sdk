<?php

declare(strict_types=1);

namespace Trk\Monitor\Support;

/**
 * W3C Trace Context identifiers for the current execution (request or job).
 * Continues an incoming `traceparent` when valid, otherwise starts a new trace.
 */
final class TraceContext
{
    private string $traceId;

    private string $spanId;

    private ?string $parentSpanId = null;

    private bool $sampled = true;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->spanId = bin2hex(random_bytes(8));
        $this->parentSpanId = null;
        $this->sampled = true;
    }

    /** Continues a trace from a W3C traceparent header. Invalid values are ignored. */
    public function continueFrom(?string $traceparent): bool
    {
        if ($traceparent === null) {
            return false;
        }

        if (! preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', strtolower(trim($traceparent)), $m)) {
            return false;
        }

        [, $version, $traceId, $parentId, $flags] = $m;

        if ($version === 'ff' || $traceId === str_repeat('0', 32) || $parentId === str_repeat('0', 16)) {
            return false;
        }

        $this->traceId = $traceId;
        $this->parentSpanId = $parentId;
        $this->spanId = bin2hex(random_bytes(8));
        $this->sampled = (hexdec($flags) & 1) === 1;

        return true;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function spanId(): string
    {
        return $this->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function traceparent(): string
    {
        return '00-'.$this->traceId.'-'.$this->spanId.'-'.($this->sampled ? '01' : '00');
    }

    /** @return array{trace_id: string, span_id: string} */
    public function toArray(): array
    {
        return ['trace_id' => $this->traceId, 'span_id' => $this->spanId];
    }
}
