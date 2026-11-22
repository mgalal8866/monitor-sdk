<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;
use Trk\Monitor\EventFactory;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Scope;
use Trk\Monitor\Transport\HttpTransport;

/** Verifies installation, configuration, connectivity and authentication, then sends a test event. */
class TestCommand extends Command
{
    protected $signature = 'trk-monitor:test';

    protected $description = 'Send a controlled test event to TrkMonitor.';

    public function handle(Options $options, HttpTransport $transport, EventFactory $events): int
    {
        $this->components->twoColumnDetail('SDK', 'trk/laravel-monitor '.Monitor::VERSION);

        if (! $options->enabled()) {
            $this->components->error('Monitoring is disabled (TRK_MONITOR_ENABLED=false).');

            return self::FAILURE;
        }

        if ($problems = $options->problems()) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Server', $options->apiBase());
        $this->components->twoColumnDetail('Project key', $options->projectKey());
        $this->components->twoColumnDetail('Secret', str_repeat('•', 8).' (hidden)');

        $health = $transport->get('health');
        if (! $health->successful()) {
            $this->components->error('Central API unreachable: '.($health->error ?? 'HTTP '.$health->status));

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('Central API', '<fg=green>reachable</>');

        $config = $transport->get('config');
        if (! $config->successful()) {
            $this->components->error('Authentication failed: '.($config->error ?? 'HTTP '.$config->status));

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('Authentication', '<fg=green>ok</> ('.data_get($config->body, 'data.project.name').')');

        $payload = $events->fromMessage('TrkMonitor test event from '.config('app.name'), 'info', new Scope, [
            'tags' => ['trk_monitor_test' => 'true'],
        ]);
        $result = $transport->post('events', $payload);

        if (! $result->successful() || (int) data_get($result->body, 'data.accepted') !== 1) {
            $this->components->error('Test event rejected: '.($result->error ?? json_encode(data_get($result->body, 'data'))));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Test event', '<fg=green>accepted</> ('.$payload['event_id'].')');
        $this->components->info('TrkMonitor is correctly configured.');

        return self::SUCCESS;
    }
}
