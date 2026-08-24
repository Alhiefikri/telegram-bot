<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

final class TelegramBinanceRailSelectionSession
{
    private const TTL_MINUTES = 10;

    public function start(Customer $customer, array $state): array
    {
        $selection = [
            ...$state,
            'customerId' => (int) $customer->getKey(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        Cache::put($this->key($customer), $selection, now()->addMinutes(self::TTL_MINUTES));

        return $selection;
    }

    /** @return array<string, mixed>|null */
    public function get(Customer $customer): ?array
    {
        $state = Cache::get($this->key($customer));
        if (! is_array($state)
            || (int) ($state['customerId'] ?? 0) !== (int) $customer->getKey()
            || ! is_string($state['nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{16}\z/D', (string) $state['nonce']) !== 1
            || ! in_array($state['source'] ?? null, ['cart', 'detail'], true)
            || ! is_int($state['messageId'] ?? null)
            || $state['messageId'] < 1
        ) {
            if ($state !== null) {
                $this->forget($customer);
            }

            return null;
        }

        return $state;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    public function put(Customer $customer, array $state): void
    {
        Cache::put($this->key($customer), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(Customer $customer): string
    {
        return 'telegram:binance-rail-selector:'.$customer->telegram_id;
    }
}
