# trk/laravel-monitor

Laravel SDK for **TrkMonitor**. Captures exceptions automatically and sends them, sanitized,
to your TrkMonitor server, without ever interfering with your application.

- Laravel 11, 12 and 13 · PHP 8.2+
- Automatic exception capture through Laravel's exception handler (your own reporting keeps working)
- HMAC-signed requests (the secret never leaves your server), gzip, retries with backoff + jitter
- Asynchronous by default: your queue, or after the response is sent
- Safe when the TrkMonitor server is down: a circuit breaker stops contacting it, events are spooled to disk
  and re-sent automatically by the scheduler ([details](docs/configuration.md#when-the-central-server-is-down))
- Recursive redaction of sensitive data; no bodies, cookies, IPs or e-mails by default
- Request, query and queue-job monitoring with slow-query detection (see [performance monitoring](docs/performance-monitoring.md))

## Installation

```bash
composer require trk/laravel-monitor
php artisan trk-monitor:install
```

Until the package is published, add the private repository to the application's `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "git@your-git-host:trk/laravel-monitor-sdk.git" }]
```

Set the credentials shown when the project was created in TrkMonitor:

```env
TRK_MONITOR_ENABLED=true
TRK_MONITOR_URL=https://monitor.example.com
TRK_MONITOR_PROJECT_KEY=trk_pk_xxxxxxxxxxxxxxxxxxxxxxxx
TRK_MONITOR_PROJECT_SECRET=trk_sk_xxxxxxxx…
TRK_MONITOR_ENVIRONMENT=production
TRK_MONITOR_RELEASE=1.0.0
```

Verify:

```bash
php artisan trk-monitor:test
```

Events are queued on the `monitoring` queue of your default connection. Make sure a worker
consumes it (`php artisan queue:work --queue=default,monitoring`) or set
`TRK_MONITOR_QUEUE=false` to send events right after the response instead.

Keep the Laravel scheduler running (`* * * * * php artisan schedule:run`). The package uses it to
re-send events that were stored on disk while the TrkMonitor server was unreachable.

## Usage

Unhandled exceptions are captured automatically. Manual capture and context:

```php
use Trk\Monitor\Facades\Monitor;

Monitor::captureException($exception);
Monitor::captureMessage('Payment gateway response was delayed', 'warning');

Monitor::setUser(['id' => auth()->id()]);
Monitor::setTag('module', 'payments');
Monitor::setContext('payment', ['gateway' => 'example', 'payment_id' => $paymentId]);
Monitor::addBreadcrumb(['category' => 'payment', 'message' => 'Payment processing started', 'level' => 'info']);
Monitor::setFingerprint(['payment-gateway-timeout']); // optional custom grouping
```

## Commands

| Command | Purpose |
|---|---|
| `trk-monitor:install` | Publish `config/trk-monitor.php` and add the variables to `.env` / `.env.example` |
| `trk-monitor:test` | Check config, connectivity and authentication, then send a test event |
| `trk-monitor:status` | Show SDK/Laravel/PHP versions, configuration and server status (no secrets) |
| `trk-monitor:release {version?}` | Register a deployment (commit/branch detected from git); queued instead of failing when the server is down (`--strict` to fail) |
| `trk-monitor:flush` | Re-send events spooled to disk while the server was unreachable (scheduled every minute; `--force` ignores the circuit breaker) |

## Documentation

[Configuration](docs/configuration.md) · [Performance, database & queues](docs/performance-monitoring.md) · [Privacy](docs/privacy.md) · [Compatibility](docs/compatibility.md) · [Troubleshooting](docs/troubleshooting.md)

## Development

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
