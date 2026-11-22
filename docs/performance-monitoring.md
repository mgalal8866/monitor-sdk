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

The decision is made once, when a transaction starts, and is propagated to downstream services and jobs.

| Kind | Rule |
|---|---|
| Continued trace (incoming `traceparent`) | follows the caller's *sampled* flag (`sampling.respect_parent`, default on) |
| Routes | `sampling.routes`: first pattern matching `METHOD /path` or the path, e.g. `['api/checkout*' => 1.0, 'GET /search' => 0.01]` |
| Jobs | `sampling.jobs`: first pattern matching the job class, e.g. `['App\Jobs\Sync*' => 0.5]` |
| Environment | `sampling.environments`, e.g. `['staging' => 1.0]` |
| Everything else | `sampling.transactions` (default 0.1) |
| Slow requests (≥ `performance.slow_request_threshold_ms`), 5xx responses, slow queries, failed/long-running jobs | always reported |
| Transactions during which an error was captured | always reported (tagged `has_error`), with counters and slow spans |
| Query spans inside sampled transactions | `sampling.queries` (slow queries always kept) |
| Errors | `sampling.errors`, independent of everything above: an exception is never dropped because transaction sampling is low |

## Spans and distributed tracing

Each transaction is the root of a span tree. Every span has a `span_id`, a `parent_span_id`, an
operation, a description, a duration (monotonic clock), a status and attributes.

| Span | Created for | Description / attributes |
|---|---|---|
| `db.query` | each query (see *Database*) | sanitized SQL; connection, driver, category |
| `http.client` | each request made with Laravel's HTTP client | `METHOD scheme://host/path` (no query string or credentials); host, port, status code. Status `error` (5xx), `client_error` (4xx), `unavailable` (connection failed) |
| `queue.publish` | each job dispatched during the transaction | job class; connection, queue |
| `queue.process` | a job executed synchronously (`sync` driver) inside the transaction | its queries become children of this span |

**W3C Trace Context.** The SDK continues incoming `traceparent` headers and forwards `tracestate`
unchanged. It also propagates them:

- **Outgoing HTTP:** `traceparent` / `tracestate` are added to requests made with the HTTP client.
  The `traceparent` carries the id of the `http.client` span, so the receiving service's transaction
  becomes its child. Headers the application set are never overwritten. Requests to the TrkMonitor
  ingestion API never get them. Limit the target hosts with `tracing.propagation_targets`
  (wildcards, default `['*']`), or disable propagation with `TRK_MONITOR_TRACE_PROPAGATION=false`.
- **Queued jobs:** the dispatch span's trace context is stored in the job payload (`trk_trace`;
  identifiers only), and the worker's `queue.job` transaction continues the trace.

Invalid or oversized trace headers are ignored, and a new trace is started. Each request and each
job starts from a clean trace context, so nothing leaks between requests, even in long-running
workers (Octane).

In the TrkMonitor dashboard, a transaction page shows the span **waterfall as a tree** and the
**distributed trace**: every transaction sharing the trace id, linked to the span that started it.
The *Transaction statistics* page lists throughput, average, p50/p95/max duration and failure rate
per transaction name.

## Database

- Every query increments the transaction counters and adds a `query` breadcrumb.
- Queries ≥ `performance.slow_query_threshold_ms` (default 500 ms) become slow spans.
- SQL is sanitized: inline literals become `?`, `IN (…)` lists collapse, max `database.max_query_length`.
- Bindings are **never** sent unless `database.send_bindings` is enabled (then sanitized and truncated).
- `database.exclude_tables` (default: queue, cache and session tables) and `database.exclude_connections` are ignored.
- `QueryException` messages are rewritten so neither interpolated bindings nor offending values
  (e.g. duplicate-key values) leave the application.

## Queue jobs (`queue.job` transactions)

- Each job runs in a fresh scope (no context leaks between jobs or from the dispatching request),
  and continues the trace of the code that dispatched it.
- Jobs executed by the `sync` driver inside a request are part of that request's transaction (a
  `queue.process` span), not separate transactions, and keep the request's scope.
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
