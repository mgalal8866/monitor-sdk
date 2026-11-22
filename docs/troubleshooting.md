# Troubleshooting

Start with `php artisan trk-monitor:status` and `php artisan trk-monitor:test`.

| Symptom | Cause / fix |
|---|---|
| `TRK_MONITOR_URL is not set` | Set the variables, then `php artisan config:clear` |
| `Authentication failed: Missing or invalid credentials` | Wrong key/secret, or the key was rotated |
| `Credentials have been revoked` / `Project is disabled` | Issue a new key or enable the project in TrkMonitor |
| `Request signature timestamp is outside the allowed window` | Server clock skew > 5 min; sync NTP |
| Test works but real errors never arrive | No worker consumes the `monitoring` queue: run one, or set `TRK_MONITOR_QUEUE=false` |
| Spooled payloads > 0 in status | The server was unreachable; run `php artisan trk-monitor:flush` |
| Some exceptions are missing | They are in Laravel's `dontReport` list or `exclude.exceptions`, or sampled out |

The SDK never throws into your application; internal failures are written at `debug` level to your
default log channel.
