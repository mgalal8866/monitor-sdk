<?php

declare(strict_types=1);

namespace Trk\Monitor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null captureException(\Throwable $e, array $overrides = [])
 * @method static string|null captureMessage(string $message, string $level = 'info', array $overrides = [])
 * @method static void setUser(?array $user)
 * @method static void setTag(string $key, string|int|float|bool|null $value)
 * @method static void setTags(array $tags)
 * @method static void setContext(string $name, array $context)
 * @method static void addBreadcrumb(array $breadcrumb)
 * @method static void setFingerprint(?array $fingerprint)
 * @method static void configureScope(\Closure $callback)
 * @method static \Trk\Monitor\Scope scope()
 * @method static string|null lastEventId()
 * @method static \Trk\Monitor\Transport\TransportResult sendRelease(array $release)
 * @method static void flush()
 *
 * @see \Trk\Monitor\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Trk\Monitor\Monitor::class;
    }
}
