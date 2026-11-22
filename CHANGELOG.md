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
