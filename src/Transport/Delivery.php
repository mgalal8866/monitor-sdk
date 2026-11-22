<?php

declare(strict_types=1);

namespace Trk\Monitor\Transport;

use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Options;

/**
 * Decides how a payload leaves the application:
 *
 *  1. Queue (default): pushed to the configured connection/queue. If that connection is
 *     "sync" or dispatching fails, delivery falls back to (2).
 *  2. Deferred: buffered in memory and sent after the HTTP response has been returned
 *     (application terminating), or immediately when running in the console.
 *
 * Payloads that cannot be delivered because the server is unreachable are spooled to disk.
 * While the circuit breaker is open (server known to be down) payloads go straight to the
 * spool: no network call, no queued job, no added latency for the application.
 */
final class Delivery
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $buffer = [];

    private bool $terminatingHookRegistered = false;

    /** Set when dispatching to the queue failed once; avoids paying for a broken queue repeatedly. */
    private bool $queueUnavailable = false;

    public function __construct(
        private readonly Options $options,
        private readonly HttpTransport $transport,
        private readonly Spool $spool,
        private readonly Application $app,
    ) {}

    /** @param array<string, mixed> $payload */
    public function send(string $endpoint, array $payload): void
    {
        try {
            if (! $this->options->isUsable()) {
                return;
            }

            if ($this->transport->breaker()->isOpen()) {
                $this->spool->store($endpoint, $payload);

                return;
            }

            if ($this->options->queueEnabled() && ! $this->queueUnavailable && $this->queueIsAsync()) {
                try {
                    SendPayload::dispatch($endpoint, $payload)
                        ->onConnection($this->options->queueConnection())
                        ->onQueue($this->options->queueName());

                    return;
                } catch (Throwable) {
                    // Queue unavailable: fall through to deferred delivery for the rest of this process.
                    $this->queueUnavailable = true;
                }
            }

            $this->defer($endpoint, $payload);
        } catch (Throwable) {
            // Monitoring must never affect the application.
        }
    }

    /** Sends immediately, bypassing queue, buffer and circuit breaker (used by commands). */
    public function sendNow(string $endpoint, array $payload): TransportResult
    {
        return $this->transport->post($endpoint, $payload, force: true);
    }

    /** Sends every buffered payload. Safe to call multiple times. */
    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        foreach ($buffer as [$endpoint, $payload]) {
            // No HTTP-level retries inside the request lifecycle: one failure opens the circuit
            // and the remaining payloads are spooled without touching the network.
            $result = $this->transport->post($endpoint, $payload, retries: $this->options->inlineRetries());

            if (! $result->successful() && $result->retryable) {
                $this->spool->store($endpoint, $payload);
            }
        }
    }

    public function pending(): int
    {
        return count($this->buffer);
    }

    /** @param array<string, mixed> $payload */
    private function defer(string $endpoint, array $payload): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->buffer[] = [$endpoint, $payload];
            $this->flush();

            return;
        }

        $this->buffer[] = [$endpoint, $payload];

        if (! $this->terminatingHookRegistered) {
            $this->terminatingHookRegistered = true;
            $this->app->terminating(function () {
                try {
                    $this->flush();
                } catch (Throwable) {
                    // Never interfere with application shutdown.
                }
            });
        }
    }

    private function queueIsAsync(): bool
    {
        try {
            $connection = $this->options->queueConnection() ?? (string) $this->app['config']->get('queue.default');
            $driver = $this->app['config']->get("queue.connections.{$connection}.driver");

            return $driver !== null && $driver !== 'sync' && $driver !== 'null';
        } catch (Throwable) {
            return false;
        }
    }
}
