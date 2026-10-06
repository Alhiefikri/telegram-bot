<?php

namespace App\Console\Commands;

use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use App\Services\UsdtFunding\UsdtFundingIntentService;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use Illuminate\Console\Command;

final class RecoverUsdtTopUps extends Command
{
    protected $signature = 'usdt-funding:recover {--limit=25} {--json}';

    protected $description = 'Recover confirmed USDT preparation from durable DB work and expire local previews without releasing unresolved money.';

    public function handle(UsdtTopUpPreparation $preparation, UsdtFundingIntentService $intents, CustomerUsdtFundingReservationService $reservations): int
    {
        $raw = (string) $this->option('limit');
        if (preg_match('/\A[1-9][0-9]{0,2}\z/D', $raw) !== 1 || (int) $raw > 100) {
            $this->error('limit must be between 1 and 100.');

            return self::INVALID;
        }
        $limit = (int) $raw;
        $result = ['expired_intents' => $intents->expire($limit), 'prepared_work' => $preparation->recover($limit),
            'held_reservations' => $reservations->holdExpired($limit)];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
