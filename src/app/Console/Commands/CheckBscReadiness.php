<?php

namespace App\Console\Commands;

use App\Services\Bsc\BscOperationalMetrics;
use App\Services\Bsc\BscReadiness;
use Illuminate\Console\Command;
use Throwable;

final class CheckBscReadiness extends Command
{
    protected $signature = 'bsc:check-readiness
        {--json : Print a sanitized JSON readiness report}';

    protected $description = 'Check database activation, BSC RPC/token metadata, receiver readiness, and verification backlog.';

    public function handle(BscReadiness $readiness, BscOperationalMetrics $metrics): int
    {
        try {
            $report = $readiness->check();
            $backlog = $metrics->pendingVerification();
            $payload = [
                'enabled' => $report->enabled,
                'ready' => $report->isReady(),
                'missing' => $report->missing,
                'invalid' => array_keys($report->invalid),
                'active_receiver_count' => $report->activeReceiverCount,
                'rpc_chain_id' => $report->rpcChainId,
                'token_decimals' => $report->tokenDecimals,
                'pending_verification' => $backlog['pending'],
                'oldest_pending_age_seconds' => $backlog['oldest_age_seconds'],
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
            } else {
                $this->line(sprintf(
                    'SUMMARY enabled=%s ready=%s active_receivers=%d pending=%d oldest_pending_age_seconds=%d',
                    $payload['enabled'] ? 'true' : 'false',
                    $payload['ready'] ? 'true' : 'false',
                    $payload['active_receiver_count'],
                    $payload['pending_verification'],
                    $payload['oldest_pending_age_seconds'],
                ));
                if (! $report->isReady() && $report->enabled) {
                    $this->line($report->configurationMessage());
                }
            }

            // BSC is disabled by default. Disabled is a safe deployment state;
            // an explicitly enabled but checkout-unready rail is a release failure.
            return ! $report->enabled || $report->isReady()
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable) {
            $this->error('BSC readiness check failed safely.');

            return self::FAILURE;
        }
    }
}
