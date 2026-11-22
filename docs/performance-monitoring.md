# Performance, database and queue monitoring

All instrumentation is automatic once the package is installed. Nothing modifies your
responses, queries or jobs, and instrumentation failures are swallowed.

## HTTP requests (`http.server` transactions)

A middleware prepended to the global stack measures every request and reports it after the
response has been sent:

- name `METHOD /route/{template}`, route name, status code, duration, peak memory
- query count, database time, slow-query count
- authenticated user id (if `privacy.send_user`)

Excluded: `exclude.paths` (default `up`, `health`, `horizon*`, `telescope*`, `_debugbar*`, `pulse*`),
`exclude.routes` (route names or URIs, wildcards) and static assets.

## Sampling

| Kind | Rule |
|---|---|
| Normal transactions | `sampling.transactions` (default 0.1) |
| Slow requests (≥ `performance.slow_request_threshold_ms`), 5xx responses, slow queries, failed/long-running jobs | always reported |
| Query spans inside sampled transactions | `sampling.queries` (slow queries always kept) |
| Errors | `sampling.errors`, independent of everything above |

## Database

- Every query increments the transaction counters and adds a `query` breadcrumb.
- Queries ≥ `performance.slow_query_threshold_ms` (default 500 ms) become slow spans.
- SQL is sanitized: inline literals become `?`, `IN (…)` lists collapse, max `database.max_query_length`.
- Bindings are **never** sent unless `database.send_bindings` is enabled (then sanitized and truncated).
- `database.exclude_tables` (default: queue, cache and session tables) and `database.exclude_connections` are ignored.
- `QueryException` messages are rewritten so neither interpolated bindings nor offending values
  (e.g. duplicate-key values) leave the application.

## Queue jobs (`queue.job` transactions)

- Each job runs in a fresh scope (no context leaks between jobs or from the dispatching request).
- Failed attempts that will be retried produce a `retrying` transaction and a breadcrumb.
- The final failure is reported **once** as an error event with `event_type: job`, the job context
  (class, queue, connection, attempts, max tries, timeout, uuid) and a `failed` transaction.
  Set `jobs.report_retries` to report every attempt.
- Jobs longer than `performance.long_running_job_threshold_ms` are always reported (tagged `slow`).
- Serialized job payloads are never sent; add top-level payload keys to `jobs.metadata_allowlist` to include them.
- `exclude.jobs` skips job classes (wildcards). The SDK's own delivery job is never monitored.

## Breadcrumbs

Automatic breadcrumbs (each can be disabled under `breadcrumbs.*`): queries, log messages,
outgoing HTTP client calls (query strings stripped; calls to the TrkMonitor server ignored) and
dispatched jobs.
