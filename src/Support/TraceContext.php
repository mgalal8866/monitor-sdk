<?php

declare(strict_types=1);

namespace Trk\Monitor\Support;

/**
 * W3C Trace Context identifiers for the current execution (request or job).
 *
 * Continues an incoming `traceparent` when valid, otherwise starts a new trace. The incoming
 * `tracestate` is kept verbatim (when well-formed) so it can be forwarded unchanged. Invalid
 * input never throws: it simply starts a fresh trace.
 */
final class TraceContext
{
    private const TRACESTATE_MAX = 512;

    private string $traceId;

    private string $spanId;

    private ?string $parentSpanId = null;

    private ?string $traceState = null;

    /** Sampling decision inherited from the parent, or null when this execution started the trace. */
    private ?bool $parentSampled = null;

    public function __construct()
    {
        $this->reset();
    }

    /** Starts a brand-new trace (new request or job): nothing leaks from the previous one. */
    public function reset(): void
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->spanId = bin2hex(random_bytes(8));
        $this->parentSpanId = null;
        $this->traceState = null;
        $this->parentSampled = null;
    }

    /** Continues a trace from a W3C traceparent header. Invalid values are ignored. */
    public function continueFrom(?string $traceparent, ?string $tracestate = null): bool
    {
        $parsed = self::parse($traceparent);

        if ($parsed === null) {
            return false;
        }

        [$this->traceId, $this->parentSpanId, $this->parentSampled] = $parsed;
        $this->spanId = bin2hex(random_bytes(8));
        $this->traceState = self::validTraceState($tracestate);

        return true;
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null [trace id, parent span id, sampled]
     */
    public static function parse(?string $traceparent): ?array
    {
        if ($traceparent === null || strlen($traceparent) > 256) {
            return null;
        }

        // Version 00 is exactly 4 fields; future versions may append fields (W3C §3.2.4).
        if (! preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/', strtolower(trim($traceparent)), $m)) {
            return null;
        }

        [, $version, $traceId, $parentId, $flags] = $m;
        $extra = $m[5] ?? '';

        if ($version === 'ff' || ($version === '00' && $extra !== '')) {
            return null;
        }

        if ($traceId === str_repeat('0', 32) || $parentId === str_repeat('0', 16)) {
            return null;
        }

        return [$traceId, $parentId, (hexdec($flags) & 1) === 1];
    }

    private static function validTraceState(?string $tracestate): ?string
    {
        if ($tracestate === null) {
            return null;
        }

        $tracestate = trim($tracestate);

        if ($tracestate === '' || strlen($tracestate) > self::TRACESTATE_MAX || preg_match('/[^\x20-\x7e]/', $tracestate)) {
            return null;
        }

        return $tracestate;
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

    public function traceState(): ?string
    {
        return $this->traceState;
    }

    public function parentSampled(): ?bool
    {
        return $this->parentSampled;
    }

    /** traceparent header for an outgoing call made from $spanId (defaults to this execution's span). */
    public function traceparent(?string $spanId = null, ?bool $sampled = null): string
    {
        $sampled ??= $this->parentSampled ?? true;

        return '00-'.$this->traceId.'-'.($spanId ?? $this->spanId).'-'.($sampled ? '01' : '00');
    }

    /** @return array{trace_id: string, span_id: string} */
    public function toArray(): array
    {
        return ['trace_id' => $this->traceId, 'span_id' => $this->spanId];
    }
}
