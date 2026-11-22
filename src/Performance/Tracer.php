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
 * Owns the transactions of the current execution (one HTTP request or one queue job).
 *
 * Sampling (decided once, when the transaction starts):
 *  - an incoming traceparent's sampled flag is followed (`sampling.respect_parent`);
 *  - otherwise route/job rules, then the environment rule, then `sampling.transactions`.
 * Slow, failed (5xx / failed job) transactions and transactions during which an error was
 * captured are always reported. Error events have their own, independent sampling rule.
 */
final class Tracer
{
    /** @var list<Transaction> */
    private array $stack = [];

    public function __construct(
        private readonly Options $options,
        private readonly TraceContext $trace,
        private readonly Delivery $delivery,
        private readonly Sanitizer $sanitizer,
    ) {}

    /** @param list<string> $samplingCandidates Values matched against `sampling.routes` / `sampling.jobs`. */
    public function start(string $name, string $op, array $samplingCandidates = []): ?Transaction
    {
        if (! $this->enabled()) {
            return null;
        }

        $transaction = new Transaction(
            name: $name,
            op: $op,
            traceId: $this->trace->traceId(),
            parentSpanId: $this->trace->parentSpanId(),
            sampled: $this->sampled($op, $samplingCandidates === [] ? [$name] : $samplingCandidates),
            maxSpans: max(1, (int) $this->options->get('performance.max_spans_per_transaction', 100)),
            // The transaction is this execution's span: error events and propagated headers use it.
            spanId: $this->trace->spanId(),
        );

        return $this->stack[] = $transaction;
    }

    public function enabled(): bool
    {
        return $this->options->isUsable() && $this->options->captures('performance') && $this->options->captures('transactions');
    }

    public function current(): ?Transaction
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /** The span outgoing calls originate from (innermost nested span), or this execution's span. */
    public function activeSpanId(): string
    {
        return $this->current()?->activeSpanId() ?? $this->trace->spanId();
    }

    /** Sampling flag to propagate downstream. */
    public function sampledFlag(): bool
    {
        return $this->current()->sampled ?? ($this->trace->parentSampled() ?? true);
    }

    /** Called when an error event is captured: the surrounding transaction is always kept. */
    public function errorCaptured(): void
    {
        if ($transaction = $this->current()) {
            $transaction->keep();
            $transaction->tags['has_error'] = 'true';
        }
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
            $this->remove($transaction);
        }
    }

    /** Drops the transaction without reporting it (e.g. excluded route). */
    public function discard(Transaction $transaction): void
    {
        $this->remove($transaction);
    }

    /** Forgets every open transaction (start of a new request in long-running workers). */
    public function reset(): void
    {
        $this->stack = [];
    }

    private function remove(Transaction $transaction): void
    {
        foreach ($this->stack as $i => $open) {
            if ($open === $transaction) {
                array_splice($this->stack, $i, 1);

                return;
            }
        }
    }

    /** @param list<string> $candidates */
    private function sampled(string $op, array $candidates): bool
    {
        $parent = $this->trace->parentSampled();

        if ($parent !== null && $this->options->respectParentSampling()) {
            return $parent;
        }

        $rate = $this->options->transactionSampleRate($op, $candidates);

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }
}
