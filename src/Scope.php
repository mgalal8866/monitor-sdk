<?php

declare(strict_types=1);

namespace Trk\Monitor;

/**
 * Per-request (or per-job) context attached to every captured event.
 * Reset between requests and queue jobs so data never leaks across executions.
 */
final class Scope
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var array<string, scalar|null> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    /** @var list<string>|null */
    private ?array $fingerprint = null;

    private ?string $transaction = null;

    public function __construct(private readonly int $maxBreadcrumbs = 50) {}

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function setTag(string $key, string|int|float|bool|null $value): void
    {
        $this->tags[mb_substr($key, 0, 64)] = is_string($value) ? mb_substr($value, 0, 200) : $value;
    }

    /** @return array<string, scalar|null> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @param array<string, mixed> $context */
    public function setContext(string $name, array $context): void
    {
        $this->contexts[$name] = $context;
    }

    /** @return array<string, array<string, mixed>> */
    public function contexts(): array
    {
        return $this->contexts;
    }

    /** @param array<string, mixed> $breadcrumb */
    public function addBreadcrumb(array $breadcrumb): void
    {
        $this->breadcrumbs[] = [
            'timestamp' => $breadcrumb['timestamp'] ?? microtime(true),
            'category' => (string) ($breadcrumb['category'] ?? 'default'),
            'message' => isset($breadcrumb['message']) ? mb_substr((string) $breadcrumb['message'], 0, 2048) : null,
            'level' => (string) ($breadcrumb['level'] ?? 'info'),
            'data' => is_array($breadcrumb['data'] ?? null) ? $breadcrumb['data'] : null,
        ];

        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    /** @return list<array<string, mixed>> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /** @param list<string>|null $fingerprint */
    public function setFingerprint(?array $fingerprint): void
    {
        $this->fingerprint = $fingerprint === null ? null : array_map('strval', $fingerprint);
    }

    /** @return list<string>|null */
    public function fingerprint(): ?array
    {
        return $this->fingerprint;
    }

    public function setTransaction(?string $name): void
    {
        $this->transaction = $name;
    }

    public function transaction(): ?string
    {
        return $this->transaction;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->tags = [];
        $this->contexts = [];
        $this->breadcrumbs = [];
        $this->fingerprint = null;
        $this->transaction = null;
    }
}
