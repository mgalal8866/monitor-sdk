<?php

declare(strict_types=1);

namespace Trk\Monitor;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\TransportResult;
use WeakMap;

/**
 * The SDK entry point (also available through the Monitor facade).
 *
 * Guarantees:
 *  - Never throws into application code; SDK failures are swallowed.
 *  - Never reports its own failures (recursion guard).
 *  - Reports each exception object at most once.
 */
class Monitor
{
    public const VERSION = '1.0.0';

    private static int $suppressed = 0;

    /** @var WeakMap<Throwable, string> */
    private WeakMap $captured;

    /** @var WeakMap<Throwable, true> Exceptions deliberately not reported (e.g. job attempts that will be retried). */
    private WeakMap $ignored;

    private ?string $lastEventId = null;

    public function __construct(
        private readonly Options $options,
        private readonly Scope $scope,
        private readonly EventFactory $events,
        private readonly Delivery $delivery,
        private readonly HttpTransport $transport,
    ) {
        $this->captured = new WeakMap;
        $this->ignored = new WeakMap;
    }

    /** @param array<string, mixed> $overrides */
    public function captureException(Throwable $e, array $overrides = []): ?string
    {
        if (isset($this->captured[$e])) {
            return $this->captured[$e];
        }

        if (isset($this->ignored[$e])) {
            return null;
        }

        return $this->guard(function () use ($e, $overrides) {
            if (! $this->shouldCaptureException($e)) {
                return null;
            }

            $payload = $this->events->fromException($e, $this->scope, $overrides);
            $this->captured[$e] = $payload['event_id'];

            // Error-triggered sampling: keep the surrounding transaction so the error has its trace.
            app(Tracer::class)->errorCaptured();

            return $this->dispatchEvent($payload);
        });
    }

    /** Prevents an exception from being reported until it is released. */
    public function ignoreException(Throwable $e): void
    {
        $this->ignored[$e] = true;
    }

    public function releaseException(Throwable $e): void
    {
        unset($this->ignored[$e]);
    }

    /** @param array<string, mixed> $overrides */
    public function captureMessage(string $message, string $level = 'info', array $overrides = []): ?string
    {
        return $this->guard(function () use ($message, $level, $overrides) {
            if (! $this->sampled('errors')) {
                return null;
            }

            return $this->dispatchEvent($this->events->fromMessage($message, $level, $this->scope, $overrides));
        });
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->scope->setUser($user);
    }

    public function setTag(string $key, string|int|float|bool|null $value): void
    {
        $this->scope->setTag($key, $value);
    }

    /** @param array<string, string|int|float|bool|null> $tags */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->scope->setTag((string) $key, $value);
        }
    }

    /** @param array<string, mixed> $context */
    public function setContext(string $name, array $context): void
    {
        $this->scope->setContext($name, $context);
    }

    /** @param array<string, mixed> $breadcrumb */
    public function addBreadcrumb(array $breadcrumb): void
    {
        $this->scope->addBreadcrumb($breadcrumb);
    }

    /** @param list<string>|null $fingerprint */
    public function setFingerprint(?array $fingerprint): void
    {
        $this->scope->setFingerprint($fingerprint);
    }

    /** @param Closure(Scope): void $callback */
    public function configureScope(Closure $callback): void
    {
        $this->guard(fn () => $callback($this->scope));
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Registers a release synchronously (deployment scripts / `trk-monitor:release`).
     *
     * @param  array<string, mixed>  $release
     */
    public function sendRelease(array $release): TransportResult
    {
        return $this->transport->post('releases', $this->releasePayload($release), retries: 1, force: true);
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function releasePayload(array $release): array
    {
        return array_filter($release + [
            'environment' => $this->options->environment(),
            'sdk_version' => self::VERSION,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Sends buffered events now (e.g. at the end of a long-running command). */
    public function flush(): void
    {
        $this->guard(fn () => $this->delivery->flush());
    }

    /** Runs a callback without capturing anything (used around SDK I/O). */
    public static function withoutCapturing(Closure $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed > 0;
    }

    private function shouldCaptureException(Throwable $e): bool
    {
        if (! $this->options->captures('exceptions')) {
            return false;
        }

        foreach ($this->options->excluded('exceptions') as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        // Errors have their own sampling rate, independent from performance sampling.
        return $this->sampled('errors');
    }

    private function sampled(string $type): bool
    {
        $rate = $this->options->sampleRate($type);

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }

    /** @param array<string, mixed> $payload */
    private function dispatchEvent(array $payload): string
    {
        $this->delivery->send('events', $payload);

        return $this->lastEventId = (string) $payload['event_id'];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    private function guard(Closure $callback): mixed
    {
        if (self::isSuppressed() || ! $this->options->isUsable()) {
            return null;
        }

        self::$suppressed++;

        try {
            return $callback();
        } catch (Throwable $e) {
            try {
                Log::channel(config('logging.default'))->debug('TrkMonitor SDK error: '.$e->getMessage());
            } catch (Throwable) {
            }

            return null;
        } finally {
            self::$suppressed--;
        }
    }
}
