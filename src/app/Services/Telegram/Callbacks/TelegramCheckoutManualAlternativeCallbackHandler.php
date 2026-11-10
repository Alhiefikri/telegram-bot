<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramOutboundEffect;
use App\Services\Checkout\CheckoutManualAlternative;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

final class TelegramCheckoutManualAlternativeCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly CheckoutManualAlternative $alternatives,
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return str_starts_with($context->data, 'checkout-manual:');
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (! $context->isPrivate() || $context->chatId !== (string) $context->customer->telegram_id
            || preg_match('/^checkout-manual:(ask|confirm|back|check):([a-f0-9-]{36})$/D', $context->data, $matches) !== 1) {
            $this->answer($context, 'unavailable');

            return true;
        }
        $payment = Payment::query()->with('order')->find($matches[2]);
        if ($payment === null || (string) $payment->order?->customer_id !== (string) $context->customer->id) {
            $this->answer($context, 'unavailable');

            return true;
        }
        $key = 'telegram:checkout-manual:'.$context->customer->id.':'.$payment->id;
        try {
            Cache::lock($key.':lock', 60)->block(1, function () use ($context, $payment, $matches, $key): void {
                $state = Cache::get($key);
                if ($matches[1] === 'ask') {
                    if (! $this->matchesSource($context, $payment->order) || ! $this->alternatives->isEligible($payment->order, $payment)) {
                        $this->answer($context, 'unavailable');

                        return;
                    }
                    if (is_array($state)) {
                        $this->bot->answerCallbackQuery($context->callbackQueryId);

                        return;
                    }
                    $response = $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'payment.manual_alternative.confirmation'), [
                        'inline_keyboard' => [
                            [['text' => $this->locale->translate($context->customer, 'payment.manual_alternative.confirm'), 'callback_data' => 'checkout-manual:confirm:'.$payment->id]],
                            [['text' => $this->locale->translate($context->customer, 'payment.manual_alternative.back'), 'callback_data' => 'checkout-manual:back:'.$payment->id],
                                ['text' => $this->locale->translate($context->customer, 'payment.manual_alternative.check'), 'callback_data' => 'checkout-manual:check:'.$payment->id]],
                        ],
                    ]);
                    $messageId = data_get($response, 'result.message_id');
                    if (! is_numeric($messageId) || (int) $messageId < 1 || ! Cache::put($key, [
                        'sourceMessageId' => $context->messageId,
                        'confirmationMessageId' => (int) $messageId,
                    ], now()->addMinutes(5))) {
                        throw new \RuntimeException('Manual checkout confirmation could not be bound.');
                    }
                    $this->bot->answerCallbackQuery($context->callbackQueryId);

                    return;
                }
                if (! is_array($state) || $context->messageId !== ($state['confirmationMessageId'] ?? null)) {
                    $this->answer($context, 'unavailable');

                    return;
                }
                if ($matches[1] === 'back') {
                    $this->bot->deleteMessage($context->chatId, $context->messageId);
                    Cache::forget($key);
                    $this->bot->answerCallbackQuery($context->callbackQueryId);

                    return;
                }
                if ($matches[1] === 'check') {
                    $status = $this->alternatives->recheck($payment);
                    $this->answer($context, $status === 'paid' ? 'paid' : 'blocked');

                    return;
                }
                $source = new TelegramCallbackContext($context->customer, $context->chatId, $context->callbackQueryId, '', 'private', $state['sourceMessageId']);
                if (! $this->matchesSource($source, $payment->order->fresh())) {
                    $this->answer($context, 'unavailable');

                    return;
                }
                $result = $this->alternatives->switch($context->customer, $payment, function (Order $order) use ($context, $state): void {
                    try {
                        $this->bot->deleteMessage($context->chatId, $state['sourceMessageId']);
                    } catch (RequestException $exception) {
                        if (! str_contains(strtolower((string) $exception->response->json('description')), 'message to delete not found')) {
                            throw $exception;
                        }
                    }
                });
                if (isset($result['order'], $result['payment'])) {
                    $effect = $this->effects->recordManualCheckout($result['order'], $result['payment']);
                    Cache::forget($key);
                    if ($effect !== null) {
                        $this->dispatcher->dispatch($effect);
                    }
                    $this->bot->editMessageText($context->chatId, $context->messageId, $this->locale->translate($context->customer, 'payment.manual_alternative.switched'));
                    $this->bot->answerCallbackQuery($context->callbackQueryId);

                    return;
                }
                $this->answer($context, match ($result['status']) {
                    'paid' => 'paid',
                    'unknown' => 'blocked',
                    default => 'unavailable',
                });
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->answer($context, 'blocked');
        }

        return true;
    }

    private function matchesSource(TelegramCallbackContext $context, Order $order): bool
    {
        if ($context->messageId === null || $order->manual_checkout_customer_notification_deleted_at !== null) {
            return false;
        }
        if ((string) $order->manual_checkout_customer_notification_chat_id === $context->chatId
            && (int) $order->manual_checkout_customer_notification_message_id === $context->messageId) {
            return true;
        }

        return TelegramOutboundEffect::query()->where('source_type', Order::class)->where('source_id', $order->id)
            ->whereIn('effect_type', TelegramOutboundEffectService::CHECKOUT_EFFECT_TYPES)
            ->where('state', 'sent')->where('telegram_chat_id', $context->chatId)
            ->where('telegram_message_id', (string) $context->messageId)->exists();
    }

    private function answer(TelegramCallbackContext $context, string $key): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'payment.manual_alternative.'.$key));
    }
}
