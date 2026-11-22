<?php

declare(strict_types=1);

namespace Trk\Monitor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Trk\Monitor\Commands\FlushCommand;
use Trk\Monitor\Commands\InstallCommand;
use Trk\Monitor\Commands\ReleaseCommand;
use Trk\Monitor\Commands\StatusCommand;
use Trk\Monitor\Commands\TestCommand;
use Trk\Monitor\Http\Middleware\TrackRequest;
use Trk\Monitor\Monitors\BreadcrumbMonitor;
use Trk\Monitor\Monitors\HttpClientMonitor;
use Trk\Monitor\Monitors\JobMonitor;
use Trk\Monitor\Monitors\QueryMonitor;
use Trk\Monitor\Monitors\QueueMonitor;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Support\StackTraceBuilder;
use Trk\Monitor\Support\TraceContext;
use Trk\Monitor\Transport\CircuitBreaker;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

class TrkMonitorServiceProvider extends ServiceProvider
{
    /** @var \WeakReference<object>|null The request whose trace context is current. */
    private ?\WeakReference $tracedRequest = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/trk-monitor.php', 'trk-monitor');

        $this->app->singleton(Options::class, fn (Application $app) => Options::fromArray((array) $app['config']->get('trk-monitor', [])));

        $this->app->singleton(Scope::class, fn (Application $app) => new Scope(max(1, (int) $app['config']->get('trk-monitor.breadcrumbs.max', 50))));

        $this->app->singleton(TraceContext::class);

        $this->app->singleton(Sanitizer::class, fn (Application $app) => new Sanitizer(
            (array) $app['config']->get('trk-monitor.privacy.sensitive_fields', []),
            (array) $app['config']->get('trk-monitor.privacy.allowed_fields', []),
        ));

        $this->app->singleton(StackTraceBuilder::class, fn (Application $app) => new StackTraceBuilder(
            $app->basePath(),
            (bool) $app['config']->get('trk-monitor.privacy.send_source_context', true),
        ));

        // A dedicated HTTP factory so application-level Http::fake()/middleware does not affect the SDK
        // unless tests explicitly swap this binding.
        $this->app->singleton(CircuitBreaker::class, function (Application $app) {
            $options = $app->make(Options::class);

            return new CircuitBreaker(
                $app->storagePath('framework/trk-monitor/circuit.json'),
                $options->circuitBreakerEnabled(),
                $options->circuitBreakerCooldown(),
                $options->circuitBreakerMaxCooldown(),
            );
        });

        $this->app->singleton(HttpTransport::class, fn (Application $app) => new HttpTransport(
            $app->make(Options::class),
            $app->make(HttpFactory::class),
            breaker: $app->make(CircuitBreaker::class),
        ));

        $this->app->singleton(Spool::class, fn (Application $app) => new Spool(
            $app->storagePath('framework/trk-monitor/spool'),
            $app->make(Options::class)->spoolMaxFiles(),
        ));

