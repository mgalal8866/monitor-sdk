# Changelog

All notable changes follow [Semantic Versioning](https://semver.org).

## [Unreleased] – 1.0.0

### Added
- Automatic exception capture via Laravel's exception handler (no duplicates, no recursion).
- Manual API: `captureException`, `captureMessage`, user, tags, contexts, breadcrumbs, custom fingerprints.
- HTTP transport with HMAC signing, gzip, timeouts, retries with exponential backoff and jitter.
- Queue delivery on a configurable connection/queue, after-response fallback, disk spool.
- Recursive privacy redaction; W3C trace identifiers (incoming `traceparent` continued).
- Commands: `trk-monitor:install`, `test`, `status`, `release`, `flush`.
- HTTP request transactions (route templates, status, duration, memory, query count, database time).
- Database instrumentation: sanitized SQL, slow-query spans, query breadcrumbs, excluded tables/connections, optional sanitized bindings.
- Queue instrumentation: per-job scope, job transactions, single report per failed job (`event_type: job`), retries as breadcrumbs, long-running job detection.
- Automatic breadcrumbs for logs, HTTP client calls and dispatched jobs.
- `QueryException` / `PDOException` messages sanitized (no bindings or offending values).
