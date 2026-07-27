<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class TelegramBinanceVerifySession
{
    public function promptedOrderId(Customer $customer): ?string
    {
        $value = Cache::get($this->promptKey($customer));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function promptOrder(Customer $customer, string $orderId): void
    {
        Cache::put($this->promptKey($customer), $orderId, now()->addMinutes(15));
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->promptKey($customer));
    }

    /**
     * Count a verification attempt for the order and report whether the
     * attempt is still within the rate limit window.
     */
    public function withinAttemptLimit(string $orderId): bool
    {
        $key = 'telegram:binance-verify-attempts:'.$orderId;
        $decay = max(60, (int) config('services.binance.verify_rate_limit_decay_seconds', 300));

        Cache::add($key, 0, $decay);
        $attempts = (int) Cache::increment($key);

        return $attempts <= max(1, (int) config('services.binance.verify_rate_limit_attempts', 5));
    }

    private function promptKey(Customer $customer): string
    {
        return 'telegram:binance-orderid:'.$customer->telegram_id;
    }
}
