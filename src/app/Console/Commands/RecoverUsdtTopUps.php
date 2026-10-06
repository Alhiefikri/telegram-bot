<?php

namespace App\Console\Commands;

use App\Services\Telegram\UsdtTopUpOutbound;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use App\Services\UsdtTopUps\UsdtReorgIncidentService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverUsdtTopUps extends Command
{
    protected $signature = 'usdt-funding:recover {--limit=25} {--json}';

    protected $description = 'Recover confirmed USDT preparation from durable DB work and expire local previews without releasing unresolved money.';

    public function handle(UsdtTopUpPreparation $preparation, UsdtFundingIntentService $intents, CustomerUsdtFundingReservationService $reservations, UsdtTopUpSettlement $settlement, UsdtTopUpOutbound $outbound, BscUsdtTransferIngestion $ingestion, UsdtReorgIncidentService $reorgs): int
    {
        $raw = (string) $this->option('limit');
        if (preg_match('/\A[1-9][0-9]{0,2}\z/D', $raw) !== 1 || (int) $raw > 100) {
            $this->error('limit must be between 1 and 100.');

            return self::INVALID;
        }
        $limit = (int) $raw;
        $expired = $intents->expire($limit);
        $prepared = $preparation->recover($limit);
        // DB attempted-at ordering rotates even unavailable receivers. A shared
        // RPC budget bounds the whole tick, not one budget per Customer.
        $streams = DB::table('usdt_bsc_scan_checkpoints')
            ->orderByRaw('CASE WHEN last_attempt_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_attempt_at')->orderBy('receiver_id')->limit(min(25, $limit))->get();
        $requests = 0;
        foreach ($streams as $stream) {
            if ($requests >= 32) {
                break;
            }
            // Keep sub-second ordering across adjacent budgeted ticks. Rounding
            // every attempt to one second repeatedly favors low receiver IDs
            // after an outage gave all streams the same timestamp.
            DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $stream->receiver_id)
                ->update(['last_attempt_at' => now()->format('Y-m-d H:i:s.u')]);
            try {
                $report = $ingestion->scan((int) $stream->receiver_id, (int) $stream->first_block, 2, 32 - $requests);
                $requests += $report['requests'];
            } catch (\Throwable) {
                // Unknown consumed budget must not permit additional RPC; the
                // checkpoint remains recoverable and settlement still runs.
                $requests = 32;
            }
        }
        $result = ['expired_intents' => $expired, 'prepared_work' => $prepared, 'scan_requests' => $requests,
            'held_reservations' => $reservations->holdExpired($limit),
            'reorg_incidents' => $reorgs->recover(min(10, $limit)),
            'settlement_work' => $settlement->recover($limit), 'receipt_dispatches' => $outbound->recover($limit)];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
