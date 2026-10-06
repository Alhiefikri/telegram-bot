<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceRestoreGateReader;
use Illuminate\Console\Command;

/** Prints the current post-restore gate state and its recorded evidence. */
final class ReportBalanceRestoreGate extends Command
{
    protected $signature = 'usdt-balance:restore-status
        {--json : Print the sanitized JSON status}';

    protected $description = 'Report the post-restore gate state, restore window, replay and closing evidence.';

    public function handle(BalanceRestoreGateReader $reader): int
    {
        $status = $reader->status();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf('STATE %s (open=%s)', $status['state'], $status['open'] ? 'true' : 'false'));
        if ($status['open']) {
            $this->warn(sprintf(
                'BLOCKED new USDT funding/spending since %s (window %s .. %s)',
                $status['opened_at'],
                $status['window_start'],
                $status['window_end'],
            ));
        }

        $this->line(sprintf(
            'REPLAY completed_at=%s by=%s',
            $status['replay_completed_at'] ?? 'none',
            $status['replay_by'] ?? 'none',
        ));
        $evidence = $status['replay_evidence'];
        if (is_array($evidence)) {
            $settlement = $evidence['settlement'] ?? [];
            $this->line(sprintf(
                '  settlement checked=%s settled=%s review=%s unchanged=%s failed=%s',
                $settlement['checked'] ?? 0,
                $settlement['settled'] ?? 0,
                $settlement['review'] ?? 0,
                $settlement['unchanged'] ?? 0,
                $settlement['failed'] ?? 0,
            ));
            $this->line(sprintf(
                '  outbound quarantined=%s scan=%s requests=%s',
                $evidence['outbound']['quarantined'] ?? 0,
                $evidence['scan']['status'] ?? 'skipped',
                $evidence['scan']['requests'] ?? 0,
            ));
        }

        $this->line(sprintf('CLEARED at=%s by=%s', $status['cleared_at'] ?? 'none', $status['cleared_by'] ?? 'none'));

        return self::SUCCESS;
    }
}
