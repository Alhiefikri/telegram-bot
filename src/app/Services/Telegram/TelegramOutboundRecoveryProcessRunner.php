<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final class TelegramOutboundRecoveryProcessRunner
{
    private const POLL_MICROSECONDS = 250_000;

    private const LOG_INTERVAL_SECONDS = 5;

    public function run(Process $process, int $maxSeconds, string $commandName): int
    {
        if ($maxSeconds < 1) {
            throw new \InvalidArgumentException('Process deadline must be at least one second.');
        }

        $process->disableOutput();
        $process->start();

        $startedAt = hrtime(true);
        $lastLoggedAge = -self::LOG_INTERVAL_SECONDS;
        $lastRssSampleAt = 0.0;
        $pid = $process->getPid();
        $rssKilobytes = $this->rssKilobytes($pid);
        $peakRssKilobytes = $rssKilobytes;

        Log::info('runtime.command_started', [
            'command' => $commandName,
            'pid' => $pid,
            'max_age_seconds' => $maxSeconds,
            'rss_kib' => $rssKilobytes,
        ]);

        while ($process->isRunning()) {
            $ageSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            if ($ageSeconds - $lastRssSampleAt >= self::LOG_INTERVAL_SECONDS || $ageSeconds >= $maxSeconds) {
                $rssKilobytes = $this->rssKilobytes($pid);
                if ($rssKilobytes !== null) {
                    $peakRssKilobytes = max($peakRssKilobytes ?? 0, $rssKilobytes);
                }
                $lastRssSampleAt = $ageSeconds;
            }

            if ($ageSeconds >= $maxSeconds) {
                $process->stop(2);
                $process->wait();

                Log::warning('runtime.command_terminated', [
                    'command' => $commandName,
                    'pid' => $pid,
                    'age_seconds' => round($ageSeconds, 3),
                    'rss_kib' => $rssKilobytes,
                    'peak_rss_kib' => $peakRssKilobytes,
                    'reason' => 'execution_deadline',
                ]);

                return 124;
            }

            $age = (int) floor($ageSeconds);
            if ($age - $lastLoggedAge >= self::LOG_INTERVAL_SECONDS) {
                Log::info('runtime.command_running', [
                    'command' => $commandName,
                    'pid' => $pid,
                    'age_seconds' => $age,
                    'rss_kib' => $rssKilobytes,
                    'peak_rss_kib' => $peakRssKilobytes,
                ]);
                $lastLoggedAge = $age;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $process->wait();
        $ageSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $exitCode = $process->getExitCode() ?? 1;

        Log::log($exitCode === 0 ? 'info' : 'warning', 'runtime.command_finished', [
            'command' => $commandName,
            'pid' => $pid,
            'exit_code' => $exitCode,
            'age_seconds' => round($ageSeconds, 3),
            'peak_rss_kib' => $peakRssKilobytes,
        ]);

        return $exitCode;
    }

    private function rssKilobytes(?int $pid): ?int
    {
        if ($pid === null || ! is_readable("/proc/{$pid}/status")) {
            return null;
        }

        $status = file_get_contents("/proc/{$pid}/status");
        if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
