<?php

namespace App\Services\Telegram;

use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\TelegramStarsCheckoutIntent;
use App\Services\Checkout\TelegramStarsCheckoutPaymentPreparation;
use App\Services\Checkout\TelegramStarsCheckoutQuote;
use App\Services\Checkout\TelegramStarsConfirmation;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\OrderCheckoutService;
use App\Services\TelegramStars\TelegramStarsChargeIdentity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TelegramStarsCheckout
{
    private const TERMS_VERSION = 'telegram-stars-v1';

    private const CALLBACK_TTL_MINUTES = 15;

    public function __construct(
        private TelegramStarsCheckoutPaymentPreparation $preparation,
        private TelegramIntentSession $selection,
        private CartService $carts,
        private CheckoutMaintenance $maintenance,
        private OrderCheckoutService $checkout,
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramUiIcons $icons,
        private TelegramOrderIntents $orders,
    ) {}

    /** A short opaque pointer to the displayed quote, never an amount or a purchase instruction. */
    public function offer(Customer $customer, string $chatId, Cart|Product $source, string $revision, ?int $quantity = null): ?array
    {
        if ($chatId !== (string) $customer->telegram_id || $customer->telegram_id < 1
            || TelegramStarsChargeIdentity::configuredNamespace() === null) {
            return null;
        }

        try {
            $quote = $this->preparation->preview($source, $this->confirmation($customer, $chatId, $revision, 'preview'), $quantity);
        } catch (CheckoutPaymentUnavailable|InvalidCheckout|InsufficientStock) {
            return null;
        }

        $nonce = bin2hex(random_bytes(12));
        $state = [
            'cart' => $quote->cartId,
            'product' => $source instanceof Product ? (int) $source->getKey() : null,
            'revision' => $revision,
            'snapshot' => $this->snapshot($quote),
            'expires_at' => $quote->expiresAt->timestamp,
        ];

        try {
            if (! Cache::put($this->key($customer, $nonce), $state, now()->addMinutes(self::CALLBACK_TTL_MINUTES))) {
                return null;
            }
        } catch (Throwable $exception) {
            Log::warning('telegram.stars_checkout_cache_unavailable', ['error_class' => $exception::class]);

            return null;
        }

        return $this->button($customer, 'stars.pay', 'stars:open:'.$nonce, ['amount' => $quote->totalXtr]);
    }

    public function bindOffer(Customer $customer, array $button, ?int $messageId): void
    {
        if ($messageId < 1) {
            return;
        }
        try {
            $key = $this->key($customer, substr($button['callback_data'], strlen('stars:open:')));
            $state = Cache::get($key);
            if (is_array($state)) {
                $state['origin'] = $messageId;
                Cache::put($key, $state, now()->addMinutes(self::CALLBACK_TTL_MINUTES));
            }
        } catch (Throwable $exception) {
            Log::warning('telegram.stars_checkout_cache_unavailable', ['error_class' => $exception::class]);
        }
    }

    public function handle(TelegramCallbackContext $context, string $action, string $nonce, callable $checkoutKey, callable $withCheckoutLock): void
    {
        $customer = $context->customer;
        if (! $context->isPrivate() || $context->chatId !== (string) $customer->telegram_id || $context->messageId < 1) {
            $this->reject($context, 'stars.stale');

            return;
        }
        $key = $this->key($customer, $nonce);
        $state = Cache::get($key);
        $messageField = $action === 'open' ? 'origin' : 'review';
        if (! is_array($state) || ($state[$messageField] ?? null) !== $context->messageId) {
            $this->reject($context, 'stars.stale');

            return;
        }

        $attempt = $checkoutKey($state['origin'], $state['product'], $state['revision']);
        $withCheckoutLock($attempt, function () use ($context, $action, $key, $messageField, $attempt): void {
            $current = Cache::get($key);
            if (! is_array($current) || ($current[$messageField] ?? null) !== $context->messageId) {
                $this->reject($context, 'stars.stale');

                return;
            }

            if ($action === 'back') {
                Cache::forget($key);
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                // Only remove our separate review message, not the navigation root.
                $this->bot->deleteMessage($context->chatId, $context->messageId);

                return;
            }

            $existing = $context->customer->orders()->where('checkout_key', $attempt)->first();
            if ($existing !== null) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->orders->sendOrderDetail($context->customer, $context->chatId, $existing->invoice_number);

                return;
            }

            $result = $this->selection->withLock($context->customer, fn () => $this->resolve($context, $action, $current, $attempt));
            if (is_string($result)) {
                $this->reject($context, $result);

                return;
            }
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            if (isset($result['order'])) {
                $this->orders->sendOrderDetail($context->customer, $context->chatId, $result['order']->invoice_number);

                return;
            }

            // Rotate the consent callback when the terms or quote change. An
            // in-flight click on the old price can never accept the new one.
            $this->sendReview($context, $key, $current, $result['quote'], $action, $result['notice']);
        });
    }

    private function resolve(TelegramCallbackContext $context, string $action, array $state, string $attempt): array|string
    {
        $customer = $context->customer;
        $quantity = null;
        if ($state['product'] !== null) {
            $selection = $this->selection->get($customer);
            if ($selection === null || $selection['mode'] !== 'browsing' || $selection['productId'] !== $state['product']
                || $selection['interactionToken'] !== $state['revision']) {
                return 'stars.stale';
            }
            $source = Product::query()->publicVisible()->find($state['product']);
            $quantity = $selection['quantity'];
        } else {
            $source = $this->carts->currentActiveCartFor($customer);
            if ($source?->getKey() !== $state['cart']) {
                return 'stars.stale';
            }
        }
        if ($source === null || TelegramStarsChargeIdentity::configuredNamespace() === null || $this->maintenance->current()->active) {
            return 'stars.unavailable';
        }

        $confirmation = $this->confirmation($customer, $context->chatId, $state['revision'], $attempt);
        try {
            $quote = $this->preparation->preview($source, $confirmation, $quantity);
            if ($state['expires_at'] <= now()->timestamp) {
                return ['quote' => $quote, 'notice' => 'stars.quote_expired'];
            }
            $changed = $state['snapshot'] !== $this->snapshot($quote);
            if ($action === 'open' || $changed) {
                return ['quote' => $quote, 'notice' => $changed ? 'stars.changed' : null];
            }

            // Re-reading the price is not a renewal of the consent window.
            $reviewedQuote = $quote->expiringAt(CarbonImmutable::createFromTimestamp($state['expires_at']));
            $intent = new TelegramStarsCheckoutIntent($reviewedQuote, $confirmation);
            $acceptedAt = now();
            $order = $source instanceof Cart
                ? $this->checkout->checkout($source, $intent, checkoutKey: $attempt, termsVersion: self::TERMS_VERSION, termsAcceptedAt: $acceptedAt)
                : $this->checkout->checkoutTelegramStarsDirect($source, $quantity, $intent, self::TERMS_VERSION, $acceptedAt);

            return ['order' => $order];
        } catch (CheckoutPaymentUnavailable $exception) {
            if ($exception->reason === 'snapshot_changed') {
                try {
                    return ['quote' => $this->preparation->preview($source, $confirmation, $quantity), 'notice' => 'stars.changed'];
                } catch (CheckoutPaymentUnavailable|InvalidCheckout|InsufficientStock) {
                    return 'stars.unavailable';
                }
            }

            return 'stars.unavailable';
        } catch (CheckoutMaintenanceActive|InvalidCheckout|InvalidCartState|InsufficientStock) {
            return 'stars.unavailable';
        }
    }

    private function sendReview(TelegramCallbackContext $context, string $oldKey, array $state, TelegramStarsCheckoutQuote $quote, string $action, ?string $notice): void
    {
        $customer = $context->customer;
        $nonce = bin2hex(random_bytes(12));
        $visibleLines = array_slice($quote->retailLines, 0, 20);
        $names = Product::query()->whereIn('id', array_column($visibleLines, 'product_id'))->pluck('name', 'id');
        $lines = [$this->locale->translate($customer, 'stars.review')];
        foreach ($visibleLines as $line) {
            $name = preg_replace('/\s+/u', ' ', (string) ($names[$line['product_id']] ?? ''));
            $lines[] = mb_strimwidth($name, 0, 80, '…').' × '.$line['quantity']
                .($line['tier_minimum'] === null ? '' : ' · '.$this->locale->translate($customer, 'stars.tier', ['quantity' => $line['tier_minimum']]));
        }
        if (count($quote->retailLines) > 20) {
            $lines[] = $this->locale->translate($customer, 'order.status.more_items', ['count' => count($quote->retailLines) - 20]);
        }
        $lines[] = '';
        if ($notice !== null) {
            $lines[] = $this->locale->translate($customer, $notice);
        }
        $lines[] = 'Telegram Stars · '.Money::xtr($quote->totalXtr);
        $lines[] = $this->locale->translate($customer, 'stars.terms');
        if ($action === 'confirm') {
            Cache::forget($oldKey);
        }
        $response = $this->bot->editMessageTextOrSend($context->chatId, implode("\n", $lines), ['inline_keyboard' => [
            [$this->button($customer, 'stars.accept', 'stars:confirm:'.$nonce, ['amount' => $quote->totalXtr])],
            [[...$this->icons->apply('menu.back', $this->locale->translate($customer, 'stars.back')), 'callback_data' => 'stars:back:'.$nonce, 'style' => 'primary']],
        ]], $action === 'confirm' ? $context->messageId : null);
        $id = data_get($response, 'result.message_id');
        if (($response['ok'] ?? false) === true && is_int($id) && $id > 0) {
            $state['review'] = $id;
            $state['snapshot'] = $this->snapshot($quote);
            $state['expires_at'] = $quote->expiresAt->timestamp;
            Cache::put($this->key($customer, $nonce), $state, now()->addMinutes(self::CALLBACK_TTL_MINUTES));
        }
    }

    private function button(Customer $customer, string $label, string $callback, array $replace): array
    {
        return [
            ...$this->icons->apply('payment.stars', '⭐ '.$this->locale->translate($customer, $label, $replace), preserveFallbackOnRejection: true),
            'callback_data' => $callback,
            'style' => 'primary',
        ];
    }

    private function snapshot(TelegramStarsCheckoutQuote $quote): array
    {
        return [$quote->selectionFingerprint, $quote->policy->values(), $quote->totalXtr, self::TERMS_VERSION, TelegramStarsChargeIdentity::configuredNamespace()];
    }

    private function key(Customer $customer, string $nonce): string
    {
        return 'telegram:stars-checkout:'.$customer->getKey().':'.$nonce;
    }

    private function confirmation(Customer $customer, string $chatId, string $revision, string $attempt): TelegramStarsConfirmation
    {
        return new TelegramStarsConfirmation((int) $customer->getKey(), $customer->telegram_id, (int) $chatId, 'private', $revision, $attempt);
    }

    private function reject(TelegramCallbackContext $context, string $reason): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, $reason));
    }
}
