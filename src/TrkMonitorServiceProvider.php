<?php

declare(strict_types=1);

namespace Trk\Monitor;

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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Trk\Monitor\Commands\FlushCommand;
use Trk\Monitor\Commands\InstallCommand;
use Trk\Monitor\Commands\ReleaseCommand;
use Trk\Monitor\Commands\StatusCommand;
use Trk\Monitor\Commands\TestCommand;
use Trk\Monitor\Http\Middleware\TrackRequest;
use Trk\Monitor\Monitors\BreadcrumbMonitor;
use Trk\Monitor\Monitors\JobMonitor;
use Trk\Monitor\Monitors\QueryMonitor;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Support\StackTraceBuilder;
use Trk\Monitor\Support\TraceContext;
use Trk\Monitor\Transport\Delivery;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

class TrkMonitorServiceProvider extends ServiceProvider
{
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
        $this->app->singleton(HttpTransport::class, fn (Application $app) => new HttpTransport(
            $app->make(Options::class),
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(Spool::class, fn (Application $app) => new Spool($app->storagePath('framework/trk-monitor/spool')));

        $this->app->singleton(Delivery::class);
        $this->app->singleton(EventFactory::class);
        $this->app->singleton(Monitor::class);
        $this->app->singleton(Tracer::class);
        $this->app->singleton(JobMonitor::class);
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

        $this->registerExceptionReporting();
        $this->registerScopeLifecycle();
        $this->registerInstrumentation();
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

    /** Isolates context between queue jobs and continues incoming W3C traces. */
    private function registerScopeLifecycle(): void
    {
        Event::listen(JobProcessing::class, function () {
            $this->app->make(Scope::class)->clear();
            $this->app->make(TraceContext::class)->reset();
        });

        $this->app->rebinding('request', function ($app, $request) {
            try {
                $app->make(TraceContext::class)->continueFrom($request->headers->get('traceparent'));
            } catch (Throwable) {
            }
        });

        if (! $this->app->runningInConsole() && $this->app->bound('request')) {
            try {
                $this->app->make(TraceContext::class)->continueFrom($this->app['request']->headers->get('traceparent'));
            } catch (Throwable) {
            }
        }
    }
}
