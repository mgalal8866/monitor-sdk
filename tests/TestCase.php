<?php

namespace Trk\Monitor\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Trk\Monitor\TrkMonitorServiceProvider;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'trk_sk_test_secret_0123456789abcdef';

    protected function getPackageProviders($app): array
    {
        return [TrkMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('trk-monitor.enabled', true);
        $app['config']->set('trk-monitor.server.url', 'https://monitor.test');
        $app['config']->set('trk-monitor.project_key', 'trk_pk_test');
        $app['config']->set('trk-monitor.project_secret', self::SECRET);
        $app['config']->set('trk-monitor.environment', 'testing');
        $app['config']->set('trk-monitor.release', '1.2.3');
        $app['config']->set('trk-monitor.queue.enabled', false);
        $app['config']->set('trk-monitor.server.retries', 0);
        $app['config']->set('queue.default', 'sync');
    }
}
