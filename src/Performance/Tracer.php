<?php

declare(strict_types=1);

namespace Trk\Monitor\Performance;

use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Support\TraceContext;
use Trk\Monitor\Transport\Delivery;

/**
 * Owns the transaction of the current execution (one HTTP request or one queue job).
 *
 * Sampling: `sampling.transactions` decides which normal transactions are reported.
 * Slow, failed (5xx) and long-running transactions are always reported. Error events have
 * their own, independent sampling rule.
 */
final class Tracer
{
    private ?Transaction $current = null;

    public function __construct(
        private readonly Options $options,
        private readonly TraceContext $trace,
        private readonly Delivery $delivery,
        private readonly Sanitizer $sanitizer,
    ) {}

    public function start(string $name, string $op): ?Transaction
    {
        if (! $this->options->isUsable() || ! $this->options->captures('performance') || ! $this->options->captures('transactions')) {
            return null;
        }

        return $this->current = new Transaction(
            name: $name,
            op: $op,
            traceId: $this->trace->traceId(),
            parentSpanId: $this->trace->parentSpanId(),
            sampled: $this->sampled(),
            maxSpans: max(1, (int) $this->options->get('performance.max_spans_per_transaction', 100)),
        );
    }

    public function current(): ?Transaction
    {
        return $this->current;
    }

    /** Finishes and reports the transaction when sampled or flagged. Never throws. */
    public function finish(Transaction $transaction, ?float $slowThresholdMs = null): void
    {
        try {
            $duration = $transaction->finish();

            if ($slowThresholdMs !== null && $duration >= $slowThresholdMs) {
                $transaction->tags['slow'] = 'true';
                $transaction->keep();
            }

            if (in_array($transaction->status, ['error', 'failed'], true)) {
                $transaction->keep();
            }

            if ($transaction->shouldSend()) {
                $payload = $transaction->toPayload($this->options->environment(), $this->options->release(), Monitor::VERSION);

                foreach (['http', 'tags', 'contexts', 'user'] as $key) {
                    if (isset($payload[$key])) {
                        $payload[$key] = $this->sanitizer->sanitize($payload[$key]);
                    }
                }

                Monitor::withoutCapturing(fn () => $this->delivery->send('transactions', $payload));
            }
        } catch (Throwable) {
            // Performance monitoring must never affect the application.
        } finally {
            if ($this->current === $transaction) {
                $this->current = null;
            }
        }
    }

    /** Drops the current transaction without reporting it (e.g. excluded route). */
    public function discard(Transaction $transaction): void
    {
        if ($this->current === $transaction) {
            $this->current = null;
        }
    }

    private function sampled(): bool
    {
        $rate = $this->options->sampleRate('transactions');

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }
}
