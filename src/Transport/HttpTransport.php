<?php

declare(strict_types=1);

namespace Trk\Monitor\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;

/**
 * Sends payloads to the TrkMonitor ingestion API.
 *
 * - Authenticates with an HMAC signature by default (the secret never crosses the wire).
 * - Compresses bodies with gzip when available.
 * - Retries connection failures, 429 and 5xx responses with exponential backoff and jitter.
 * - Never throws: failures are returned as a TransportResult.
 * - Respects the circuit breaker: while the server is known to be down no request is made.
 *   Diagnostic commands pass $force to probe the server regardless.
 */
final class HttpTransport
{
    public const SIGNING_LABEL = 'trk-monitor-signing-v1';

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    private readonly CircuitBreaker $breaker;

    public function __construct(
        private readonly Options $options,
        private readonly HttpFactory $http,
        ?\Closure $sleeper = null,
        ?CircuitBreaker $breaker = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
        $this->breaker = $breaker ?? new CircuitBreaker('', enabled: false);
    }

    /** @param array<string, mixed> $payload */
    public function post(string $endpoint, array $payload, ?int $retries = null, bool $force = false): TransportResult
    {
        return $this->request('POST', $endpoint, $payload, $retries, $force);
    }

    public function get(string $endpoint, ?int $retries = 0, bool $force = false): TransportResult
    {
        return $this->request('GET', $endpoint, null, $retries, $force);
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    /** @param array<string, mixed>|null $payload */
    private function request(string $method, string $endpoint, ?array $payload, ?int $retries, bool $force): TransportResult
    {
        if (! $this->options->isUsable()) {
            return TransportResult::skipped('Monitoring is disabled or not configured.');
        }

        if (! $force && ! $this->breaker->allowsRequest()) {
            return TransportResult::circuitOpen($this->breaker->retryIn());
        }

        $url = $this->options->apiBase().'/'.ltrim($endpoint, '/');
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $attempts = 1 + ($retries ?? $this->options->retries());
        $last = TransportResult::failed('No attempt made.');

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->attempt($method, $url, $body);

            if ($last->successful() || ! $last->retryable || $attempt === $attempts) {
                break;
            }

            ($this->sleeper)($this->backoff($attempt, $last->retryAfter));
        }

        if ($last->retryable) {
            $this->breaker->recordFailure($last->retryAfter);
        } elseif ($last->status !== null) {
            // The server answered (2xx or a permanent 4xx): it is reachable.
            $this->breaker->recordSuccess();
        }

        return $last;
    }

    private function attempt(string $method, string $url, string $body): TransportResult
    {
        try {
            $headers = $this->headers($method, $url, $body);
            $request = $this->http->withHeaders($headers)
                ->timeout($this->options->timeout())
                ->connectTimeout($this->options->connectTimeout())
                ->acceptJson();

            if ($method === 'GET') {
                $response = $request->get($url);
            } else {
                $sent = $body;
                if ($this->options->compress() && strlen($body) > 1024) {
                    $sent = (string) gzencode($body, 6);
                    $request = $request->withHeaders(['Content-Encoding' => 'gzip']);
                }
                $response = $request->withBody($sent, 'application/json')->post($url);
            }

            $status = $response->status();
            $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

            return new TransportResult(
                status: $status,
                body: is_array($response->json()) ? $response->json() : null,
                error: $response->successful() ? null : (string) ($response->json('message') ?? 'HTTP '.$status),
                retryable: $status === 429 || $status >= 500,
                retryAfter: $retryAfter,
            );
        } catch (ConnectionException $e) {
            return TransportResult::failed('Connection failed: '.$e->getMessage(), retryable: true);
        } catch (Throwable $e) {
            return TransportResult::failed('Transport error: '.$e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function headers(string $method, string $url, string $body): array
    {
        $headers = [
            'X-Trk-Key' => $this->options->projectKey(),
            'User-Agent' => 'trk-laravel-monitor/'.Monitor::VERSION,
            'X-Trk-Sdk' => 'trk/laravel-monitor/'.Monitor::VERSION,
        ];

        if ($this->options->signRequests()) {
            $timestamp = (string) time();
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
            $headers['X-Trk-Timestamp'] = $timestamp;
            $headers['X-Trk-Signature'] = self::sign($this->options->projectSecret(), $timestamp, $method, $path, $body);
        } else {
            $headers['Authorization'] = 'Bearer '.$this->options->projectSecret();
        }

        return $headers;
    }

    /** HMAC over the canonical request, keyed with a one-way derivation of the secret. */
    public static function sign(string $secret, string $timestamp, string $method, string $path, string $body): string
    {
        $signingKey = hash_hmac('sha256', self::SIGNING_LABEL, $secret);

        return hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$path."\n".hash('sha256', $body), $signingKey);
    }

    /** Exponential backoff with full jitter, capped at 5 seconds (or the server's Retry-After). */
    private function backoff(int $attempt, ?int $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min(5000, $retryAfter * 1000);
        }

        $cap = min(5000, 100 * (2 ** $attempt));

        return random_int((int) ($cap / 2), $cap);
    }
}
