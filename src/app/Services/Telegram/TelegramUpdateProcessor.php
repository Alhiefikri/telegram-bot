<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use Illuminate\Support\Arr;

class TelegramUpdateProcessor
{
    public function __construct(
        private TelegramUpdateReplayGuard $replayGuard,
        private TelegramUpdateShapeValidator $updateShapes,
        private TelegramCustomerResolver $customers,
        private TelegramMessageRouter $messages,
        private TelegramCallbackRouter $callbacks,
    ) {}

    public function process(TelegramUpdate $update): void
    {
        $this->replayGuard->processOnce($update, fn (TelegramUpdate $claimedUpdate) => $this->route($claimedUpdate));
    }

    private function route(TelegramUpdate $update): void
    {
        $payload = $update->payload;
        $type = $this->updateShapes->supportedType($payload);
        if ($type === TelegramUpdateShapeValidator::TYPE_MESSAGE) {
            $message = $payload['message'];
            $customer = $this->customers->resolve(Arr::get($message, 'from', []));
            $update->update(['customer_id' => $customer->id]);
            $this->messages->route($customer, (string) Arr::get($message, 'chat.id'), $message, (string) Arr::get($message, 'chat.type', 'private'));
        } elseif ($type === TelegramUpdateShapeValidator::TYPE_CALLBACK_QUERY) {
            $callback = $payload['callback_query'];
            $customer = $this->customers->resolve(Arr::get($callback, 'from', []));
            $update->update(['customer_id' => $customer->id]);
            $this->callbacks->route($customer, (string) Arr::get($callback, 'message.chat.id'), (string) Arr::get($callback, 'id'), (string) Arr::get($callback, 'data'), (string) Arr::get($callback, 'message.chat.type', 'private'));
        }
    }
}
