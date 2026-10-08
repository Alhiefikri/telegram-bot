<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded prompt state for one Customer's next USDT funding amount or
 * transaction-hash message. State is bound to the authenticated private chat
 * and current navigation root so a stale prompt cannot consume another flow.
 */
final class TelegramUsdtFundingSession
{
    private const TTL_MINUTES = 10;

    public function promptAmount(Customer $customer, string $chatId, int $rootId, int $messageId, string $rail): void
    {
        Cache::put($this->amountKey($customer), [
            'chat_id' => $chatId,
            'root_id' => $rootId,
            'message_id' => $messageId,
            'rail' => $rail,
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /** @return array{chat_id:string,root_id:int,message_id:int,rail:string}|null */
    public function promptedAmount(Customer $customer): ?array
    {
        $state = Cache::get($this->amountKey($customer));

        return is_array($state)
            && is_string($state['chat_id'] ?? null)
            && is_int($state['root_id'] ?? null)
            && is_int($state['message_id'] ?? null)
            && is_string($state['rail'] ?? null)
            ? $state
            : null;
    }

    public function promptHash(Customer $customer, string $topUpId): void
    {
        Cache::put($this->hashKey($customer), $topUpId, now()->addMinutes(self::TTL_MINUTES));
    }

    public function promptedTopUpId(Customer $customer): ?string
    {
        $topUpId = Cache::get($this->hashKey($customer));

        return is_string($topUpId) && $topUpId !== '' ? $topUpId : null;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->amountKey($customer));
        Cache::forget($this->hashKey($customer));
    }

    private function amountKey(Customer $customer): string
    {
        return 'telegram:usdt-funding-amount:'.$customer->telegram_id;
    }

    private function hashKey(Customer $customer): string
    {
        return 'telegram:usdt-tx-hash:'.$customer->telegram_id;
    }
}
