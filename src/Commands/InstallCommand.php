<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;

/** Publishes the configuration and adds the environment variables to .env / .env.example. */
class InstallCommand extends Command
{
    protected $signature = 'trk-monitor:install
        {--url= : TrkMonitor server URL}
        {--key= : Project key ID}
        {--secret= : Project secret (written to .env only)}
        {--force : Overwrite an existing config/trk-monitor.php}';

    protected $description = 'Install TrkMonitor: publish config and add environment variables.';

    private const VARIABLES = [
        'TRK_MONITOR_ENABLED' => 'true',
        'TRK_MONITOR_URL' => '',
        'TRK_MONITOR_PROJECT_KEY' => '',
        'TRK_MONITOR_PROJECT_SECRET' => '',
        'TRK_MONITOR_ENVIRONMENT' => '${APP_ENV}',
        'TRK_MONITOR_RELEASE' => '',
    ];

    public function handle(): int
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'trk-monitor-config',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->components->info('Configuration published to config/trk-monitor.php.');

        $values = array_merge(self::VARIABLES, array_filter([
            'TRK_MONITOR_URL' => $this->option('url'),
            'TRK_MONITOR_PROJECT_KEY' => $this->option('key'),
            'TRK_MONITOR_PROJECT_SECRET' => $this->option('secret'),
        ]));

        $this->writeEnv(base_path('.env'), $values);
        $this->writeEnv(base_path('.env.example'), array_merge($values, ['TRK_MONITOR_PROJECT_SECRET' => '']));

        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. Set TRK_MONITOR_URL, TRK_MONITOR_PROJECT_KEY and TRK_MONITOR_PROJECT_SECRET in .env');
        $this->line('  2. Run <info>php artisan trk-monitor:test</info> to verify the connection');

        return self::SUCCESS;
    }

    /** @param array<string, string> $values */
    private function writeEnv(string $path, array $values): void
    {
        if (! is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);
        $missing = [];

        foreach ($values as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents)) {
                // Fill only empty existing values, never overwrite configured ones.
                if ($value !== '' && preg_match('/^'.preg_quote($key, '/').'=\s*$/m', $contents)) {
                    $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=\s*$/m', $key.'='.$value, $contents);
                }

                continue;
            }

            $missing[] = $key.'='.$value;
        }

        if ($missing !== []) {
            $contents = rtrim($contents)."\n\n".implode("\n", $missing)."\n";
        }

        file_put_contents($path, $contents);
        $this->components->info('Updated '.basename($path).'.');
    }
}
