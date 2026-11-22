<?php

declare(strict_types=1);

namespace Trk\Monitor\Transport;

final readonly class TransportResult
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        public ?int $status,
        public ?array $body = null,
        public ?string $error = null,
        public bool $retryable = false,
        public ?int $retryAfter = null,
        public bool $skipped = false,
    ) {}

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(status: null, error: $error, retryable: $retryable);
    }

    public static function skipped(string $reason): self
    {
        return new self(status: null, error: $reason, skipped: true);
    }

    public function successful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /** The event will never be accepted as sent (validation, auth or size problems). */
    public function permanentFailure(): bool
    {
        return ! $this->successful() && ! $this->retryable && ! $this->skipped;
    }
}
