# Configuration

Publish with `php artisan vendor:publish --tag=trk-monitor-config` (done by `trk-monitor:install`).

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `TRK_MONITOR_ENABLED` | `true` | `false` disables every transmission |
| `server.url` | `TRK_MONITOR_URL` | — | Base URL of the TrkMonitor server |
| `project_key` / `project_secret` | `TRK_MONITOR_PROJECT_KEY` / `_SECRET` | — | Missing values disable the SDK silently |
| `environment` | `TRK_MONITOR_ENVIRONMENT` | `APP_ENV` | `[A-Za-z0-9_.-]`, max 64 |
| `release` | `TRK_MONITOR_RELEASE` | — | Associates events with a release |
| `server.timeout` / `connect_timeout` | `TRK_MONITOR_TIMEOUT` / `_CONNECT_TIMEOUT` | 3 / 1 s | Capped at 30 s |
| `server.sign_requests` | `TRK_MONITOR_SIGN_REQUESTS` | `true` | HMAC signing instead of sending the secret |
| `server.compress` | `TRK_MONITOR_COMPRESS` | `true` | gzip bodies > 1 KiB |
| `server.retries` | `TRK_MONITOR_RETRIES` | 3 | Retries for connection errors, 429 and 5xx (manual commands) |
| `server.inline_retries` | `TRK_MONITOR_INLINE_RETRIES` | 0 | Retries inside a request/command lifecycle (max 3); keep at 0 |
| `circuit_breaker.enabled` | `TRK_MONITOR_CIRCUIT_BREAKER` | `true` | Stop contacting an unreachable server (see below) |
| `circuit_breaker.cooldown` / `max_cooldown` | `TRK_MONITOR_CIRCUIT_COOLDOWN` / `_MAX_COOLDOWN` | 30 / 300 s | Pause after a failure; doubles while the server stays down |
| `spool.max_files` | `TRK_MONITOR_SPOOL_MAX_FILES` | 1000 | Payloads kept on disk while the server is down; newer ones are dropped when full |
| `spool.auto_flush` | `TRK_MONITOR_SPOOL_AUTO_FLUSH` | `true` | Schedules `trk-monitor:flush` every minute (needs the Laravel scheduler) |
| `queue.enabled` | `TRK_MONITOR_QUEUE` | `true` | Uses your queue; falls back to "after response" on `sync` or failure |
| `queue.connection` / `queue.name` | `TRK_MONITOR_QUEUE_CONNECTION` / `_NAME` | default / `monitoring` | Never assumes a dedicated Redis queue |
| `sampling.errors` | — | 1.0 | Error sampling is independent from performance sampling |
| `sampling.transactions` | — | 0.1 | Default transaction sample rate |
| `sampling.routes` / `sampling.jobs` / `sampling.environments` | — | `[]` | Pattern → rate overrides (see [performance monitoring](performance-monitoring.md#sampling)) |
| `sampling.respect_parent` | — | `true` | Follow the sampled flag of an incoming `traceparent` |
| `tracing.propagate` | `TRK_MONITOR_TRACE_PROPAGATION` | `true` | Add `traceparent`/`tracestate` to outgoing HTTP requests and queued jobs |
| `tracing.propagation_targets` | — | `['*']` | Hosts (wildcards) that receive trace headers |
| `tracing.http_client_spans` / `queue_spans` | — | `true` | `http.client` and `queue.publish` spans |
| `exclude.exceptions` | — | `[]` | Exception classes (and subclasses) never reported |
| `max_payload_size` | — | 1 MiB | Payloads are trimmed progressively to fit |
| `breadcrumbs.max` | — | 50 | Ring buffer size |
| `breadcrumbs.queries` / `logs` / `http_client` / `queue` | — | `true` | Automatic breadcrumb sources |
| `performance.slow_query_threshold_ms` | — | 500 | Slow query threshold |
| `performance.slow_request_threshold_ms` | — | 1000 | Slow request threshold (always reported) |
| `performance.long_running_job_threshold_ms` | — | 60000 | Long-running job threshold |
| `database.send_bindings` | — | `false` | Send sanitized bindings with query spans |
| `database.exclude_tables` / `exclude_connections` | — | queue/cache/session tables | Ignored by query monitoring |
| `jobs.report_retries` | — | `false` | Report every failed attempt instead of the final failure |
| `jobs.metadata_allowlist` | — | `[]` | Extra top-level job payload keys in the job context |
| `exclude.paths` / `exclude.routes` / `exclude.jobs` | — | health/tooling paths | Never monitored as transactions |

Invalid values fall back to safe defaults; `php artisan trk-monitor:status` lists configuration problems.

## When the central server is down

The application is never affected: no exception, no error page, and at most one short timeout.

1. The first failed delivery (timeout, connection error, 429 or 5xx) **opens the circuit**. This
   costs at most `connect_timeout` (1 s) or `timeout` (3 s), and happens after the response has
   been sent, or inside a queue worker.
2. While the circuit is open, every PHP process on the machine (FPM, queue workers, scheduler)
   skips the network. Payloads are written to `storage/framework/trk-monitor/spool` in about 1 ms,
   and no queue jobs are dispatched. The shared state is a small file
   (`storage/framework/trk-monitor/circuit.json`), so Redis or the cache can be down too.
3. After the cooldown, one process probes the server. If the probe succeeds, the circuit closes;
   if it fails, the cooldown doubles, up to `max_cooldown`.
4. The scheduled `trk-monitor:flush` re-sends spooled payloads once the server is back. Make sure
   the scheduler runs (`* * * * * php artisan schedule:run`); without it, run the command
   manually.
5. `trk-monitor:release` does not fail a deployment. The release is spooled and sent later, unless
   `--strict` is passed.

`trk-monitor:status` shows the circuit state and how full the spool is.
