<?php

declare(strict_types=1);

namespace Trk\Monitor;

use Illuminate\Support\Str;

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

    /**
     * Sample rate for a transaction. The most specific rule wins:
     *  1. `sampling.routes` (HTTP) or `sampling.jobs` (queue) — first matching pattern;
     *  2. `sampling.environments[<environment>]`;
     *  3. `sampling.transactions`.
     *
     * @param  list<string>  $candidates  Values matched against the patterns (e.g. "GET /api/orders", "api/orders").
     */
    public function transactionSampleRate(string $op, array $candidates): float
    {
        $rules = (array) $this->get($op === 'queue.job' ? 'sampling.jobs' : 'sampling.routes', []);

        foreach ($rules as $pattern => $rate) {
            if (! is_string($pattern) || ! is_numeric($rate)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (Str::is($pattern, $candidate) || Str::is(ltrim($pattern, '/'), ltrim($candidate, '/'))) {
                    return max(0.0, min(1.0, (float) $rate));
                }
            }
        }

        $byEnvironment = $this->get('sampling.environments.'.$this->environment());

        if (is_numeric($byEnvironment)) {
            return max(0.0, min(1.0, (float) $byEnvironment));
        }

        return $this->sampleRate('transactions');
    }

    /** Follow the sampling decision of an incoming traceparent (W3C "sampled" flag). */
    public function respectParentSampling(): bool
    {
        return filter_var($this->get('sampling.respect_parent', true), FILTER_VALIDATE_BOOL);
    }

    public function tracing(string $key, mixed $default = true): mixed
    {
        return $this->get("tracing.{$key}", $default);
    }

    /** Whether trace headers may be added to an outgoing request to $host. */
    public function propagatesTo(string $host): bool
    {
        if (! filter_var($this->tracing('propagate', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        foreach ((array) $this->tracing('propagation_targets', ['*']) as $pattern) {
            if (is_string($pattern) && Str::is(strtolower($pattern), strtolower($host))) {
                return true;
            }
        }

        return false;
    }

    public function timeout(): float
    {
        $value = $this->get('server.timeout', 3);

        return is_numeric($value) && $value > 0 ? min(30.0, (float) $value) : 3.0;
    }

    public function connectTimeout(): float
    {
        $value = $this->get('server.connect_timeout', 1);

        return is_numeric($value) && $value > 0 ? min(30.0, (float) $value) : 1.0;
    }

    public function retries(): int
    {
        return max(0, min(10, (int) $this->get('server.retries', 3)));
    }

    /** HTTP retries for payloads sent from inside a request or command (not from a queue worker). */
    public function inlineRetries(): int
    {
        return max(0, min(3, (int) $this->get('server.inline_retries', 0)));
    }

    public function circuitBreakerEnabled(): bool
    {
        return filter_var($this->get('circuit_breaker.enabled', true), FILTER_VALIDATE_BOOL);
    }

    /** Seconds the SDK stops contacting the server after a failure (doubles on repeated failures). */
    public function circuitBreakerCooldown(): int
    {
        return max(1, min(3600, (int) $this->get('circuit_breaker.cooldown', 30)));
    }

    public function circuitBreakerMaxCooldown(): int
    {
        return max($this->circuitBreakerCooldown(), min(86400, (int) $this->get('circuit_breaker.max_cooldown', 300)));
    }

    /** Maximum number of payloads kept on disk while the server is unreachable. */
    public function spoolMaxFiles(): int
    {
        return max(0, min(100000, (int) $this->get('spool.max_files', 1000)));
    }

    public function spoolAutoFlush(): bool
    {
        return filter_var($this->get('spool.auto_flush', true), FILTER_VALIDATE_BOOL);
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
