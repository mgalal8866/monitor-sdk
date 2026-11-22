<?php

declare(strict_types=1);

namespace Trk\Monitor;

/**
 * Validated, typed view over config/trk-monitor.php.
 * Invalid values fall back to safe defaults and are reported by `trk-monitor:status`.
 */
final class Options
{
    /** @var list<string> */
    private array $problems = [];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function enabled(): bool
    {
        return filter_var($this->get('enabled', true), FILTER_VALIDATE_BOOL);
    }

    /** True when monitoring is enabled and every required setting is present and valid. */
    public function isUsable(): bool
    {
        return $this->enabled() && $this->problems() === [];
    }

    /** @return list<string> Human-readable configuration problems (never includes secrets). */
    public function problems(): array
    {
        $this->problems = [];

        $url = (string) $this->get('server.url', '');
        if ($url === '') {
            $this->problems[] = 'TRK_MONITOR_URL is not set.';
        } elseif (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->problems[] = 'TRK_MONITOR_URL must be an http(s) URL.';
        }

        if ((string) $this->get('project_key', '') === '') {
            $this->problems[] = 'TRK_MONITOR_PROJECT_KEY is not set.';
        }

        if ((string) $this->get('project_secret', '') === '') {
            $this->problems[] = 'TRK_MONITOR_PROJECT_SECRET is not set.';
        }

        return $this->problems;
    }

    public function apiBase(): string
    {
        return rtrim((string) $this->get('server.url', ''), '/').'/api/v1/monitor';
    }

    public function projectKey(): string
    {
        return (string) $this->get('project_key', '');
    }

    public function projectSecret(): string
    {
        return (string) $this->get('project_secret', '');
    }

    public function environment(): string
    {
        $env = (string) $this->get('environment', 'production');

        return preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $env) ? $env : 'production';
    }

    public function release(): ?string
    {
        $release = $this->get('release');

        return is_string($release) && $release !== '' ? mb_substr($release, 0, 191) : null;
    }

    public function sampleRate(string $type): float
    {
        $rate = $this->get("sampling.{$type}", 1.0);

        return is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 1.0;
    }

    public function timeout(): float
    {
        $value = $this->get('server.timeout', 3);

        return is_numeric($value) && $value > 0 ? min(30.0, (float) $value) : 3.0;
    }

    public function connectTimeout(): float
    {
        $value = $this->get('server.connect_timeout', 2);

        return is_numeric($value) && $value > 0 ? min(30.0, (float) $value) : 2.0;
    }

    public function retries(): int
    {
        return max(0, min(10, (int) $this->get('server.retries', 3)));
    }

    public function signRequests(): bool
    {
        return filter_var($this->get('server.sign_requests', true), FILTER_VALIDATE_BOOL);
    }

    public function compress(): bool
    {
        return filter_var($this->get('server.compress', true), FILTER_VALIDATE_BOOL) && function_exists('gzencode');
    }

    public function maxPayloadSize(): int
    {
        return max(16 * 1024, (int) $this->get('max_payload_size', 1024 * 1024));
    }

    public function captures(string $what): bool
    {
        return filter_var($this->get("capture.{$what}", true), FILTER_VALIDATE_BOOL);
    }

    public function queueEnabled(): bool
    {
        return filter_var($this->get('queue.enabled', true), FILTER_VALIDATE_BOOL);
    }

    public function queueConnection(): ?string
    {
        $connection = $this->get('queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function queueName(): string
    {
        $name = $this->get('queue.name', 'monitoring');

        return is_string($name) && $name !== '' ? $name : 'monitoring';
    }

    public function privacy(string $key, mixed $default = null): mixed
    {
        return $this->get("privacy.{$key}", $default);
    }

    /** @return list<string> */
    public function excluded(string $type): array
    {
        return array_values(array_filter((array) $this->get("exclude.{$type}", []), 'is_string'));
    }
}
