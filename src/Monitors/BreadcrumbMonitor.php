<?php

declare(strict_types=1);

namespace Trk\Monitor\Monitors;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Throwable;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Scope;

/** Automatic breadcrumbs: log messages, outgoing HTTP calls and dispatched jobs. */
final class BreadcrumbMonitor
{
    public function __construct(
        private readonly Options $options,
        private readonly Scope $scope,
    ) {}

    public function log(MessageLogged $event): void
    {
        $this->record('logs', fn () => [
            'category' => 'log',
            'message' => Str::limit($event->message, 500),
            'level' => $event->level,
        ]);
    }

    public function httpResponse(ResponseReceived $event): void
    {
        if ($this->isMonitorUrl($event->request->url())) {
            return;
        }

        $status = $event->response->status();

        $this->record('http_client', fn () => [
            'category' => 'http',
            'message' => $event->request->method().' '.$this->stripQuery($event->request->url()),
            'level' => $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'),
            'data' => ['status_code' => $status],
        ]);
    }

    public function httpFailed(ConnectionFailed $event): void
    {
        if ($this->isMonitorUrl($event->request->url())) {
            return;
        }

        $this->record('http_client', fn () => [
            'category' => 'http',
            'message' => $event->request->method().' '.$this->stripQuery($event->request->url()).' (connection failed)',
            'level' => 'error',
        ]);
    }

    public function jobQueued(JobQueued $event): void
    {
        $name = is_object($event->job) ? $event->job::class : (string) $event->job;

        if ($name === SendPayload::class) {
            return;
        }

        $this->record('queue', fn () => [
            'category' => 'queue',
            'message' => 'Dispatched '.$name,
            'level' => 'info',
            'data' => ['connection' => $event->connectionName, 'queue' => $event->queue ?? null],
        ]);
    }

    /** @param \Closure(): array<string, mixed> $build */
    private function record(string $kind, \Closure $build): void
    {
        if (Monitor::isSuppressed() || ! $this->options->captures('breadcrumbs') || ! $this->options->get("breadcrumbs.{$kind}", true)) {
            return;
        }

        try {
            $this->scope->addBreadcrumb($build());
        } catch (Throwable) {
        }
    }

    private function isMonitorUrl(string $url): bool
    {
        $server = (string) $this->options->get('server.url', '');

        // Only the SDK's own ingestion API is skipped; other paths on the same host are instrumented.
        return $server !== '' && str_starts_with($url, $this->options->apiBase().'/');
    }

    private function stripQuery(string $url): string
    {
        return (string) Str::before($url, '?');
    }
}
