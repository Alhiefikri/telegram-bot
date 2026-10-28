<?php

namespace App\Services\Telegram\LatencyBaseline;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Owns one bounded queue:work process for the baseline.
 *
 * The process is a real Redis worker using the application's own queue job
 * classes; it is not an in-process sync driver. Output is pumped so a full
 * stderr pipe cannot block the worker.
 */
final class LatencyBaselineWorker
{
    private ?Process $process = null;

    private string $output = '';

    private int $peakRssBytes = 0;

    /**
     * @param  array<string, string>  $environment
     * @param  array{queue: string, sleep: int, tries: int, timeout: int, max_time: int}  $topology
     */
    public function __construct(
        private readonly array $environment,
        private readonly array $topology,
    ) {}

    public function start(): void
    {
        $command = [
            PHP_BINARY,
            __DIR__.'/worker.php',
            'queue:work',
            'redis',
            '--queue='.$this->topology['queue'],
            '--sleep='.$this->topology['sleep'],
            '--tries='.$this->topology['tries'],
            '--timeout='.$this->topology['timeout'],
            '--max-time='.$this->topology['max_time'],
            '--quiet',
        ];

        // proc_open replaces the child environment when an array is supplied;
        // merge the container environment so DB/Redis credentials survive.
        $environment = array_replace(
            array_filter(getenv() ?: [], static fn (mixed $value): bool => is_string($value)),
            $this->environment,
        );

        $this->process = new Process($command, base_path(), $environment);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);
        $this->process->start();
    }

    public function pump(): void
    {
        if ($this->process === null) {
            return;
        }

        try {
            $this->output .= $this->process->getIncrementalOutput();
            $this->output .= $this->process->getIncrementalErrorOutput();
        } catch (Throwable) {
            // The pipe may already be closed after the final wait; the output
            // collected while the worker was running remains authoritative.
        }

        $this->sampleMemory();
    }

    private function sampleMemory(): void
    {
        $pid = $this->process?->getPid();
        if ($pid === null) {
            return;
        }

        $status = @file_get_contents('/proc/'.$pid.'/status');
        if (is_string($status)
            && preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $status, $matches) === 1) {
            $this->peakRssBytes = max($this->peakRssBytes, (int) $matches[1] * 1024);
        }
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->pump();

        try {
            if ($this->process->isRunning()) {
                $this->process->signal(SIGTERM);
                $deadline = microtime(true) + 5;

                // Drain while the worker finishes its current job; stopping
                // through Process::stop() closes the pipes before buffered
                // stderr is read.
                while ($this->process->isRunning() && microtime(true) < $deadline) {
                    usleep(50_000);
                    $this->pump();
                }

                if ($this->process->isRunning()) {
                    $this->process->signal(SIGKILL);
                }

                $this->process->wait();
            }
        } catch (Throwable) {
            // A worker that exited between checks needs no further cleanup.
        }

        $this->pump();
        $this->process = null;
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function output(): string
    {
        return $this->output;
    }

    /** @return array<string, mixed> */
    public function description(): array
    {
        return [
            'connection' => 'redis',
            'queue' => $this->topology['queue'],
            'sleep_seconds' => $this->topology['sleep'],
            'tries' => $this->topology['tries'],
            'job_timeout_seconds' => $this->topology['timeout'],
            'max_time_seconds' => $this->topology['max_time'],
            'workers' => 1,
            'peak_rss_bytes' => $this->peakRssBytes > 0 ? $this->peakRssBytes : null,
            'telegram_latency_ms' => (int) round(((int) ($this->environment['DOGFOOD_FAKE_TELEGRAM_LATENCY_US'] ?? 0)) / 1000),
            'fake_telegram_transport' => ($this->environment['DOGFOOD_FAKE_TELEGRAM_OUTBOUND'] ?? 'false') === 'true',
        ];
    }
}
