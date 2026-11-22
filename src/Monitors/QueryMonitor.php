<?php

declare(strict_types=1);

namespace Trk\Monitor\Monitors;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Performance\Tracer;
use Trk\Monitor\Scope;
use Trk\Monitor\Support\Sanitizer;
use Trk\Monitor\Support\SqlSanitizer;

/**
 * Database instrumentation: query count and time per transaction, slow-query spans and
 * query breadcrumbs. SQL is sanitized (no literals); bindings are omitted unless enabled.
 */
final class QueryMonitor
{
    public function __construct(
        private readonly Options $options,
        private readonly Tracer $tracer,
        private readonly Scope $scope,
        private readonly Sanitizer $sanitizer,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        if (Monitor::isSuppressed()) {
            return;
        }

        try {
            if ($this->excluded($event)) {
                return;
            }

            $durationMs = (float) $event->time;
            $sql = SqlSanitizer::sanitize($event->sql, max(100, (int) $this->options->get('database.max_query_length', 2000)));
            $slow = $durationMs >= (float) $this->options->get('performance.slow_query_threshold_ms', 500);

            $data = [
                'connection' => $event->connectionName,
                'driver' => $event->connection->getDriverName(),
                'category' => SqlSanitizer::category($event->sql),
            ];

            if ($this->options->get('database.send_bindings', false)) {
                $data['bindings'] = array_map(
                    fn ($b) => is_string($b) ? Str::limit((string) $this->sanitizer->sanitize($b), 100) : (is_scalar($b) || $b === null ? $b : '['.get_debug_type($b).']'),
                    $event->bindings,
                );
            }

            $this->tracer->current()?->recordQuery($sql, $durationMs, $slow, $data, $this->keepSpan());

            if ($this->options->captures('breadcrumbs') && $this->options->get('breadcrumbs.queries', true)) {
                $this->scope->addBreadcrumb([
                    'category' => 'query',
                    'message' => Str::limit($sql, 500),
                    'level' => $slow ? 'warning' : 'info',
                    'data' => ['connection' => $event->connectionName, 'time_ms' => round($durationMs, 2)],
                ]);
            }
        } catch (Throwable) {
            // Instrumentation must never affect queries.
        }
    }

    private function excluded(QueryExecuted $event): bool
    {
        if (in_array($event->connectionName, (array) $this->options->get('database.exclude_connections', []), true)) {
            return true;
        }

        $excludedTables = array_map('strtolower', (array) $this->options->get('database.exclude_tables', []));

        return $excludedTables !== [] && array_intersect(SqlSanitizer::tables($event->sql), $excludedTables) !== [];
    }

    private function keepSpan(): bool
    {
        $rate = $this->options->sampleRate('queries');

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }
}
