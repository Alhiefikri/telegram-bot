<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

final class TelegramStartIntentSession
{
    private const TTL_MINUTES = 15;

    public function key(Customer $customer): string
    {
        return 'telegram:start-intent:'.$customer->telegram_id;
    }

    /**
     * @param  array{type: 'product'|'family', id: int}  $intent
     */
    public function put(Customer $customer, array $intent): void
    {
        if (! in_array($intent['type'] ?? null, ['product', 'family'], true)
            || ! is_int($intent['id'] ?? null)
            || $intent['id'] < 1) {
            return;
        }

        Cache::put($this->key($customer), $intent, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * @return array{type: 'product'|'family', id: int}|null
     */
    public function get(Customer $customer): ?array
    {
        return $this->normalize(Cache::get($this->key($customer)));
    }

    /**
     * @return array{type: 'product'|'family', id: int}|null
     */
    public function pull(Customer $customer): ?array
    {
        return Cache::lock($this->key($customer).':lock', 15)->block(5, function () use ($customer): ?array {
            return $this->normalize(Cache::pull($this->key($customer)));
        });
    }

    /** @return array{type: 'product'|'family', id: int}|null */
    private function normalize(mixed $intent): ?array
    {
        if (! is_array($intent)
            || ! in_array($intent['type'] ?? null, ['product', 'family'], true)
            || ! is_int($intent['id'] ?? null)
            || $intent['id'] < 1) {
            return null;
        }

        return ['type' => $intent['type'], 'id' => $intent['id']];
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }
}
