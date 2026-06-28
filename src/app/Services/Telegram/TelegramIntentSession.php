<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class TelegramIntentSession
{
    public function key(Customer $customer): string
    {
        return 'telegram:selected-product:'.$customer->telegram_id;
    }

    public function getSelectedProductId(Customer $customer): mixed
    {
        return Cache::get($this->key($customer));
    }

    public function put(Customer $customer, int $productId): void
    {
        Cache::put($this->key($customer), $productId, now()->addMinutes(15));
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }
}
