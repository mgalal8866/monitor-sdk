# Troubleshooting

Start with `php artisan trk-monitor:status` and `php artisan trk-monitor:test`.

| Symptom | Cause / fix |
|---|---|
| `TRK_MONITOR_URL is not set` | Set the variables, then `php artisan config:clear` |
| `Authentication failed: Missing or invalid credentials` | Wrong key/secret, or the key was rotated |
| `Credentials have been revoked` / `Project is disabled` | Issue a new key or enable the project in TrkMonitor |
| `Request signature timestamp is outside the allowed window` | Server clock skew > 5 min; sync NTP |
| Test works but real errors never arrive | No worker consumes the `monitoring` queue: run one, or set `TRK_MONITOR_QUEUE=false` |
| Spooled payloads > 0 in status | The server was unreachable. They are re-sent by the scheduler; check that `schedule:run` runs, or run `php artisan trk-monitor:flush` |
| `Circuit breaker: open` in status | The server failed recently, so the SDK is pausing (30 s → 5 min). Payloads are spooled; `trk-monitor:flush --force` retries now |
| `The spool is almost full` | The server has been down for a long time, or the scheduler is not running; new payloads are dropped once it is full |
| `Release … queued` during a deploy | The server was down; the release is sent by `trk-monitor:flush`. Use `--strict` to fail instead |
| Some exceptions are missing | They are in Laravel's `dontReport` list or `exclude.exceptions`, or sampled out |

The SDK never throws into your application; internal failures are written at `debug` level to your
default log channel.