        $this->app->singleton(Delivery::class);
        $this->app->singleton(EventFactory::class);
        $this->app->singleton(Monitor::class);
        $this->app->singleton(Tracer::class);
        $this->app->singleton(JobMonitor::class);
        $this->app->singleton(QueueMonitor::class);
        $this->app->singleton(HttpClientMonitor::class);
        $this->app->singleton(TrackRequest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/trk-monitor.php' => config_path('trk-monitor.php'),
            ], 'trk-monitor-config');

            $this->commands([
                InstallCommand::class,
                TestCommand::class,
                StatusCommand::class,
                ReleaseCommand::class,
                FlushCommand::class,
            ]);
        }

        if (! $this->app->make(Options::class)->enabled()) {
            return;
        }

        $this->registerSpoolFlushing();
        $this->registerExceptionReporting();
        $this->registerScopeLifecycle();
        $this->registerInstrumentation();
    }

    /**
     * Re-sends spooled payloads every minute through the application's scheduler. The command
     * respects the circuit breaker and holds its own file lock, so it needs neither the cache
     * nor Redis and costs nothing while the server is down.
     */
    private function registerSpoolFlushing(): void
    {
        if (! $this->app->make(Options::class)->spoolAutoFlush()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            try {
                $schedule->command('trk-monitor:flush', ['--scheduled' => true])
                    ->everyMinute()
                    ->name('trk-monitor:flush');
            } catch (Throwable) {
            }
        });
    }

    /** Database, HTTP request, queue and breadcrumb instrumentation (each can be disabled). */
    private function registerInstrumentation(): void
    {
        $options = $this->app->make(Options::class);

        if ($options->captures('performance') || $options->captures('breadcrumbs')) {
            Event::listen(QueryExecuted::class, [QueryMonitor::class, 'handle']);
        }

        // JobMonitor keeps per-job state, so the listener must reuse one instance.
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => $this->app->make(JobMonitor::class)->processing($e));
        Event::listen(JobProcessed::class, fn (JobProcessed $e) => $this->app->make(JobMonitor::class)->processed($e));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $this->app->make(JobMonitor::class)->exceptionOccurred($e));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $this->app->make(JobMonitor::class)->failed($e));

        $this->registerTracing();

        if ($options->captures('breadcrumbs')) {
            Event::listen(MessageLogged::class, [BreadcrumbMonitor::class, 'log']);
            Event::listen(ResponseReceived::class, [BreadcrumbMonitor::class, 'httpResponse']);
            Event::listen(ConnectionFailed::class, [BreadcrumbMonitor::class, 'httpFailed']);
            Event::listen(JobQueued::class, [BreadcrumbMonitor::class, 'jobQueued']);
        }

        if ($options->captures('performance') && $options->captures('transactions')) {
            $this->app->booted(function () {
                try {
                    $this->prependRequestTracking($this->app->make(HttpKernel::class));
                } catch (Throwable) {
                    // No HTTP kernel (e.g. console-only application).
                }
            });
        }
    }

    /**
     * Outgoing HTTP and queue instrumentation: `http.client` / `queue.publish` spans and W3C trace
     * context propagation. Both hooks only read/add trace identifiers and never throw.
     */
    private function registerTracing(): void
    {
        // HTTP client: a global request middleware (adds headers, opens the span) + response events.
        // callAfterResolving also runs immediately when the factory has already been resolved.
        $this->callAfterResolving(HttpFactory::class, function (HttpFactory $factory) {
            try {
                $factory->globalRequestMiddleware(fn (RequestInterface $request) => $this->app->make(HttpClientMonitor::class)->onRequest($request));
            } catch (Throwable) {
            }
        });
        Event::listen(ResponseReceived::class, fn (ResponseReceived $e) => $this->app->make(HttpClientMonitor::class)->responseReceived($e));
        Event::listen(ConnectionFailed::class, fn (ConnectionFailed $e) => $this->app->make(HttpClientMonitor::class)->connectionFailed($e));

        // Queue: trace context travels inside the job payload. The callback is idempotent (it skips
        // payloads that already carry trace context), so a repeated registration is harmless.
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            try {
                return $this->app->make(QueueMonitor::class)->createPayload(
                    is_string($connection) ? $connection : null,
                    is_string($queue) ? $queue : null,
                    is_array($payload) ? $payload : [],
                );
            } catch (Throwable) {
                return [];
            }
        });
        Event::listen(JobQueued::class, fn (JobQueued $e) => $this->app->make(QueueMonitor::class)->queued($e));
    }

    /** Runs TrackRequest first so it measures the whole middleware pipeline. */
    private function prependRequestTracking(object $kernel): void
    {
        if ($kernel instanceof Kernel) {
            $kernel->prependMiddleware(TrackRequest::class);
        }
    }

    /**
     * Hooks into Laravel's exception reporting. The callback does not stop propagation,
     * so the application's own reporting (logs, other services) continues unchanged and
     * the response is never modified.
     */
    private function registerExceptionReporting(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e): void {
                if (Monitor::isSuppressed()) {
                    return;
                }

                try {
                    $this->app->make(Monitor::class)->captureException($e);
                } catch (Throwable) {
                    // Monitoring must never break exception reporting.
                }
            });
        });
    }

    /**
     * Continues incoming W3C traces. Each new request starts from a clean trace so nothing leaks
     * between requests; queue jobs are isolated by JobMonitor (which keeps the caller's scope for
     * jobs executed synchronously inside a request).
     */
    private function registerScopeLifecycle(): void
    {
        $continue = function ($request): void {
            // The same request is re-bound several times while it is handled: only a new request
            // starts a new trace.
            if ($this->tracedRequest?->get() === $request) {
                return;
            }

            $this->tracedRequest = \WeakReference::create($request);

            try {
                $trace = $this->app->make(TraceContext::class);
                $trace->reset();
                $trace->continueFrom($request->headers->get('traceparent'), $request->headers->get('tracestate'));
            } catch (Throwable) {
            }
        };

        $this->app->rebinding('request', fn ($app, $request) => $continue($request));

        if (! $this->app->runningInConsole() && $this->app->bound('request')) {
            $continue($this->app['request']);
        }
    }
}
