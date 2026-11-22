<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

/** Re-sends payloads spooled to disk while the central server was unreachable. */
class FlushCommand extends Command
{
    protected $signature = 'trk-monitor:flush {--limit=500 : Maximum number of payloads to send}';

    protected $description = 'Send TrkMonitor payloads that were buffered on disk.';

    public function handle(Spool $spool, HttpTransport $transport, Options $options): int
    {
        if (! $options->isUsable()) {
            $this->components->error('TrkMonitor is disabled or not configured.');

            return self::FAILURE;
        }

        $result = $spool->flush(fn (string $endpoint, array $payload) => $transport->post($endpoint, $payload), (int) $this->option('limit'));

        $this->components->info(sprintf('Sent %d, dropped %d, remaining %d.', $result['sent'], $result['dropped'], $result['remaining']));

        return self::SUCCESS;
    }
}
