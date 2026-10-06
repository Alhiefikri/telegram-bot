<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded prompt state for one Customer's next USDT transaction-hash message.
 * It only selects which confirmed target the next lookup hint belongs to; the
 * hint itself is not money authority and never credits a balance.
 */
final class TelegramUsdtFundingSession
{
    private const TTL_MINUTES = 15;

    public function promptHash(Customer $customer, string $topUpId): void
    {
        Cache::put($this->key($customer), $topUpId, now()->addMinutes(self::TTL_MINUTES));
    }

    public function promptedTopUpId(Customer $customer): ?string
    {
        $topUpId = Cache::get($this->key($customer));

        return is_string($topUpId) && $topUpId !== '' ? $topUpId : null;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    private function key(Customer $customer): string
    {
        return 'telegram:usdt-tx-hash:'.$customer->telegram_id;
    }
}
