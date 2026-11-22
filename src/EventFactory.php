<?php

declare(strict_types=1);

namespace Trk\Monitor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Support\StackTraceBuilder;
use Trk\Monitor\Support\TraceContext;

/** Builds sanitized, size-limited event payloads (ingestion API v1 contract). */
final class EventFactory
{
    public function __construct(
        private readonly Options $options,
        private readonly Sanitizer $sanitizer,
        private readonly StackTraceBuilder $stackTraces,
        private readonly TraceContext $trace,
        private readonly Application $app,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function fromException(Throwable $e, Scope $scope, array $overrides = []): array
    {
        return $this->finalize(array_replace([
            'level' => 'error',
            'exception' => ['values' => $this->stackTraces->exceptions($e)],
        ], $overrides), $scope);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function fromMessage(string $message, string $level, Scope $scope, array $overrides = []): array
    {
        return $this->finalize(array_replace([
            'level' => $level,
            'message' => mb_substr($message, 0, 8192),
        ], $overrides), $scope);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function finalize(array $event, Scope $scope): array
    {
        $event = array_replace([
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => microtime(true),
            'platform' => 'php',
            'environment' => $this->options->environment(),
            'release' => $this->options->release(),
            'sdk' => ['name' => 'trk/laravel-monitor', 'version' => Monitor::VERSION],
            'contexts' => $this->runtimeContexts() + $scope->contexts(),
            'tags' => $scope->tags(),
            'breadcrumbs' => $this->options->captures('breadcrumbs') ? $scope->breadcrumbs() : [],
            'trace' => $this->trace->toArray(),
        ], $event);

        if ($fingerprint = $scope->fingerprint()) {
            $event['fingerprint'] ??= $fingerprint;
        }

        if ($transaction = $scope->transaction()) {
            $event['transaction'] ??= $transaction;
        }

        if ($request = $this->requestContext()) {
            $event['request'] = $request;
            $event['transaction'] ??= $request['route'] ?? null;
        }

        if ($user = $this->userContext($scope)) {
            $event['user'] = $user;
        }

        $event = $this->sanitizer->sanitize(array_filter($event, fn ($v) => $v !== null && $v !== []));

        return $this->shrink($event);
    }

    /** @return array<string, array<string, mixed>> */
    private function runtimeContexts(): array
    {
        return [
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => $this->app->version(),
                'sapi' => PHP_SAPI,
            ],
            'os' => ['name' => PHP_OS_FAMILY],
        ];
    }

    /** @return array<string, mixed>|null */
    private function requestContext(): ?array
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        try {
            /** @var Request $request */
            $request = $this->app->make('request');
            $route = $request->route();

            // Only routed HTTP requests carry request context (console/queue requests are synthetic).
            if ($route === null) {
                return null;
            }

            $context = [
                'url' => $request->url(),
                'method' => $request->getMethod(),
                'route' => $route->getName() ?? $route->uri(),
                'query' => $request->query(),
            ];

            if ($this->options->privacy('send_headers', true)) {
                $headers = $request->headers->all();
                if (! $this->options->privacy('send_cookies', false)) {
                    unset($headers['cookie']);
                }
                $context['headers'] = $this->sanitizer->sanitizeHeaders($headers);
            }

            if ($this->options->privacy('send_request_body', false)) {
                $context['data'] = $request->except(array_keys($request->allFiles()));
            }

            return array_filter($context, fn ($v) => $v !== []);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function userContext(Scope $scope): ?array
    {
        $user = $scope->user() ?? [];

        if ($user === [] && $this->options->privacy('send_user', true)) {
            try {
                $auth = $this->app->bound('auth') ? $this->app->make('auth') : null;
                $authUser = $auth?->hasUser() ? $auth->user() : null;

                if ($authUser !== null) {
                    $user = ['id' => $authUser->getAuthIdentifier()];

                    if ($this->options->privacy('send_user_email', false) && isset($authUser->email)) {
                        $user['email'] = $authUser->email;
                    }
                }
            } catch (Throwable) {
                // Auth may not be configured.
            }
        }

        if (! $this->options->privacy('send_user_email', false)) {
            unset($user['email']);
        }

        if ($this->options->privacy('send_ip', false) && $this->app->bound('request') && $this->app->make('request')->route() !== null) {
            try {
                $user['ip_address'] = $this->app->make('request')->ip();
            } catch (Throwable) {
            }
        } else {
            unset($user['ip_address']);
        }

        return $user === [] ? null : $user;
    }

    /**
     * Keeps the encoded payload under max_payload_size by dropping the least valuable
     * data first: source context, breadcrumbs, vendor frames, request data.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function shrink(array $event): array
    {
        $max = $this->options->maxPayloadSize();
        $size = fn (array $e) => strlen((string) json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        $steps = [
            function (array $e) {
                foreach ($e['exception']['values'] ?? [] as $i => $ex) {
                    foreach ($ex['stacktrace']['frames'] ?? [] as $j => $f) {
                        unset($e['exception']['values'][$i]['stacktrace']['frames'][$j]['pre_context'], $e['exception']['values'][$i]['stacktrace']['frames'][$j]['post_context']);
                    }
                }

                return $e;
            },
            fn (array $e) => array_merge($e, ['breadcrumbs' => array_slice($e['breadcrumbs'] ?? [], -10)]),
            function (array $e) {
                foreach ($e['exception']['values'] ?? [] as $i => $ex) {
                    $frames = $ex['stacktrace']['frames'] ?? [];
                    $e['exception']['values'][$i]['stacktrace']['frames'] = array_values(array_slice(
                        array_filter($frames, fn ($f) => ($f['in_app'] ?? false) || $f === ($frames[0] ?? null)),
                        0,
                        50,
                    ));
                }

                return $e;
            },
            function (array $e) {
                unset($e['request']['data'], $e['request']['headers'], $e['breadcrumbs']);

                return $e;
            },
            function (array $e) {
                $e['exception']['values'] = array_slice($e['exception']['values'] ?? [], 0, 1);
                $e['message'] = isset($e['message']) ? mb_substr((string) $e['message'], 0, 1024) : null;
                $e['contexts'] = ['runtime' => $e['contexts']['runtime'] ?? []];

                return array_filter($e, fn ($v) => $v !== null && $v !== []);
            },
        ];

        foreach ($steps as $step) {
            if ($size($event) <= $max) {
                break;
            }
            $event = $step($event);
        }

        return $event;
    }
}
