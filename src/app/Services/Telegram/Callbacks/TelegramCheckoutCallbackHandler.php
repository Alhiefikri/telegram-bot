<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\InsufficientUsdtBalance;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Exceptions\UsdtBalanceFrozenException;
use App\Exceptions\UsdtBalanceProjectionMismatchException;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendBscCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendSakurupiahCheckoutInstructions;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\CheckoutPricingSnapshot;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use App\Services\OrderCheckoutService;
use App\Services\Pricing\ProductPriceQuote;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Telegram\TelegramBinanceRailSelectionSession;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramCartIntents;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramHtmlCaption;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramManualPaymentIntents;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUiIcons;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
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
        private ProductQuantityPricing $pricing,
        private TelegramOutboundEffectService $outboundEffects,
        private TelegramOutboundEffectDispatcher $dispatcher,
        private TelegramHtmlCaption $captions,
        private TelegramUiIcons $uiIcons,
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
        return $context->data === 'checkout'
            || $context->data === 'checkout:current'
            || $context->data === 'checkout:balance'
            || $context->data === 'checkout:usdt_balance'
            || $context->data === 'checkout:binance'
            || preg_match('/^checkout:binance:(?:rail:)?(binance_pay_id|usdt_bep20):[a-f0-9]{16}$/', $context->data) === 1
            || preg_match('/^checkout:binance:cancel:[a-f0-9]{16}$/', $context->data) === 1
            || preg_match('/^manual:paid:([0-9a-fA-F-]+)$/', $context->data) === 1
            || preg_match('/^detail:pay:(qris|binance|balance):([1-9][0-9]*):[A-Za-z0-9_-]+$/', $context->data) === 1;
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

        if ($context->data === 'checkout:balance') {
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->checkout($context, CheckoutPaymentIntents::RAIL_STORE_BALANCE, true, $checkoutKey));

            return true;
        }

        if ($context->data === 'checkout:usdt_balance') {
            if (! $context->isPrivate() || $context->chatId !== (string) $context->customer->telegram_id) {
                $this->bot->answerCallbackQuery($context->callbackQueryId);

                return true;
            }
            $checkoutKey = $this->checkoutIntentKey($context->customer, $context->chatId, $context->messageId, $this->checkoutIntentPayload($context->data));
            $this->withCheckoutIntentLock($context, $checkoutKey, fn () => $this->checkout($context, CheckoutPaymentIntents::RAIL_USDT_BALANCE, true, $checkoutKey));

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

        if (preg_match('/^detail:pay:(qris|binance|balance):([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $context->data, $matches) === 1) {
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
                    fn () => $this->checkoutProductDetail($context, $paymentMethod, $productId, $token, $checkoutKey),
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
        $preview = $cart === null ? null : $this->cartPricing($cart);
        if ($preview === null || $preview['snapshot']->usdtTotal() === null) {
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
        $quote = $product === null ? null : $this->safeQuote($product, (int) ($selection['quantity'] ?? 0));
        if ($selection === null
            || $selection['mode'] !== 'browsing'
            || (int) $selection['productId'] !== $productId
            || ! hash_equals((string) $selection['interactionToken'], $token)
            || $product === null
            || max(0, $product->availableStock()) < (int) $selection['quantity']
            || $quote === null
            || $quote->usdtSubtotal === null
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
            $quote = $product === null ? null : $this->safeQuote($product, (int) ($selection['quantity'] ?? 0));
            if ($selection === null
                || $selection['mode'] !== 'browsing'
                || (int) $selection['productId'] !== $productId
                || ! hash_equals((string) $selection['interactionToken'], $token)
                || $product === null
                || max(0, $product->availableStock()) < (int) $selection['quantity']
                || $quote === null
                || $quote->usdtSubtotal === null) {
                $this->railSession->forget($context->customer);
                $this->sendRailSelectionStale($context);

                return;
            }

            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                $this->session->forget($context->customer);
                $this->railSession->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId);
                $this->bot->sendMessage(
                    $context->chatId,
                    $this->maintenanceMessage($context->customer, $maintenance),
                    $this->maintenanceKeyboard($context->customer),
                );

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

    private function checkoutProductDetail(TelegramCallbackContext $context, string $rail, int $productId, string $token, string $checkoutKey): void
    {
        if ($this->checkoutIntentAlreadyHandled($checkoutKey)) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);

            return;
        }

        $this->session->withLock($context->customer, function () use ($context, $rail, $productId, $token, $checkoutKey): void {
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
            $quote = $product === null ? null : $this->safeQuote($product, (int) $selection['quantity']);
            if (! $product || $availableStock < (int) $selection['quantity'] || $quote === null) {
                $this->session->forget($context->customer);
                $this->sendSelectionUnavailable($context);

                return;
            }

            if ($rail === 'binance' && ($quote->usdtSubtotal === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($context->customer);
                $this->bot->answerCallbackQuery($context->callbackQueryId, $this->locale->translate($context->customer, 'cart.binance_unavailable_product'));

                return;
            }

            // Resolve the rail before mutating the active Cart. The checkout
            // service remains the authoritative second check inside its transaction.
            $requestedRail = match ($rail) {
                'balance' => CheckoutPaymentIntents::RAIL_STORE_BALANCE,
                'binance' => CheckoutPaymentIntents::RAIL_BINANCE_PAY_ID,
                default => CheckoutPaymentIntents::RAIL_QRIS,
            };
            $this->paymentIntentSelector->select($requestedRail);

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
            $this->checkout($context, $requestedRail, false, $checkoutKey);
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

        $preview = $this->cartPricing($cart);
        if ($preview === null) {
            $this->bot->answerCallbackQuery($context->callbackQueryId);
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.invalid_cart'));

            return;
        }

        /** @var CheckoutPricingSnapshot $snapshot */
        $snapshot = $preview['snapshot'];
        $products = $preview['products'];
        $usdtBalance = app(CustomerUsdtBalanceLedger::class)->snapshot($context->customer);
        $usdtBalanceSelectable = $context->isPrivate()
            && $context->chatId === (string) $context->customer->telegram_id
            && $snapshot->usdtTotal() !== null
            && $usdtBalance->isAvailable() && ! $usdtBalance->isFrozen()
            && app(CustomerUsdtFundingGate::class)->spendingAvailability()->available;
        $showUsdtPricing = ($this->checkoutIntents->binanceRailSelectable() || $usdtBalanceSelectable) && $snapshot->usdtTotal() !== null;
        $summaryLines = [$this->locale->translate($context->customer, 'checkout.payment_method')];
        foreach ($cart->cartItems as $item) {
            $quote = $snapshot->quoteForItem($item);
            $product = $products->get((int) $item->product_id);
            $summaryLines[] = $this->locale->translate(
                $context->customer,
                $showUsdtPricing ? 'checkout.cart_item_dual' : 'checkout.cart_item',
                [
                    'product' => $product?->name ?? 'Product',
                    'quantity' => (int) $item->quantity,
                    'unit' => number_format($quote->effectiveIdrUnitAmount, 0, ',', '.'),
                    'subtotal' => number_format($quote->idrSubtotal, 0, ',', '.'),
                    ...($showUsdtPricing ? [
                        'usdt_unit' => $this->checkoutIntents->formatUsdt((int) $quote->effectiveUsdtUnitAmount),
                        'usdt_subtotal' => $this->checkoutIntents->formatUsdt((int) $quote->usdtSubtotal),
                    ] : []),
                ],
            );
            if ($product instanceof Product) {
                foreach ($quote->tiers() as $tier) {
                    if ($tier->minimumQuantity <= $product->availableStock()) {
                        $summaryLines[] = $this->locale->translate(
                            $context->customer,
                            $showUsdtPricing ? 'checkout.cart_tier_dual' : 'checkout.cart_tier',
                            [
                                'minimum' => $tier->minimumQuantity,
                                'amount' => number_format($tier->priceAmount, 0, ',', '.'),
                                ...($showUsdtPricing ? ['usdt' => $this->checkoutIntents->formatUsdt((int) $tier->usdtPriceAmount)] : []),
                            ],
                        );
                    }
                }
                if ($quote->nextTier !== null
                    && $quote->nextTier->minimumQuantity <= $product->availableStock()) {
                    $nextTierQuantity = $quote->nextTier->minimumQuantity;
                    $nextTierQuote = $this->safeQuote($product, $nextTierQuantity);
                    if ($nextTierQuote !== null) {
                        $summaryLines[] = $this->locale->translate($context->customer, 'checkout.cart_next_tier', [
                            'quantity' => $nextTierQuantity - $quote->quantity,
                        ]);
                        $summaryLines[] = $this->locale->translate(
                            $context->customer,
                            $showUsdtPricing ? 'checkout.cart_next_tier_total_dual' : 'checkout.cart_next_tier_total',
                            [
                                'quantity' => $nextTierQuantity,
                                'amount' => number_format($nextTierQuote->idrSubtotal, 0, ',', '.'),
                                ...($showUsdtPricing ? ['usdt' => $this->checkoutIntents->formatUsdt((int) $nextTierQuote->usdtSubtotal)] : []),
                            ],
                        );
                    }
                }
            }
            $usdtSavings = $quote->usdtSavings();
            if ($quote->idrSavings() > 0 && $usdtSavings !== null && $usdtSavings > 0) {
                $summaryLines[] = $this->locale->translate($context->customer, 'checkout.cart_savings_dual', [
                    'idr' => number_format($quote->idrSavings(), 0, ',', '.'),
                    'usdt' => $this->checkoutIntents->formatUsdt($usdtSavings),
                ]);
            } elseif ($quote->idrSavings() > 0) {
                $summaryLines[] = $this->locale->translate($context->customer, 'checkout.cart_savings', [
                    'amount' => number_format($quote->idrSavings(), 0, ',', '.'),
                ]);
            } elseif ($usdtSavings !== null && $usdtSavings > 0) {
                $summaryLines[] = $this->locale->translate($context->customer, 'checkout.cart_savings_usdt', [
                    'amount' => $this->checkoutIntents->formatUsdt($usdtSavings),
                ]);
            }
        }
        $summaryLines[] = $this->locale->translate(
            $context->customer,
            $showUsdtPricing ? 'checkout.cart_total_dual' : 'checkout.cart_total',
            [
                'amount' => number_format($snapshot->idrTotal(), 0, ',', '.'),
                ...($showUsdtPricing ? ['usdt' => $this->checkoutIntents->formatUsdt((int) $snapshot->usdtTotal())] : []),
            ],
        );

        $qrisButton = [[
            'text' => $this->locale->translate($context->customer, 'checkout.payment.qris', [
                'amount' => number_format($snapshot->idrTotal(), 0, ',', '.'),
            ]),
            'callback_data' => 'checkout:current',
        ]];
        $balanceButton = [[
            ...$this->uiIcons->apply('menu.balance', $this->locale->translate($context->customer, 'checkout.payment.store_balance', [
                'amount' => number_format($snapshot->idrTotal(), 0, ',', '.'),
            ])),
            'callback_data' => 'checkout:balance',
        ]];
        $buttons = [$qrisButton, $balanceButton];
        if ($usdtBalanceSelectable) {
            $buttons[] = [[
                ...$this->uiIcons->apply('menu.balance', $this->locale->translate($context->customer, 'checkout.payment.usdt_balance', [
                    'amount' => $this->checkoutIntents->formatUsdt($snapshot->usdtTotal()),
                ])),
                'callback_data' => 'checkout:usdt_balance',
            ]];
        }
        if ($this->checkoutIntents->binanceRailSelectable() && $snapshot->usdtTotal() !== null) {
            $buttons[] = [[
                'text' => $this->locale->translate($context->customer, 'checkout.payment.binance', [
                    'amount' => $this->checkoutIntents->formatUsdt($snapshot->usdtTotal()),
                ]),
                'callback_data' => 'checkout:binance',
            ]];
        }

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $paymentMethodMessage = $this->captions->html(implode("\n", $summaryLines), 4096)
            ."\n\n"
            .$this->captions->blockquote($this->locale->qrisCrossBorderSupport($context->customer));
        $this->bot->sendMessage($context->chatId, $paymentMethodMessage, ['inline_keyboard' => $buttons], ['parse_mode' => 'HTML']);
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
            $preview = $this->cartPricing($cart);
            if ($preview === null) {
                throw new InvalidCartState('Cart pricing is no longer available.');
            }
            if ($binance) {
                $railReady = $paymentRail === CheckoutPaymentIntents::RAIL_BINANCE_PAY_ID
                    ? $this->checkoutIntents->binancePayEnabled()
                    : $this->checkoutIntents->bscRailSelectable();
                if (! $railReady || $preview['snapshot']->usdtTotal() === null) {
                    $this->bot->sendMessage(
                        $context->chatId,
                        $this->locale->translate($context->customer, 'cart.binance_unavailable_cart'),
                        [
                            'inline_keyboard' => [[[
                                'text' => $this->locale->translate($context->customer, 'catalog.detail.pay_qris'),
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
        } catch (InsufficientStoreBalance) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.balance.insufficient'));

            return;
        } catch (InsufficientUsdtBalance) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.usdt_balance.insufficient'));

            return;
        } catch (UsdtBalanceFrozenException|UsdtBalanceProjectionMismatchException|CustomerUsdtFundingUnavailable) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.usdt_balance.unavailable'));

            return;
        } catch (StoreBalanceProjectionMismatchException) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.balance.recovery'));

            return;
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
            $this->bot->sendMessage($context->chatId, $this->locale->translate($context->customer, 'checkout.invalid_cart'));

            return;
        }

        $payment = $order->payments()->latest('created_at')->firstOrFail();

        $this->dispatchCheckoutInstructions($order, $payment);
        $this->dispatchPendingAdminNotification($order);
    }

    /**
     * @return array{snapshot: CheckoutPricingSnapshot, products: Collection<int, Product>}|null
     */
    private function cartPricing(Cart $cart): ?array
    {
        $cart->loadMissing('cartItems');
        $items = $cart->cartItems;
        if ($items->isEmpty()) {
            return null;
        }

        $productIds = $items
            ->pluck('product_id')
            ->map(static fn (mixed $productId): int => (int) $productId)
            ->filter(static fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();
        if ($productIds->count() !== $items->count()) {
            return null;
        }

        $products = Product::query()
            ->withAvailability()
            ->publicVisible()
            ->whereKey($productIds)
            ->get()
            ->keyBy(static fn (Product $product): int => (int) $product->getKey());
        foreach ($items as $item) {
            $product = $products->get((int) $item->product_id);
            $quantity = (int) $item->quantity;
            if (! $product instanceof Product || $quantity < 1 || $product->availableStock() < $quantity) {
                return null;
            }
        }

        try {
            $snapshot = CheckoutPricingSnapshot::fromCartItems($items, $products, $this->pricing);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['snapshot' => $snapshot, 'products' => $products];
    }

    private function safeQuote(Product $product, int $quantity): ?ProductPriceQuote
    {
        if ($quantity < 1) {
            return null;
        }

        try {
            return $this->pricing->quote($product, $quantity);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function dispatchCheckoutInstructions(Order $order, Payment $payment): void
    {
        if (in_array($payment->provider, [PaymentProvider::STORE_BALANCE, PaymentProvider::USDT_BALANCE], true)) {
            return;
        }

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
        if ($order->payment_status !== PaymentStatus::Pending) {
            return;
        }

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
            CheckoutPostCreationBehavior::SakurupiahInstructions,
            CheckoutPostCreationBehavior::StoreBalancePaid,
            CheckoutPostCreationBehavior::UsdtBalancePaid => null,
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
        if (in_array($data, ['checkout:current', 'checkout:balance', 'checkout:usdt_balance', 'checkout:binance'], true)) {
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
