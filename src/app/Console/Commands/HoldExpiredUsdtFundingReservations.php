<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\UsdtFunding\CustomerUsdtFundingReservationService;
use Illuminate\Console\Command;

/**
 * Bounded sweep of due Customer USDT funding reservations into
 * expired_unresolved. It never releases a slot and never writes money:
 * expiry does not prove that no funds exist, so ambiguous money stays
 * reserved until a real settlement or typed release resolution.
 */
final class HoldExpiredUsdtFundingReservations extends Command
{
    protected $signature = 'usdt-funding:hold-expired-reservations
        {--limit=100 : Maximum reservations to hold (1-1000).}
        {--customer= : Limit the sweep to one Customer id.}
        {--json : Print the sanitized result as JSON.}';

    protected $description = 'Hold due Customer USDT funding reservations as expired-unresolved without releasing the slot or touching money.';

    public function handle(CustomerUsdtFundingReservationService $reservations): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $customer = null;
        $customerId = $this->option('customer');
        if ($customerId !== null) {
            $id = filter_var($customerId, FILTER_VALIDATE_INT);
            $customer = $id === false || $id < 1 ? null : Customer::query()->find($id);
            if ($customer === null) {
                $this->error('Customer not found.');

                return self::FAILURE;
            }
        }

        $held = $reservations->holdExpired($limit, $customer);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['held' => $held], JSON_THROW_ON_ERROR));
        } else {
            $this->info("Held {$held} expired reservation(s); no slot released and no money written.");
        }

        return self::SUCCESS;
    }
}
