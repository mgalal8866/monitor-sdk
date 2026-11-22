<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;
use Throwable;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\HttpTransport;
use Trk\Monitor\Transport\Spool;

/** Re-sends payloads spooled to disk while the central server was unreachable. */
class FlushCommand extends Command
{
    protected $signature = 'trk-monitor:flush
        {--limit=500 : Maximum number of payloads to send}
        {--force : Contact the server even while the circuit breaker is open}
        {--scheduled : Run quietly from the scheduler (no retries, no output when idle)}';

    protected $description = 'Send TrkMonitor payloads that were buffered on disk.';

    public function handle(Spool $spool, HttpTransport $transport, Options $options): int
    {
        $scheduled = (bool) $this->option('scheduled');

        try {
            if (! $options->isUsable()) {
                if (! $scheduled) {
                    $this->components->error('TrkMonitor is disabled or not configured.');
                }

                return $scheduled ? self::SUCCESS : self::FAILURE;
            }

            if ($spool->count() === 0) {
                if (! $scheduled) {
                    $this->components->info('Nothing to send.');
                }

                return self::SUCCESS;
            }

            $force = (bool) $this->option('force');

            if (! $force && $transport->breaker()->isOpen()) {
                if (! $scheduled) {
                    $this->components->warn(sprintf(
                        'Central server unavailable; next attempt in %ds (use --force to try now). %d payload(s) waiting.',
                        $transport->breaker()->retryIn(),
                        $spool->count(),
                    ));
                }

                return self::SUCCESS;
            }

            $lock = $this->acquireLock($spool);
            if ($lock === false) {
                if (! $scheduled) {
                    $this->components->warn('Another trk-monitor:flush is already running.');
                }

                return self::SUCCESS;
            }

            try {
                $retries = $scheduled ? 0 : null;
                $result = $spool->flush(
                    fn (string $endpoint, array $payload) => $transport->post($endpoint, $payload, $retries, $force),
                    max(1, (int) $this->option('limit')),
                );
            } finally {
                $this->releaseLock($lock);
            }

            if (! $scheduled || $result['sent'] > 0 || $result['dropped'] > 0) {
                $this->components->info(sprintf('Sent %d, dropped %d, remaining %d.', $result['sent'], $result['dropped'], $result['remaining']));
            }
        } catch (Throwable $e) {
            // Never fail the scheduler because of monitoring.
            if (! $scheduled) {
                $this->components->error('Flush failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /** @return resource|false|null null when locking is not possible (flush proceeds unlocked) */
    private function acquireLock(Spool $spool): mixed
    {
        try {
            $handle = @fopen($spool->directory().DIRECTORY_SEPARATOR.'.flush.lock', 'c');
            if ($handle === false) {
                return null;
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return false;
            }

            return $handle;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param resource|null $lock */
    private function releaseLock(mixed $lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
