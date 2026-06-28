<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class TelegramUpdateProcessor
{
    public function __construct(
        private TelegramCustomerResolver $customers,
        private TelegramMessageRouter $messages,
        private TelegramCallbackRouter $callbacks,
    ) {}

    public function process(TelegramUpdate $update): void
    {
        if ($update->processed_at) {
            return;
        }

        $lock = Cache::lock('telegram-update:'.$update->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $update->refresh();
            if ($update->processed_at) {
                return;
            }

            $payload = $update->payload;
            if ($message = Arr::get($payload, 'message')) {
                $customer = $this->customers->resolve(Arr::get($message, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->messages->route($customer, (string) Arr::get($message, 'chat.id'), $message);
            } elseif ($callback = Arr::get($payload, 'callback_query')) {
                $customer = $this->customers->resolve(Arr::get($callback, 'from', []));
                $update->update(['customer_id' => $customer->id]);
                $this->callbacks->route($customer, (string) Arr::get($callback, 'message.chat.id'), (string) Arr::get($callback, 'id'), (string) Arr::get($callback, 'data'));
            }

            $update->update(['processed_at' => now()]);
        } finally {
            $lock->release();
        }
    }
}
