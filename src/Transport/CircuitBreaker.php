<?php

declare(strict_types=1);

namespace Trk\Monitor\Transport;

use Throwable;

/**
 * Stops the SDK from talking to an unreachable central server.
 *
 * After a delivery failure (connection error, timeout, 429 or 5xx) the circuit "opens" and
 * every process on this machine skips the network for a cooldown period: payloads are spooled
 * to disk instantly instead. When the cooldown expires one process is allowed to probe; success
 * closes the circuit, failure re-opens it with a longer (exponential, capped) cooldown.
 *
 * State lives in a small file so it is shared between PHP-FPM workers, queue workers and the
 * scheduler without depending on the application's cache or Redis (which may be down too).
 * If the file cannot be written the breaker still works per process, in memory.
 */
final class CircuitBreaker
{
    /** @var array{open_until: float, trips: int}|null */
    private ?array $memory = null;

    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(
        private readonly string $file,
        private readonly bool $enabled = true,
        private readonly int $cooldown = 30,
        private readonly int $maxCooldown = 300,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Whether a request may be attempted now. Claims the probe slot when the cooldown has expired. */
    public function allowsRequest(): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $state = $this->state();

        if ($state['trips'] === 0) {
            return true;
        }

        $now = ($this->clock)();

        if ($now < $state['open_until']) {
            return false;
        }

        // Half-open: let this process probe and keep everyone else out while it does.
        $this->write(['open_until' => $now + $this->cooldownFor($state['trips']), 'trips' => $state['trips']]);

        return true;
    }

    public function recordSuccess(): void
    {
        if (! $this->enabled || $this->state()['trips'] === 0) {
            return;
        }

        $this->memory = ['open_until' => 0.0, 'trips' => 0];

        try {
            if (is_file($this->file)) {
                @unlink($this->file);
            }
        } catch (Throwable) {
        }
    }

    /** Opens the circuit. A server-provided Retry-After (seconds) extends the cooldown. */
    public function recordFailure(?int $retryAfter = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $trips = min(30, $this->state()['trips'] + 1);
        $cooldown = max($this->cooldownFor($trips), min($this->maxCooldown * 4, max(0, (int) $retryAfter)));

        $this->write(['open_until' => ($this->clock)() + $cooldown, 'trips' => $trips]);
    }

    public function isOpen(): bool
    {
        $state = $this->state();

        return $this->enabled && $state['trips'] > 0 && ($this->clock)() < $state['open_until'];
    }

    /** Seconds until the next probe is allowed (0 when closed). */
    public function retryIn(): int
    {
        return $this->isOpen() ? (int) ceil($this->state()['open_until'] - ($this->clock)()) : 0;
    }

    public function reset(): void
    {
        $this->memory = null;

        try {
            if (is_file($this->file)) {
                @unlink($this->file);
            }
        } catch (Throwable) {
        }
    }

    private function cooldownFor(int $trips): int
    {
        return (int) min($this->maxCooldown, $this->cooldown * (2 ** max(0, min(10, $trips - 1))));
    }

    /** @return array{open_until: float, trips: int} */
    private function state(): array
    {
        try {
            if (is_file($this->file)) {
                $data = json_decode((string) @file_get_contents($this->file), true);

                if (is_array($data) && isset($data['open_until'], $data['trips'])) {
                    return $this->memory = ['open_until' => (float) $data['open_until'], 'trips' => (int) $data['trips']];
                }
            } elseif ($this->memory !== null && $this->memory['trips'] > 0 && $this->fileWritable()) {
                // Another process closed the circuit (deleted the file).
                return $this->memory = ['open_until' => 0.0, 'trips' => 0];
            }
        } catch (Throwable) {
        }

        return $this->memory ?? ['open_until' => 0.0, 'trips' => 0];
    }

    /** @param array{open_until: float, trips: int} $state */
    private function write(array $state): void
    {
        $this->memory = $state;

        try {
            $dir = dirname($this->file);
            if (! is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }

            @file_put_contents($this->file, json_encode($state), LOCK_EX);
        } catch (Throwable) {
            // In-memory state still protects this process.
        }
    }

    private function fileWritable(): bool
    {
        $dir = dirname($this->file);

        return is_dir($dir) && is_writable($dir);
    }
}
