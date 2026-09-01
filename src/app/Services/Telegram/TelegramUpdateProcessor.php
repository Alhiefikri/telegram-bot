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
        private TelegramCallbackAckState $callbackAckState,
        private TelegramChatInteractionLock $interactionLock,
    ) {}

    public function process(TelegramUpdate $update): void
    {
        $this->callbackAckState->reset();
        $this->replayGuard->processOnce($update, fn (TelegramUpdate $claimedUpdate) => $this->route($claimedUpdate));
    }

    private function route(TelegramUpdate $update): void
    {
        $payload = $update->payload;
        $type = $this->updateShapes->supportedType($payload);
        if ($type === TelegramUpdateShapeValidator::TYPE_MESSAGE) {
            $message = $payload['message'];
            $customer = $this->customers->resolve(Arr::get($message, 'from', []));
            $chatId = (string) Arr::get($message, 'chat.id');
            $update->update(['customer_id' => $customer->id]);
            $this->interactionLock->withLock(
                $chatId,
                fn () => $this->messages->route($customer, $chatId, $message, (string) Arr::get($message, 'chat.type', 'private')),
            );
        } elseif ($type === TelegramUpdateShapeValidator::TYPE_CALLBACK_QUERY) {
            $callback = $payload['callback_query'];
            if ((bool) Arr::get($callback, '_acknowledged_via_webhook', false)) {
                $this->callbackAckState->acknowledge((string) Arr::get($callback, 'id'));
            }
            $customer = $this->customers->resolve(Arr::get($callback, 'from', []));
            $chatId = (string) Arr::get($callback, 'message.chat.id');
            $update->update(['customer_id' => $customer->id]);
            $this->interactionLock->withLock(
                $chatId,
                fn () => $this->callbacks->route($customer, $chatId, (string) Arr::get($callback, 'id'), (string) Arr::get($callback, 'data'), (string) Arr::get($callback, 'message.chat.type', 'private'), Arr::get($callback, 'message.message_id')),
            );
        }
    }
}
