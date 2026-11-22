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
        $this->components->twoColumnDetail('Spooled payloads', (string) $spool->count());

        foreach ($options->problems() as $problem) {
            $this->components->warn($problem);
        }

        if (! $options->isUsable()) {
            return self::SUCCESS;
        }

        $health = $transport->get('health');
        $this->components->twoColumnDetail('Central API', $health->successful() ? '<fg=green>available</>' : '<fg=red>unavailable</> '.$health->error);

        if ($health->successful()) {
            $config = $transport->get('config');
            $this->components->twoColumnDetail('Authentication', $config->successful() ? '<fg=green>ok</>' : '<fg=red>failed</> '.$config->error);
        }

        return self::SUCCESS;
    }
}
