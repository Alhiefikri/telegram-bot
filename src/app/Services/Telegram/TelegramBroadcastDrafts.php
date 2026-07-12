<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class TelegramBroadcastDrafts
{
    private const TTL_MINUTES = 15;

    /** @param array{message: string, product_id: ?int} $draft */
    public function put(Customer $customer, array $draft): void
    {
        Cache::put($this->key($customer), $draft, now()->addMinutes(self::TTL_MINUTES));
    }

    /** @return array{message: string, product_id: ?int}|null */
    public function get(Customer $customer): ?array
    {
        $draft = Cache::get($this->key($customer));

        return is_array($draft) ? $draft : null;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    /** @return array{message: string, product_id: ?int}|null */
    public function consume(Customer $customer): ?array
    {
        $lock = Cache::lock($this->key($customer).':lock', 10);
        if (! $lock->get()) {
            return null;
        }

        try {
            $draft = Cache::pull($this->key($customer));

            return is_array($draft) ? $draft : null;
        } finally {
            $lock->release();
        }
    }

    private function key(Customer $customer): string
    {
        return 'telegram:broadcast-draft:'.$customer->telegram_id;
    }
}
