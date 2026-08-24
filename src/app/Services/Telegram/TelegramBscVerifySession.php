<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

final class TelegramBscVerifySession
{
    private const TTL_MINUTES = 15;

    public function promptOrder(Customer $customer, string $orderId): void
    {
        Cache::put($this->key($customer), $orderId, now()->addMinutes(self::TTL_MINUTES));
    }

    public function promptedOrderId(Customer $customer): ?string
    {
        $orderId = Cache::get($this->key($customer));

        return is_string($orderId) && $orderId !== '' ? $orderId : null;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    private function key(Customer $customer): string
    {
        return 'telegram:bsc-tx-hash:'.$customer->telegram_id;
    }
}
