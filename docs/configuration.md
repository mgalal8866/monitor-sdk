# Configuration

Publish with `php artisan vendor:publish --tag=trk-monitor-config` (done by `trk-monitor:install`).

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `TRK_MONITOR_ENABLED` | `true` | `false` disables every transmission |
| `server.url` | `TRK_MONITOR_URL` | — | Base URL of the TrkMonitor server |
| `project_key` / `project_secret` | `TRK_MONITOR_PROJECT_KEY` / `_SECRET` | — | Missing values disable the SDK silently |
| `environment` | `TRK_MONITOR_ENVIRONMENT` | `APP_ENV` | `[A-Za-z0-9_.-]`, max 64 |
| `release` | `TRK_MONITOR_RELEASE` | — | Associates events with a release |
| `server.timeout` / `connect_timeout` | `TRK_MONITOR_TIMEOUT` / `_CONNECT_TIMEOUT` | 3 / 2 s | Capped at 30 s |
| `server.sign_requests` | `TRK_MONITOR_SIGN_REQUESTS` | `true` | HMAC signing instead of sending the secret |
| `server.compress` | `TRK_MONITOR_COMPRESS` | `true` | gzip bodies > 1 KiB |
| `server.retries` | `TRK_MONITOR_RETRIES` | 3 | Retries for connection errors, 429 and 5xx |
| `queue.enabled` | `TRK_MONITOR_QUEUE` | `true` | Uses your queue; falls back to "after response" on `sync` or failure |
| `queue.connection` / `queue.name` | `TRK_MONITOR_QUEUE_CONNECTION` / `_NAME` | default / `monitoring` | Never assumes a dedicated Redis queue |
| `sampling.errors` | — | 1.0 | Error sampling is independent from performance sampling |
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
