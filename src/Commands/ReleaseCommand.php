<?php

declare(strict_types=1);

namespace Trk\Monitor\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;
use Trk\Monitor\Monitor;
use Trk\Monitor\Options;
use Trk\Monitor\Transport\Spool;

/** Registers a deployment with TrkMonitor. Run it from your deploy script. */
class ReleaseCommand extends Command
{
    protected $signature = 'trk-monitor:release
        {version? : Release version (defaults to TRK_MONITOR_RELEASE or the current git commit)}
        {--commit= : Commit hash (detected from git when omitted)}
        {--branch= : Branch name (detected from git when omitted)}
        {--environment= : Deployment environment}
        {--strict : Fail when the central server is unreachable instead of queueing the release}';

    protected $description = 'Register a release/deployment with TrkMonitor.';

    public function handle(Monitor $monitor, Options $options, Spool $spool): int
    {
        if (! $options->isUsable()) {
            $this->components->error('TrkMonitor is disabled or not configured.');

            return self::FAILURE;
        }

        $commit = $this->option('commit') ?: $this->git(['rev-parse', 'HEAD']);
        $version = $this->argument('version') ?: $options->release() ?: ($commit ? substr($commit, 0, 12) : null);

        if (! $version) {
            $this->components->error('No version given and none could be detected.');

            return self::FAILURE;
        }

        $release = [
            'version' => $version,
            'commit_hash' => $commit,
            'branch' => $this->option('branch') ?: $this->git(['rev-parse', '--abbrev-ref', 'HEAD']),
            'environment' => $this->option('environment') ?: $options->environment(),
            'deployed_at' => now()->toIso8601String(),
        ];
        $result = $monitor->sendRelease($release);

        // Central server down: never break the deployment. Keep the release and send it later.
        if (! $result->successful() && $result->retryable && ! $this->option('strict')) {
            $spool->store('releases', $monitor->releasePayload($release));
            $this->components->warn("Central server unavailable; release {$version} queued and will be sent by trk-monitor:flush.");

            return self::SUCCESS;
        }

        if (! $result->successful()) {
            $this->components->error('Release registration failed: '.($result->error ?? 'HTTP '.$result->status));

            return self::FAILURE;
        }

        $this->components->info("Release {$version} registered.");

        return self::SUCCESS;
    }

    /** @param list<string> $args */
    private function git(array $args): ?string
    {
        try {
            $process = new Process(['git', ...$args], base_path());
            $process->setTimeout(5);
            $process->run();

            $output = trim($process->getOutput());

            return $process->isSuccessful() && $output !== '' && $output !== 'HEAD' ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }
}
