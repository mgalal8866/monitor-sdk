<?php

declare(strict_types=1);

namespace Trk\Monitor\Monitors;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Span;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Support\TraceContext;
use WeakMap;

/**
 * Outgoing HTTP calls made with Laravel's HTTP client:
 *  - an `http.client` span per request (method, host, path — never the query string);
 *  - W3C `traceparent` / `tracestate` headers so the receiving service continues the trace.
 *
 * Headers the application already set are never overwritten, and nothing is added to the
 * SDK's own requests to the TrkMonitor server.
 */
final class HttpClientMonitor
{
    /** @var WeakMap<RequestInterface, Span> */
    private WeakMap $pending;

    /** @var array<string, Span> Fallback lookup by propagated span id. */
    private array $bySpanId = [];

    public function __construct(
        private readonly Options $options,
        private readonly Tracer $tracer,
        private readonly TraceContext $trace,
    ) {
        $this->pending = new WeakMap;
    }

    /** Guzzle request mapper registered as global HTTP client middleware. */
    public function onRequest(RequestInterface $request): RequestInterface
    {
        if (Monitor::isSuppressed() || ! $this->options->isUsable()) {
            return $request;
        }

        try {
            $uri = $request->getUri();

            if ($this->isMonitorRequest((string) $uri)) {
                return $request;
            }

            $span = null;
            $transaction = $this->tracer->current();

            if ($transaction !== null && filter_var($this->options->tracing('http_client_spans', true), FILTER_VALIDATE_BOOL)) {
                $span = $transaction->startSpan('http.client', $request->getMethod().' '.$this->safeUrl($request), array_filter([
                    'method' => $request->getMethod(),
                    'host' => $uri->getHost(),
                    'port' => $uri->getPort(),
                ], fn ($v) => $v !== null && $v !== ''));
            }

            if ($this->options->propagatesTo($uri->getHost()) && ! $request->hasHeader('traceparent')) {
                $request = $request->withHeader('traceparent', $this->trace->traceparent(
                    $span->spanId ?? $this->tracer->activeSpanId(),
                    $this->tracer->sampledFlag(),
                ));

                if (($state = $this->trace->traceState()) !== null && ! $request->hasHeader('tracestate')) {
                    $request = $request->withHeader('tracestate', $state);
                }
            }

            if ($span !== null) {
                $this->pending[$request] = $span;
                $this->bySpanId[$span->spanId] = $span;
            }
        } catch (Throwable) {
            // Never affect outgoing requests.
        }

        return $request;
    }

    public function responseReceived(ResponseReceived $event): void
    {
        $this->complete($event->request, function (Span $span) use ($event) {
            $status = $event->response->status();
            $span->data['status_code'] = $status;

            return $status >= 500 ? 'error' : ($status >= 400 ? 'client_error' : 'ok');
        });
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $this->complete($event->request, fn () => 'unavailable');
    }

    /** @param \Closure(Span): string $status */
    private function complete(Request $request, \Closure $status): void
    {
        try {
            $span = $this->find($request);

            if ($span === null) {
                return;
            }

            unset($this->bySpanId[$span->spanId]);
            $this->tracer->current()?->finishSpan($span, $status($span));
            $span->finish();
        } catch (Throwable) {
        }
    }

    private function find(Request $request): ?Span
    {
        try {
            $psr = $request->toPsrRequest();

            if (isset($this->pending[$psr])) {
                $span = $this->pending[$psr];
                unset($this->pending[$psr]);

                return $span;
            }
        } catch (Throwable) {
        }

        // Another middleware replaced the request object: fall back to the propagated span id.
        $parsed = TraceContext::parse($request->header('traceparent')[0] ?? null);

        return $parsed !== null ? ($this->bySpanId[$parsed[1]] ?? null) : null;
    }

    private function isMonitorRequest(string $url): bool
    {
        $server = (string) $this->options->get('server.url', '');

        // Only the SDK's own ingestion API is skipped; other paths on the same host are instrumented.
        return $server !== '' && str_starts_with($url, $this->options->apiBase().'/');
    }

    /** scheme://host[:port]/path — query strings and credentials are never recorded. */
    private function safeUrl(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        return $uri->getScheme().'://'.$uri->getHost().($port !== null ? ':'.$port : '').($uri->getPath() === '' ? '/' : $uri->getPath());
    }
}
