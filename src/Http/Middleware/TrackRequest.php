<?php

declare(strict_types=1);

namespace Trk\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Performance\Transaction;
use Trk\Monitor\Support\TraceContext;

/**
 * Measures incoming HTTP requests as `http.server` transactions.
 *
 * The response is never inspected beyond its status code and never modified. Reporting
 * happens in terminate(), after the response has been sent.
 */
final class TrackRequest
{
    private const ATTRIBUTE = 'trk-monitor.transaction';

    private const STATIC_EXTENSIONS = ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot'];

    public function __construct(
        private readonly Options $options,
        private readonly Tracer $tracer,
        private readonly TraceContext $trace,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Each request starts its own trace (or continues the caller's); nothing leaks from a
            // previous request handled by the same worker (Octane, RoadRunner…).
            $this->tracer->reset();
            $this->trace->reset();
            $this->trace->continueFrom($request->headers->get('traceparent'), $request->headers->get('tracestate'));

            if (! $this->excludedPath($request)) {
                $path = '/'.ltrim($request->path(), '/');
                $transaction = $this->tracer->start($request->getMethod().' '.$path, 'http.server', [
                    $request->getMethod().' '.$path,
                    $path,
                ]);
                $request->attributes->set(self::ATTRIBUTE, $transaction);
            }
        } catch (Throwable) {
            // Never block the request.
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $transaction = $request->attributes->get(self::ATTRIBUTE);

            if (! $transaction instanceof Transaction) {
                return;
            }

            $route = $request->route();
            $uri = is_object($route) ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');
            $routeName = is_object($route) ? $route->getName() : null;

            if ($this->excludedRoute($routeName, $uri)) {
                $this->tracer->discard($transaction);

                return;
            }

            $status = $response->getStatusCode();
            $transaction->name = $request->getMethod().' '.$uri;
            $transaction->status = $status >= 500 ? 'error' : 'ok';
            $transaction->http = array_filter([
                'method' => $request->getMethod(),
                'route' => $uri,
                'route_name' => $routeName,
                'url' => $request->url(),
                'status_code' => $status,
            ], fn ($v) => $v !== null);
            $transaction->user = $this->user($request);

            $this->tracer->finish($transaction, (float) $this->options->get('performance.slow_request_threshold_ms', 1000));
        } catch (Throwable) {
            // Never affect termination.
        }
    }

    private function excludedPath(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, self::STATIC_EXTENSIONS, true)) {
            return true;
        }

        foreach ($this->options->excluded('paths') as $pattern) {
            if (Str::is(ltrim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    private function excludedRoute(?string $name, string $uri): bool
    {
        foreach ($this->options->excluded('routes') as $pattern) {
            if (($name !== null && Str::is($pattern, $name)) || Str::is('/'.ltrim($pattern, '/'), $uri)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function user(Request $request): ?array
    {
        if (! $this->options->privacy('send_user', true)) {
            return null;
        }

        try {
            $user = $request->user();

            return $user === null ? null : ['id' => $user->getAuthIdentifier()];
        } catch (Throwable) {
            return null;
        }
    }
}
