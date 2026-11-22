<?php

declare(strict_types=1);

namespace Trk\Monitor\Monitors;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;
use Throwable;
use Trk\Monitor\Jobs\SendPayload;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Performance\Transaction;
use Trk\Monitor\Scope;
use Trk\Monitor\Support\TraceContext;

/**
 * Queue instrumentation.
 *
 * Every job runs in its own scope and `queue.job` transaction. Failures are reported once,
 * when the job finally fails (intermediate attempts become breadcrumbs unless
 * `jobs.report_retries` is enabled). Serialized job payloads are never transmitted; only
 * metadata (and allow-listed payload keys) is.
 */
final class JobMonitor
{
    private ?Transaction $transaction = null;

    private ?string $jobId = null;

    public function __construct(
        private readonly Options $options,
        private readonly Monitor $monitor,
        private readonly Scope $scope,
        private readonly TraceContext $trace,
        private readonly Tracer $tracer,
    ) {}

    public function processing(JobProcessing $event): void
    {
        $this->safely(function () use ($event) {
            $this->scope->clear();
            $this->trace->reset();
            $this->transaction = null;

            if ($this->ignored($event->job)) {
                return;
            }

            $name = $event->job->resolveName();
            $context = $this->context($event->job, $event->connectionName);

            $this->jobId = $this->identity($event->job);
            $this->scope->setTransaction($name);
            $this->scope->setTag('job', $name);
            $this->scope->setTag('queue', (string) $event->job->getQueue());
            $this->scope->setContext('job', $context);

            if ($transaction = $this->tracer->start($name, 'queue.job')) {
                $transaction->contexts['job'] = $context;
                $transaction->tags = ['job' => $name, 'queue' => (string) $event->job->getQueue()];
                $this->transaction = $transaction;
            }
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->safely(function () use ($event) {
            if ($this->ignored($event->job) || ! $this->isCurrent($event->job)) {
                return;
            }

            $this->finish('ok');
        });
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->safely(function () use ($event) {
            if ($this->ignored($event->job)) {
                return;
            }

            $this->scope->addBreadcrumb([
                'category' => 'queue',
                'message' => 'Attempt '.$event->job->attempts().' failed: '.Str::limit($event->exception->getMessage(), 200),
                'level' => 'error',
                'data' => ['exception' => $event->exception::class],
            ]);

            // Report only the final failure; JobFailed releases and captures it.
            if (! $this->options->get('jobs.report_retries', false)) {
                $this->monitor->ignoreException($event->exception);
            }

            if ($this->transaction !== null && $this->isCurrent($event->job)) {
                $this->transaction->status = 'retrying';
            }
        });
    }

    public function failed(JobFailed $event): void
    {
        $this->safely(function () use ($event) {
            if ($this->ignored($event->job)) {
                return;
            }

            if (! $this->isCurrent($event->job)) {
                // Failed outside of a processing cycle (e.g. max attempts exceeded on pop).
                $this->scope->clear();
                $this->scope->setTag('job', $event->job->resolveName());
                $this->scope->setContext('job', $this->context($event->job, $event->connectionName));
            }

            $this->monitor->releaseException($event->exception);

            if ($this->options->captures('failed_jobs')) {
                $this->monitor->captureException($event->exception, ['event_type' => 'job']);
            }

            if ($this->isCurrent($event->job)) {
                $this->finish('failed');
            }
        });
    }

    private function finish(string $status): void
    {
        $transaction = $this->transaction;
        $this->transaction = null;
        $this->jobId = null;

        if ($transaction === null) {
            return;
        }

        if ($transaction->status !== 'retrying' || $status === 'failed') {
            $transaction->status = $status;
        }

        $this->tracer->finish($transaction, (float) $this->options->get('performance.long_running_job_threshold_ms', 60000));
    }

    /** @return array<string, mixed> */
    private function context(Job $job, string $connection): array
    {
        $payload = $this->payload($job);

        $context = array_filter([
            'name' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $connection,
            'attempts' => $job->attempts(),
            'max_tries' => $job->maxTries(),
            'timeout' => $job->timeout(),
            'uuid' => $payload['uuid'] ?? null,
        ], fn ($v) => $v !== null);

        foreach ((array) $this->options->get('jobs.metadata_allowlist', []) as $key) {
            if (is_string($key) && array_key_exists($key, $payload) && $key !== 'data') {
                $context['meta'][$key] = is_scalar($payload[$key]) ? $payload[$key] : '['.get_debug_type($payload[$key]).']';
            }
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private function payload(Job $job): array
    {
        try {
            return (array) $job->payload();
        } catch (Throwable) {
            return [];
        }
    }

    private function identity(Job $job): string
    {
        return (string) ($job->uuid() ?? $job->getJobId() ?? spl_object_id($job));
    }

    private function isCurrent(Job $job): bool
    {
        return $this->jobId !== null && $this->jobId === $this->identity($job);
    }

    private function ignored(Job $job): bool
    {
        $name = $job->resolveName();

        if ($name === SendPayload::class) {
            return true;
        }

        foreach ($this->options->excluded('jobs') as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private function safely(\Closure $callback): void
    {
        if (Monitor::isSuppressed() || ! $this->options->isUsable()) {
            return;
        }

        try {
            $callback();
        } catch (Throwable) {
            // Instrumentation must never affect job processing.
        }
    }
}
