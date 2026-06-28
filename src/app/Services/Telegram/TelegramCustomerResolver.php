<?php

namespace App\Services\Telegram;

use App\Models\Customer;

class TelegramCustomerResolver
{
    public function resolve(array $from): Customer
    {
        return Customer::updateOrCreate(
            ['telegram_id' => (int) $from['id']],
            ['first_name' => $from['first_name'] ?? 'Telegram Customer', 'username' => $from['username'] ?? null],
        );
    }
}
