<?php

declare(strict_types=1);

namespace Trk\Monitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

/**
 * Delivers one payload from the application's queue.
 * Retried a bounded number of times; afterwards the payload is spooled to disk.
 */
final class SendPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $endpoint, public readonly array $payload) {}

    /** @return list<int> Exponential backoff with jitter, in seconds. */
    public function backoff(): array
    {
        return [10 + random_int(0, 5), 60 + random_int(0, 15), 300 + random_int(0, 60)];
    }

    public function handle(HttpTransport $transport, Spool $spool): void
    {
        Monitor::withoutCapturing(function () use ($transport, $spool) {
            // The job itself handles retries; keep HTTP-level retries short inside workers.
            $result = $transport->post($this->endpoint, $this->payload, retries: 1);

            if ($result->successful() || $result->skipped || $result->permanentFailure()) {
                return;
            }

            if ($this->attempts() >= $this->tries) {
                $spool->store($this->endpoint, $this->payload);

                return;
            }

            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        });
    }

    public function failed(?Throwable $e): void
    {
        try {
            app(Spool::class)->store($this->endpoint, $this->payload);
        } catch (Throwable) {
            // Never let monitoring failures cascade.
        }
    }
}
