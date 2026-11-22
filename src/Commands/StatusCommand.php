<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

class StatusCommand extends Command
{
    protected $signature = 'trk-monitor:status';

    protected $description = 'Show TrkMonitor SDK status and configuration (secrets are never displayed).';

    public function handle(Options $options, HttpTransport $transport, Spool $spool): int
    {
        $this->components->twoColumnDetail('SDK version', Monitor::VERSION);
        $this->components->twoColumnDetail('Laravel version', $this->laravel->version());
        $this->components->twoColumnDetail('PHP version', PHP_VERSION);
        $this->components->twoColumnDetail('Monitoring', $options->enabled() ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>');
        $this->components->twoColumnDetail('Environment', $options->environment());
        $this->components->twoColumnDetail('Release', $options->release() ?? '<fg=gray>not set</>');
        $this->components->twoColumnDetail('Server', (string) ($options->get('server.url') ?: '<fg=red>not set</>'));
        $this->components->twoColumnDetail('Project key', $options->projectKey() ?: '<fg=red>not set</>');
        $this->components->twoColumnDetail('Secret', $options->projectSecret() !== '' ? 'configured' : '<fg=red>not set</>');
        $this->components->twoColumnDetail('Request signing', $options->signRequests() ? 'HMAC' : 'bearer secret');
        $this->components->twoColumnDetail('Delivery', $options->queueEnabled()
            ? 'queue ('.($options->queueConnection() ?? config('queue.default')).' / '.$options->queueName().')'
            : 'after response');
        $spooled = $spool->count();
        $this->components->twoColumnDetail('Spooled payloads', $spooled.' / '.$spool->maxFiles());
        $breaker = $transport->breaker();
        $this->components->twoColumnDetail('Circuit breaker', ! $options->circuitBreakerEnabled()
            ? '<fg=yellow>disabled</>'
            : ($breaker->isOpen() ? '<fg=red>open</> (server skipped, next attempt in '.$breaker->retryIn().'s)' : '<fg=green>closed</>'));

        foreach ($options->problems() as $problem) {
            $this->components->warn($problem);
        }

        if ($spool->maxFiles() > 0 && $spooled >= (int) ($spool->maxFiles() * 0.8)) {
            $this->components->warn('The spool is almost full; new payloads are dropped once it is full. Run trk-monitor:flush or check the central server.');
        }

        if (! $options->isUsable()) {
            return self::SUCCESS;
        }

        $health = $transport->get('health', force: true);
        $this->components->twoColumnDetail('Central API', $health->successful() ? '<fg=green>available</>' : '<fg=red>unavailable</> '.$health->error);

        if ($health->successful()) {
            $config = $transport->get('config', force: true);
            $this->components->twoColumnDetail('Authentication', $config->successful() ? '<fg=green>ok</>' : '<fg=red>failed</> '.$config->error);
        }

        return self::SUCCESS;
    }
}
