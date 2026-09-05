<?php

namespace App\Services\Telegram\Callbacks;

use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramBinanceRailSelectionSession;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCartIntents;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramManualPaymentIntents;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class TelegramCheckoutCallbackHandler implements TelegramCallbackHandler
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private OrderCheckoutService $checkout,
        private TelegramCartIntents $cart,
        private TelegramCatalogIntents $catalog,
        private TelegramCheckoutIntents $checkoutIntents,
        private TelegramManualPaymentIntents $manualPayments,
        private TelegramCheckoutPaymentIntentSelector $paymentIntentSelector,
        private TelegramIntentSession $session,
        private TelegramBinanceRailSelectionSession $railSession,
        private TelegramLocale $locale,
        private CheckoutMaintenance $maintenance,
        private TelegramOutboundEffectService $outboundEffects,
        private TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        return $context->data === 'checkout'
            || $context->data === 'checkout:current'
            || $context->data === 'checkout:binance'
            || preg_match('/^checkout:binance:(?:rail:)?(binance_pay_id|usdt_bep20):[a-f0-9]{16}$/', $context->data) === 1
            || preg_match('/^checkout:binance:cancel:[a-f0-9]{16}$/', $context->data) === 1
            || preg_match('/^manual:paid:([0-9a-fA-F-]+)$/', $context->data) === 1
            || preg_match('/^detail:pay:(qris|binance):([1-9][0-9]*):[A-Za-z0-9_-]+$/', $context->data) === 1;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match('/^manual:paid:([0-9a-fA-F-]+)$/', $context->data, $matches) === 1) {
            $this->showManualPaymentProofInstructions($context, $matches[1]);

            return true;
        }
        if ($context->data === 'checkout') {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->choosePaymentMethod($context, $checkoutKey));

            return true;
        }

        if ($context->data === 'checkout:binance') {
            $this->withCheckoutIntentLock(
                $context,
                $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, 'checkout:binance-selector'),
                fn () => $this->openCartBinanceSelector($context),
            );

            return true;
        }

        if ($context->data === 'checkout:current') {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->checkout($context, 'qris', true, $checkoutKey));

            return true;
        }

        if (preg_match('/^checkout:binance:(?:rail:)?(binance_pay_id|usdt_bep20):([a-f0-9]{16})$/', $context->data, $matches) === 1) {
            $rail = $matches[1];
            $nonce = $matches[2];
            $state = $this->railSession->get($context->customer);
            $checkoutKey = $state === null ? null : $this->selectorCheckoutKey($context->customer, $state, $rail);
            $this->withCheckoutIntentLock(
                $context,
                $checkoutKey,
                fn () => $this->handleBinanceRailSelection($context, $rail, $nonce, $checkoutKey),
            );

            return true;
        }

        if (preg_match('/^checkout:binance:cancel:([a-f0-9]{16})$/', $context->data, $matches) === 1) {
            $this->cancelBinanceRailSelection($context, $matches[1]);

            return true;
        }

        if (preg_match('/^detail:pay:(qris|binance):([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $context->data, $matches) === 1) {
            $paymentMethod = $matches[1];
            $productId = (int) $matches[2];
            $token = $matches[3];
            $checkoutKey = $this->detailCheckoutIntentKey($context->customer, $context->chatId, $context->messageId, $productId, $token);
            if ($paymentMethod === 'binance') {
                $this->withCheckoutIntentLock(
                    $context,
                    $checkoutKey,
                    fn () => $this->openDetailBinanceSelector($context, $productId, $token),
                );
            } else {
                $this->withCheckoutIntentLock(
                    $context,
                    $checkoutKey,
                    fn () => $this->checkoutProductDetail($context, false, $productId, $token, $checkoutKey),
                );
            }

            return true;
        }

        return false;
    }

    private function openCartBinanceSelector(TelegramCallbackContext $context): void
    {
        if ($context->messageId === null || $context->messageId < 1) {
            $this->sendRailSelectionStale($context);

            return;
        }

        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->bot->sendMessage(
                $context->chatId,
                $this->maintenanceMessage($context->customer, $maintenance),
                $this->maintenanceKeyboard($context->customer),
            );

            return;
        }

        $cart = $this->carts->currentActiveCartFor($context->customer);
        $cart?->load('cartItems.product');
        if ($cart === null || $cart->cartItems->isEmpty() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'cart.binance_unavailable_cart'));

            return;
        }

        if (! $this->checkoutIntents->binanceRailSelectable()) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'cart.binance_unavailable_cart'));

            return;
        }

        $state = $this->railSession->start($context->customer, [
            'source' => 'cart',
            'messageId' => (int) $context->messageId,
            'chatId' => $context->chatId,
            'cartId' => (int) $cart->getKey(),
            'cartFingerprint' => $this->cartFingerprint($cart),
            'locale' => $this->locale->code($context->customer),
        ]);
        $this->sendRailSelector($context, $state);
    }

    private function openDetailBinanceSelector(TelegramCallbackContext $context, int $productId, string $token): void
    {
        if ($context->messageId === null || $context->messageId < 1) {
            $this->sendRailSelectionStale($context);

            return;
        }

        $selection = $this->session->get($context->customer);
        $product = Product::query()->withAvailability()->publicVisible()->whereKey($productId)->first();
        if ($selection === null
            || $selection['mode'] !== 'browsing'
            || (int) $selection['productId'] !== $productId
            || ! hash_equals((string) $selection['interactionToken'], $token)
            || $product === null
            || max(0, $product->availableStock()) < (int) $selection['quantity']
            || $product->usdt_price_amount === null
            || ! $this->checkoutIntents->binanceRailSelectable()) {
            $this->session->forget($context->customer);
            $this->sendRailSelectionStale($context);

            return;
        }

        $state = $this->railSession->start($context->customer, [
            'source' => 'detail',
            'messageId' => (int) $context->messageId,
            'chatId' => $context->chatId,
            'productId' => $productId,
            'quantity' => (int) $selection['quantity'],
            'interactionToken' => $token,
            'locale' => $this->locale->code($context->customer),
        ]);
        $this->sendRailSelector($context, $state);
    }

    /** @param array<string, mixed> $state */
    private function sendRailSelector(TelegramCallbackContext $context, array $state): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $buttons = [];
        if ($this->checkoutIntents->binancePayEnabled()) {
            $buttons[] = [[
                'text' => $this->locale->translate($context->customer, 'checkout.payment.binance_pay_id'),
                'callback_data' => 'checkout:binance:rail:binance_pay_id:'.$state['nonce'],
            ]];
        }
        if ($this->checkoutIntents->bscRailSelectable()) {
            $buttons[] = [[
                'text' => $this->locale->translate($context->customer, 'checkout.payment.usdt_bep20'),
                'callback_data' => 'checkout:binance:rail:usdt_bep20:'.$state['nonce'],
            ]];
        }
        $buttons[] = [[
            'text' => $this->locale->translate($context->customer, 'checkout.payment.cancel'),
            'callback_data' => 'checkout:binance:cancel:'.$state['nonce'],
        ]];

        $response = $this->bot->sendMessage(
            $context->chatId,
            $this->locale->translate($context->customer, 'checkout.payment.binance_selector'),
            ['inline_keyboard' => $buttons],
        );
        $selectorMessageId = data_get($response, 'result.message_id');
        if (is_numeric($selectorMessageId) && (int) $selectorMessageId > 0) {
            $this->railSession->put($context->customer, [
                ...$state,
                'selectorMessageId' => (int) $selectorMessageId,
            ]);
        } else {
            $this->railSession->forget($context->customer);
        }
    }

    private function handleBinanceRailSelection(TelegramCallbackContext $context, string $rail, string $nonce, ?string $checkoutKey): void
    {
        $state = $this->railSession->get($context->customer);
        if ($state === null
            || ! hash_equals((string) $state['nonce'], $nonce)
            || (string) ($state['chatId'] ?? $context->chatId) !== $context->chatId
            || ! isset($state['selectorMessageId'])
            || (int) $state['selectorMessageId'] !== (int) $context->messageId
            || ! CheckoutPaymentIntents::isBinancePaymentRail($rail)) {
            $this->sendRailSelectionStale($context);

            return;
        }

        if ($state['source'] === 'cart') {
            $cart = $this->carts->currentActiveCartFor($context->customer);
            $cart?->load('cartItems.product');
            if ($cart === null
                || (int) $cart->getKey() !== (int) ($state['cartId'] ?? 0)
                || $this->cartFingerprint($cart) !== (string) ($state['cartFingerprint'] ?? '')) {
                $this->railSession->forget($context->customer);
                $this->sendRailSelectionStale($context);

                return;
            }

            $this->railSession->forget($context->customer);
            $this->checkout($context, $rail, false, $checkoutKey);

            return;
        }

        $productId = (int) ($state['productId'] ?? 0);
        $token = (string) ($state['interactionToken'] ?? '');
        $this->session->withLock($context->customer, function () use ($context, $rail, $checkoutKey, $productId, $token): void {
            $selection = $this->session->get($context->customer);
            $product = Product::query()->withAvailability()->publicVisible()->whereKey($productId)->first();
            if ($selection === null
                || $selection['mode'] !== 'browsing'
                || (int) $selection['productId'] !== $productId
                || ! hash_equals((string) $selection['interactionToken'], $token)
                || $product === null
                || max(0, $product->availableStock()) < (int) $selection['quantity']) {
                $this->railSession->forget($context->customer);
                $this->sendRailSelectionStale($context);

                return;
            }

            $added = $this->cart->addProductToCart(
                $context->customer,
                $context->chatId,
                $productId,
                (int) $selection['quantity'],
                true,
                false,
            );
            if (! $added) {
                $this->railSession->forget($context->customer);
                $this->sendSelectionUnavailable($context);

                return;
            }

            $this->session->forget($context->customer);
            $this->railSession->forget($context->customer);
            $this->checkout($context, $rail, false, $checkoutKey);
        });
    }

    private function cancelBinanceRailSelection(TelegramCallbackContext $context, string $nonce): void
    {
        $state = $this->railSession->get($context->customer);
        if ($state === null
            || ! hash_equals((string) $state['nonce'], $nonce)
            || ! isset($state['selectorMessageId'])
            || (int) $state['selectorMessageId'] !== (int) $context->messageId) {
            $this->sendRailSelectionStale($context);

            return;
        }

        $this->railSession->forget($context->customer);
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        if ($state['source'] === 'cart') {
            $this->choosePaymentMethod($context);

            return;
        }

        $selection = $this->session->get($context->customer);
        $product = Product::query()->withAvailability()->publicVisible()->whereKey((int) ($state['productId'] ?? 0))->first();
        if ($selection !== null && $product !== null) {
            $this->catalog->renderProductDetail($context->customer, $context->chatId, $product, $selection);

            return;
        }

        $this->sendRailSelectionStale($context);
    }

    private function sendRailSelectionStale(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'checkout.payment.selector_stale'),
        );
    }

    private function cartFingerprint($cart): string
    {
        return hash('sha256', $cart->cartItems
            ->map(fn ($item): array => [(int) $item->product_id, (int) $item->quantity])
            ->sortBy(fn (array $item): int => $item[0])
            ->values()
            ->toJson());
    }

    /** @param array<string, mixed> $state */
    private function selectorCheckoutKey(Customer $customer, array $state, string $rail): string
    {
        return hash_hmac('sha256', implode('|', [
            'telegram-binance-rail',
            $customer->getKey(),
            $state['source'],
            $state['messageId'],
            $state['cartId'] ?? $state['productId'] ?? 'none',
            $state['interactionToken'] ?? 'none',
            $rail,
        ]), (string) config('app.key'));
    }

    private function checkoutProductDetail(TelegramCallbackContext $context, bool $binance, int $productId, string $token, string $checkoutKey): void
    {
        if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return;
        }

        $this->session->withLock($context->customer, function () use ($context, $binance, $productId, $token, $checkoutKey): void {
            if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);

                return;
            }

            $selection = $this->session->get($context->customer);
            if ($selection === null
                || $selection['mode'] !== 'browsing'
                || (int) $selection['productId'] !== $productId
                || ! hash_equals((string) $selection['interactionToken'], $token)
            ) {
                $this->sendSelectionStale($context);

                return;
            }

            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->bot->sendMessage(
                    $context->chatId,
                    $this->maintenanceMessage($context->customer, $maintenance),
                    $this->maintenanceKeyboard($context->customer),
                );

                return;
            }

            $product = Product::query()->withAvailability()->publicVisible()->whereKey($productId)->first();
            $availableStock = $product ? max(0, $product->availableStock()) : 0;
            if (! $product || $availableStock < (int) $selection['quantity']) {
                $this->session->forget($context->customer);
                $this->sendSelectionUnavailable($context);

                return;
            }

            if ($binance && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'cart.binance_unavailable_product'));

                return;
            }

            // Resolve the rail before mutating the active Cart. The checkout
            // service remains the authoritative second check inside its transaction.
            $this->paymentIntentSelector->select($binance);

            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $added = $this->cart->addProductToCart(
                $context->customer,
                $context->chatId,
                $productId,
                (int) $selection['quantity'],
                true,
                false,
            );
            if (! $added) {
                $this->session->forget($context->customer);

                return;
            }

            $this->session->forget($context->customer);
            $this->checkout($context, $binance, false, $checkoutKey);
        });
    }

    private function choosePaymentMethod(TelegramCallbackContext $context, ?string $checkoutKey = null): void
    {

        if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return;
        }

        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->bot->sendMessage(
                $context->chatId,
                $this->maintenanceMessage($context->customer, $maintenance),
                $this->maintenanceKeyboard($context->customer),
            );

            return;
        }
        $cart = $this->carts->currentActiveCartFor($context->customer);
        if (! $cart) {
            $this->checkout($context, false, true, $checkoutKey);

            return;
        }
        $cart->load('cartItems.product');
        if (! $this->checkoutIntents->binanceRailSelectable() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
            $this->checkout($context, false, true, $checkoutKey);

            return;
        }
        $micro = $cart->cartItems->sum(fn ($item) => $item->product->usdt_price_amount * $item->quantity);
        $qrisButton = [['text' => $this->locale->translate($context->customer, 'checkout.payment.qris'), 'callback_data' => 'checkout:current']];
        $binanceButton = [['text' => $this->locale->translate($context->customer, 'checkout.payment.binance', ['amount' => $this->checkoutIntents->formatUsdt($micro)]), 'callback_data' => 'checkout:binance']];
        $buttons = [$qrisButton, $binanceButton];
        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $paymentMethodMessage = $this->locale->translate($context->customer, 'checkout.payment_method')
            ."\n\n"
            .$this->locale->qrisCrossBorderSupport($context->customer);
        $this->bot->sendMessage($context->chatId, $paymentMethodMessage, ['inline_keyboard' => $buttons]);
    }

    private function checkout(TelegramCallbackContext $context, string|bool $rail = 'qris', bool $answerCallback = true, ?string $checkoutKey = null): void
    {
        if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
            if ($answerCallback) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);
            }

            return;
        }
        $paymentRail = is_bool($rail)
            ? ($rail ? CheckoutPaymentIntents::RAIL_BINANCE_PAY_ID : CheckoutPaymentIntents::RAIL_QRIS)
            : CheckoutPaymentIntents::normalizePaymentRail($rail);
        $binance = CheckoutPaymentIntents::isBinancePaymentRail($paymentRail);
        $maintenance = $this->maintenance->current();
        if ($maintenance->active) {
            if ($answerCallback) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);
            }
            $this->bot->sendMessage(
                $context->chatId,
                $this->maintenanceMessage($context->customer, $maintenance),
                $this->maintenanceKeyboard($context->customer),
            );

            return;
        }

        if ($answerCallback) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
        }

        try {
            $cart = $this->carts->currentActiveCartFor($context->customer);
            if (! $cart) {
                throw new InvalidCheckout('Cart is empty.');
            }
            if ($binance) {
                $cart->load('cartItems.product');
                $railReady = $paymentRail === CheckoutPaymentIntents::RAIL_BINANCE_PAY_ID
                    ? $this->checkoutIntents->binancePayEnabled()
                    : $this->checkoutIntents->bscRailSelectable();
                if (! $railReady || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
                    $this->bot->sendMessage(
                        $context->chatId,
                        $this->locale->translate($context->customer, 'cart.binance_unavailable_cart'),
                        [
                            'inline_keyboard' => [[[
                                'text' => $this->locale->translate($context->customer, 'checkout.payment.qris'),
                                'callback_data' => 'checkout:current',
                            ]]],
                        ],
                    );

                    return;
                }
            }

            $selection = $this->paymentIntentSelector->select($paymentRail);
            $paymentMode = $selection->paymentMode;
            $paymentIntent = $selection->paymentIntent;
            $this->assertSupportedPostCreationBehavior($paymentIntent->postCreationBehavior());
            $order = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => 'order_checkout',
                'payment_mode' => $paymentMode,
                'customer_correlation_id' => DogfoodTiming::correlation($context->customer->telegram_id),
                'cart_correlation_id' => DogfoodTiming::correlation($cart->id),
            ], fn () => $this->checkout->checkout($cart, $paymentIntent, checkoutKey: $checkoutKey));
        } catch (CheckoutMaintenanceActive $exception) {
            $this->bot->sendMessage(
                $context->chatId,
                $this->maintenanceMessage($context->customer, $exception->state),
                $this->maintenanceKeyboard($context->customer),
            );

            return;
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.invalid_cart'));

            return;
        }

        $payment = $order->payments()->latest('created_at')->firstOrFail();

        $this->dispatchCheckoutInstructions($order, $payment);
        $this->dispatchPendingAdminNotification($order);
    }

    private function dispatchCheckoutInstructions(Order $order, Payment $payment): void
    {
        $effectType = TelegramOutboundEffectService::typeForPayment($payment);
        $effect = $effectType === null
            ? null
            : $this->outboundEffects->forOrder($order->id, $effectType);

        if ($effect !== null) {
            $this->dispatcher->dispatch($effect);

            return;
        }

        $job = match (true) {
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID' => SendBinanceCheckoutInstructions::dispatch($order->id, now()->toISOString()),
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'USDT_BEP20' => SendBscCheckoutInstructions::dispatch($order->id, now()->toISOString()),
            $payment->provider === PaymentProvider::KLIKQRIS => SendKlikQrisCheckoutInstructions::dispatch($order->id, now()->toISOString()),
            $payment->provider === PaymentProvider::SAKURUPIAH => SendSakurupiahCheckoutInstructions::dispatch($order->id, now()->toISOString()),
            default => SendManualCheckoutNotifications::dispatch($order->id, now()->toISOString()),
        };

        $job->onQueue('telegram-outbound')->afterCommit();
    }

    private function dispatchPendingAdminNotification(Order $order): void
    {
        $effect = $this->outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        if ($effect !== null) {
            $this->dispatcher->dispatch($effect);

            return;
        }

        // Compatibility path for deployments without the new effect target
        // configuration and for orders created before the ledger migration.
        SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())
            ->onQueue('telegram-outbound')
            ->afterCommit();
    }

    private function assertSupportedPostCreationBehavior(CheckoutPostCreationBehavior $behavior): void
    {
        match ($behavior) {
            CheckoutPostCreationBehavior::BinancePayInstructions,
            CheckoutPostCreationBehavior::BscInstructions,
            CheckoutPostCreationBehavior::ManualQrisInstructions,
            CheckoutPostCreationBehavior::KlikQrisInstructions,
            CheckoutPostCreationBehavior::SakurupiahInstructions => null,
        };
    }

    private function showManualPaymentProofInstructions(TelegramCallbackContext $context, string $orderId): void
    {
        $order = $this->manualPayments->pendingManualQrisOrderForCustomer($context->customer, $orderId);
        if ($order === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'payment.manual.proof_unavailable'),
            );

            return;
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $caption = $this->checkoutIntents->manualPaymentProofInstructions($order);
        $replyMarkup = [
            'inline_keyboard' => [[[
                'text' => $this->locale->translate($context->customer, 'payment.action.cancel'),
                'callback_data' => 'buyer:cancel-pending:'.$order->id,
            ]]],
        ];

        $this->bot->editMessageCaptionOrSend($context->chatId, $caption, $replyMarkup, $context->messageId);
    }

    private function sendSelectionStale(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.stale'));
    }

    private function sendSelectionUnavailable(TelegramCallbackContext $context): void
    {
        $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'catalog.selection.unavailable'));
    }

    private function maintenanceMessage(Customer $customer, CheckoutMaintenanceState $state): string
    {
        return $this->locale->translate($customer, 'checkout.maintenance')
            .$this->locale->checkoutMaintenanceNote($customer, $state->publicNote);
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    private function maintenanceKeyboard(Customer $customer): array
    {
        return ['inline_keyboard' => [[[
            'text' => $this->locale->translate($customer, 'checkout.status_button'),
            'callback_data' => 'checkout-maintenance:status',
        ]]]];
    }

    private function detailCheckoutIntentKey(Customer $customer, string $chatId, ?int $messageId, int $productId, string $token): string
    {
        $payload = implode('|', [
            'telegram-detail-checkout',
            $customer->getKey(),
            $customer->telegram_id,
            $chatId,
            $messageId ?? 'no-message',
            $productId,
            $token,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function checkoutIntentKey(Customer $customer, string $chatId, ?int $messageId, string $data): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $payload = implode('|', [
            'telegram-direct-checkout',
            $customer->getKey(),
            $customer->telegram_id,
            $chatId,
            $messageId,
            $data,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function checkoutIntentPayload(string $data): string
    {

        if (in_array($data, ['checkout:current', 'checkout:binance'], true)) {
            return 'checkout:choice';
        }

        return $data;
    }

    private function checkoutIntentAlreadyHandled(?string $checkoutKey): bool
    {
        return $checkoutKey !== null
            && Order::query()->where('checkout_key', $checkoutKey)->exists();
    }

    private function withCheckoutIntentLock(TelegramCallbackContext $context, ?string $checkoutKey, callable $callback): void
    {
        if ($checkoutKey === null) {
            $callback();

            return;
        }

        try {
            Cache::lock('telegram:checkout:'.$checkoutKey, 45)
                ->block(5, fn () => $callback());
        } catch (LockTimeoutException) {
            $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'checkout.processing'));
        }
    }
}
