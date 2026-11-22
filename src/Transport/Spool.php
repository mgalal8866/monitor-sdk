<?php

declare(strict_types=1);

namespace Trk\Monitor\Transport;

use Throwable;

/**
 * A bounded on-disk buffer for payloads that could not be delivered (central server
 * unreachable). `php artisan trk-monitor:flush` re-sends them.
 */
final class Spool
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxFiles = 500,
    ) {}

    /** @param array<string, mixed> $payload */
    public function store(string $endpoint, array $payload): bool
    {
        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
                return false;
            }

            if ($this->count() >= $this->maxFiles) {
                return false;
            }

            $file = $this->directory.DIRECTORY_SEPARATOR.sprintf('%d-%s.json', (int) (microtime(true) * 1000), bin2hex(random_bytes(4)));

            return @file_put_contents($file, json_encode(['endpoint' => $endpoint, 'payload' => $payload], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function count(): int
    {
        return count($this->files());
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Re-sends spooled payloads oldest first; stops at the first retryable failure.
     *
     * @param  callable(string, array<string, mixed>): TransportResult  $send
     * @return array{sent: int, dropped: int, remaining: int}
     */
    public function flush(callable $send, int $limit = 500): array
    {
        $sent = 0;
        $dropped = 0;

        foreach (array_slice($this->files(), 0, $limit) as $file) {
            $entry = json_decode((string) @file_get_contents($file), true);

            if (! is_array($entry) || ! isset($entry['endpoint'], $entry['payload']) || ! is_array($entry['payload'])) {
                @unlink($file);
                $dropped++;

                continue;
            }

            $result = $send((string) $entry['endpoint'], $entry['payload']);

            if ($result->successful()) {
                @unlink($file);
                $sent++;
            } elseif ($result->permanentFailure()) {
                @unlink($file);
                $dropped++;
            } else {
                break;
            }
        }

        return ['sent' => $sent, 'dropped' => $dropped, 'remaining' => $this->count()];
    }
}
