<?php

namespace App\Storefront;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Binance\BinancePayClient;
use App\Services\CartService;
use App\Services\Checkout\BinancePayCheckoutIntent;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\Checkout\StorefrontBalanceCheckoutIntent;
use App\Services\Checkout\UsdtBep20CheckoutIntent;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\GuestOrderNotificationService;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\OrderCheckoutService;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\WijayaPay\WijayaPayInvoiceLifecycleService;
use App\Support\PaymentProvider;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class StorefrontShopCheckout
{
    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontGuestCart $guestCarts,
        private readonly OrderCheckoutService $orders,
        private readonly KlikQrisNativeQrisService $klikQrisNativeQris,
        private readonly SakurupiahInvoiceLifecycleService $sakurupiahLifecycle,
        private readonly WijayaPayInvoiceLifecycleService $wijayapayLifecycle,
        private readonly PaymentProviderResolver $providerResolver,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontOrderAccess $orderAccess,
        private readonly GuestOrderNotificationService $guestOrderNotifications,
        private readonly StorefrontFunnelEvents $events,
        private readonly CheckoutMaintenance $maintenance,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly BinancePayClient $binance,
    ) {}

    public function start(
        StorefrontShopCheckoutInput $input,
        ?string $sessionId = null,
        ?Session $session = null,
        ?Closure $authorizeNewCheckout = null,
        ?string $visitorIp = null,
        ?Customer $customer = null,
    ): StorefrontShopCheckoutResult {
        $this->assertTermsAccepted($input);
        $termsVersion = $this->currentTermsVersion();
        $paymentRail = $this->paymentRail($input->paymentMethod);
        if ($paymentRail === CheckoutPaymentIntents::RAIL_STORE_BALANCE
            && ($customer === null || blank($sessionId) || $input->checkoutAttempt === null || ($input->walletExpectedTotal ?? 0) < 1)) {
            throw new StorefrontCheckoutError('wallet_unavailable');
        }
        if ($sessionId === null || trim($sessionId) === '') {
            $authorizeNewCheckout?->__invoke();

            return $this->startOnce($input, null, $termsVersion, null, $paymentRail);
        }

        $session ??= app('session.store');
        $session->put(StorefrontGuestCart::CLIENT_KEY, trim($sessionId));
        $sessionId = $this->guestCarts->clientKey($session);
        $admission = new StorefrontCheckoutAdmission($sessionId, $visitorIp);

        try {
            return Cache::lock('storefront:checkout:'.hash('sha256', $sessionId), 45)
                ->block(5, fn (): StorefrontShopCheckoutResult => $paymentRail === CheckoutPaymentIntents::RAIL_STORE_BALANCE
                    ? $this->startWalletCheckout($input, $customer, $sessionId, $session, $termsVersion, $admission, $authorizeNewCheckout)
                    : $this->startOnce($input, $sessionId, $termsVersion, $session, $paymentRail, $authorizeNewCheckout, $admission));
        } catch (LockTimeoutException $exception) {
            throw new StorefrontCheckoutError('checkout_locked', previous: $exception);
        }
    }

    private function startWalletCheckout(
        StorefrontShopCheckoutInput $input,
        Customer $customer,
        string $sessionId,
        Session $session,
        string $termsVersion,
        StorefrontCheckoutAdmission $admission,
        ?Closure $authorizeNewCheckout,
    ): StorefrontShopCheckoutResult {
        $key = hash_hmac('sha256', json_encode([
            'storefront-balance', $sessionId, $customer->id, $input->checkoutAttempt,
            $input->productId, $input->productId === null ? $input->cartSnapshot : $input->quantity,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        if (($order = $admission->existing($key)) === null) {
            $maintenance = $this->maintenance->current();
            if ($maintenance->active) {
                throw new CheckoutMaintenanceActive($maintenance);
            }
            $admission->assertCapacity();
            $authorizeNewCheckout?->__invoke();
            $source = $input->productId === null ? $this->guestCarts->current($session) : null;
            $value = $this->supportContactValue($input->supportContactChannel, $input->supportContactValue);
            $intent = new StorefrontBalanceCheckoutIntent(
                (int) $customer->id, $input->walletExpectedTotal, $input->email,
                $value === null ? null : $input->supportContactChannel, $value,
            );

            try {
                $order = DB::transaction(function () use ($input, $customer, $termsVersion, $admission, $key, $source, $intent): Order {
                    if (($existing = $admission->guard($key)) !== null) {
                        return $existing;
                    }

                    Customer::query()->whereKey($customer->id)->sharedLock()->firstOrFail();
                    $sourceCart = $source === null ? null : Cart::query()->lockForUpdate()->find($source->id);
                    // Lock the source Cart before copying it so concurrent cart edits cannot be consumed.
                    if ($input->productId === null && ($sourceCart === null || $sourceCart->status !== CartStatus::Active
                        || ! $this->guestCarts->matchesSnapshot($sourceCart, $input->cartSnapshot)
                        || $sourceCart->cartItems->isEmpty())) {
                        throw new StorefrontCheckoutError('cart_changed');
                    }

                    $cart = Cart::query()->create([
                        'customer_id' => $customer->id,
                        'origin' => CartOrigin::StorefrontBalance,
                        'status' => CartStatus::Active,
                        'expires_at' => now()->addHour(),
                    ]);

                    if ($sourceCart === null) {
                        $product = $this->product($input->productId);
                        $cart->cartItems()->create(['product_id' => $product->id, 'quantity' => $input->quantity]);
                    } else {
                        $cart->cartItems()->createMany($sourceCart->cartItems->map(fn ($item): array => [
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                        ])->all());
                    }

                    $order = $this->orders->checkout(
                        $cart, $intent, checkoutKey: $key, termsVersion: $termsVersion,
                        termsAcceptedAt: now(), storefrontAdmission: $admission,
                    );
                    $sourceCart?->update(['status' => CartStatus::CheckedOut]);

                    return $order;
                }, 5);
            } catch (InsufficientStoreBalance $exception) {
                throw new StorefrontCheckoutError('wallet_insufficient', previous: $exception);
            } catch (CustomerUsdtFundingUnavailable|StoreBalanceProjectionMismatchException $exception) {
                throw new StorefrontCheckoutError('wallet_unavailable', previous: $exception);
            }
        }

        if ((int) $order->customer_id !== (int) $customer->id) {
            throw new StorefrontCheckoutError('wallet_unavailable');
        }

        return new StorefrontShopCheckoutResult(
            redirectUrl: $this->invoiceUrls->show($order),
            orderId: $order->getKey(),
            recoveryKey: $this->issueRecoveryKeyIfMissing($order),
        );
    }

    private function startOnce(
        StorefrontShopCheckoutInput $input,
        ?string $sessionId,
        string $termsVersion,
        ?Session $session,
        string $paymentRail,
        ?Closure $authorizeNewCheckout = null,
        ?StorefrontCheckoutAdmission $admission = null,
    ): StorefrontShopCheckoutResult {
        if ($session !== null) {
            return $this->startSessionCheckout($input, $sessionId, $session, $termsVersion, $paymentRail, $authorizeNewCheckout, $admission);
        }

        $maintenanceState = $this->maintenance->current();
        if ($maintenanceState->active) {
            throw new CheckoutMaintenanceActive($maintenanceState);
        }

        if ($input->productId === null) {
            throw new StorefrontCheckoutError('cart_empty_error');
        }
        $product = $this->product($input->productId);
        $paymentIntent = $this->paymentIntent($paymentRail, $product);

        if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
            throw new InsufficientStock(StorefrontLocale::text('insufficient_stock'));
        }

        $order = DB::transaction(function () use ($input, $product, $paymentIntent, $termsVersion): Order {
            $customer = Customer::create([
                'telegram_id' => null,
                'first_name' => $this->buyerName($input->buyerName),
                'username' => null,
            ]);

            $cart = $this->carts->activeCartFor($customer);
            $this->carts->setItemQuantity($cart, $product, $input->quantity);

            return $this->orders->checkout(
                $cart,
                $paymentIntent,
                checkoutKey: null,
                termsVersion: $termsVersion,
                termsAcceptedAt: now(),
            );
        });

        return $this->finishNewCheckout($order, $input);
    }

    private function startSessionCheckout(
        StorefrontShopCheckoutInput $input,
        string $sessionId,
        Session $session,
        string $termsVersion,
        string $paymentRail,
        ?Closure $authorizeNewCheckout,
        StorefrontCheckoutAdmission $admission,
    ): StorefrontShopCheckoutResult {
        $candidate = $this->guestCarts->checkoutCandidate($session);
        $candidateKey = $candidate === null && $input->checkoutAttempt === null
            ? null
            : $this->checkoutKey($input, $sessionId, $paymentRail, $candidate);

        if ($candidateKey !== null && ($existing = $admission->existing($candidateKey)) !== null) {
            return $this->reuseExistingCheckout($existing, $input);
        }
        if ($candidateKey !== null
            && ($existing = Order::query()->where('checkout_key', $candidateKey)->first()) !== null
            && (string) $existing->cart_id === (string) $candidate?->getKey()) {
            return $this->reuseExistingCheckout($existing, $input);
        }

        $maintenanceState = $this->maintenance->current();
        if ($maintenanceState->active) {
            throw new CheckoutMaintenanceActive($maintenanceState);
        }
        $admission->assertCapacity();
        $authorizeNewCheckout?->__invoke();

        if ($input->productId !== null) {
            $product = $this->product($input->productId);
            $paymentIntent = $this->paymentIntent($paymentRail, $product);

            if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
                throw new InsufficientStock(StorefrontLocale::text('insufficient_stock'));
            }

            $cart = DB::transaction(function () use ($session, $product, $input): Cart {
                $cart = $this->guestCarts->active($session);
                $this->carts->setItemQuantity($cart, $product, $input->quantity);

                return $cart;
            });
        } else {
            $cart = $this->guestCarts->current($session);
            if ($cart === null || $cart->cartItems->isEmpty()) {
                throw new StorefrontCheckoutError('cart_empty_error');
            }

            if ($input->cartSnapshot !== null && ! $this->guestCarts->matchesSnapshot($cart, $input->cartSnapshot)) {
                throw new StorefrontCheckoutError('cart_changed');
            }

            $paymentIntent = $this->paymentIntentForCart($paymentRail, $cart);
        }

        $cart = $this->guestCarts->load($cart);
        if ($input->productId !== null && CheckoutPaymentIntents::isBinancePaymentRail($paymentRail)) {
            $paymentIntent = $this->paymentIntentForCart($paymentRail, $cart);
        }

        $checkoutKey = $this->checkoutKey($input, $sessionId, $paymentRail, $cart);
        $existing = Order::query()->where('checkout_key', $checkoutKey)->first();
        if ($existing !== null && (string) $existing->cart_id === (string) $cart->getKey()) {
            return $this->reuseExistingCheckout($existing, $input);
        }

        $customer = Customer::query()->findOrFail($cart->customer_id);
        $customer->forceFill(['first_name' => $this->buyerName($input->buyerName)])->save();
        $order = $this->orders->checkout(
            $cart,
            $paymentIntent,
            checkoutKey: $checkoutKey,
            termsVersion: $termsVersion,
            termsAcceptedAt: now(),
            storefrontAdmission: $admission,
        );

        return $this->finishNewCheckout($order, $input);
    }

    private function reuseExistingCheckout(Order $existing, StorefrontShopCheckoutInput $input): StorefrontShopCheckoutResult
    {
        $this->guestOrderNotifications->capture($existing, $input->email);
        $this->storeSupportContact($existing, $input);
        $recoveryKey = $this->issueRecoveryKeyIfMissing($existing);

        if ($existing->order_status === OrderStatus::PendingPayment
            && $existing->payment_status === PaymentStatus::Pending) {
            $payment = $existing->payments()->latest('created_at')->first();
            if ($this->usesNativeQris($payment)) {
                try {
                    $wasQrisReady = $this->hasPayableNativeQris($payment);
                    $this->ensureNativeQrisCreated($payment);
                    $payment->refresh();
                    if ($this->hasPayableNativeQris($payment)) {
                        $this->events->qrisCreated($existing, $payment, [
                            'source' => 'checkout_reuse',
                            'idempotent_reuse' => $wasQrisReady,
                        ]);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return new StorefrontShopCheckoutResult(
            redirectUrl: $this->invoiceUrls->show($existing),
            orderId: $existing->getKey(),
            recoveryKey: $recoveryKey,
        );
    }

    private function finishNewCheckout(Order $order, StorefrontShopCheckoutInput $input): StorefrontShopCheckoutResult
    {
        $this->guestOrderNotifications->capture($order, $input->email);
        $this->storeSupportContact($order, $input);
        $payment = $order->payments()->latest('created_at')->firstOrFail();

        if ($payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID') {
            $recoveryKey = $this->issueRecoveryKeyIfMissing($order);
            $this->dispatchPendingAdminNotification($order);

            return new StorefrontShopCheckoutResult(
                redirectUrl: $this->invoiceUrls->show($order),
                orderId: $order->getKey(),
                recoveryKey: $recoveryKey,
            );
        }

        $recoveryKey = $this->issueRecoveryKeyIfMissing($order);

        try {
            $this->ensureNativeQrisCreated($payment);
        } catch (Throwable $exception) {
            report($exception);

            return new StorefrontShopCheckoutResult(
                redirectUrl: $this->invoiceUrls->show($order),
                orderId: $order->getKey(),
                recoveryKey: $recoveryKey,
            );
        }

        $payment->refresh();
        $order->refresh();
        if ($this->hasPayableNativeQris($payment)) {
            $this->events->qrisCreated($order, $payment, ['source' => 'checkout_submit']);
        }
        $this->dispatchPendingAdminNotification($order);

        return new StorefrontShopCheckoutResult(
            redirectUrl: $this->invoiceUrls->show($order),
            orderId: $order->getKey(),
            recoveryKey: $recoveryKey,
        );
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

    private function product(int $productId): Product
    {
        $product = Product::query()->whereKey($productId)->first();

        if (! $product) {
            throw new StorefrontCheckoutError('product_unavailable');
        }

        return $product;
    }

    private function issueRecoveryKeyIfMissing(Order $order): ?string
    {
        return DB::transaction(function () use ($order): ?string {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            return $this->orderAccess->hasKey($lockedOrder) ? null : $this->orderAccess->issue($lockedOrder);
        }, 5);
    }

    private function buyerName(string $buyerName): string
    {
        return trim($buyerName);
    }

    private function paymentIntent(string $paymentMethod, Product $product): CheckoutPaymentIntent
    {
        $paymentRail = $this->paymentRail($paymentMethod);

        if (CheckoutPaymentIntents::isBinancePayIdRail($paymentRail)) {
            $settings = ManualPaymentSetting::current();
            if (! $settings?->binance_pay_enabled
                || ! $this->binance->configured()
                || (! filled(config('services.binance.pay_id')) && ! filled(config('services.binance.pay_uid')))
                || $product->usdt_price_amount === null) {
                throw new StorefrontCheckoutError('binance_unavailable');
            }

            return new BinancePayCheckoutIntent(requiresOrderId: true);
        }

        if (CheckoutPaymentIntents::isUsdtBep20Rail($paymentRail)) {
            if ($product->usdt_price_amount === null) {
                throw new StorefrontCheckoutError('bsc_unavailable');
            }
            if (! ManualPaymentSetting::current()?->bsc_payment_enabled) {
                throw new StorefrontCheckoutError('bsc_unavailable');
            }

            // Readiness is deliberately checked by OrderCheckoutService after
            // it locks the authoritative checkout setting inside its transaction.
            return new UsdtBep20CheckoutIntent(requiresTransactionHash: true);
        }

        if (ManualPaymentSetting::current() === null) {
            throw new StorefrontCheckoutError('automatic_qris_inactive');
        }

        $selection = $this->providerResolver->resolve('qris');

        return $selection->paymentIntent;
    }

    private function paymentIntentForCart(string $paymentRail, Cart $cart): CheckoutPaymentIntent
    {
        $items = $cart->cartItems;
        if ($items->isEmpty()) {
            throw new StorefrontCheckoutError('cart_empty_error');
        }

        $products = $items->map(fn ($item) => $item->product)->values();
        if ($products->contains(fn ($product): bool => $product === null)) {
            throw new StorefrontCheckoutError('cart_unavailable_items');
        }

        if (CheckoutPaymentIntents::isBinancePaymentRail($paymentRail)
            && $products->contains(fn (Product $product): bool => $product->usdt_price_amount === null)) {
            throw new StorefrontCheckoutError('cart_requires_usdt');
        }

        /** @var Product $product */
        $product = $products->first();

        return $this->paymentIntent($paymentRail, $product);
    }

    private function checkoutKey(
        StorefrontShopCheckoutInput $input,
        ?string $sessionId,
        ?string $paymentRail = null,
        ?Cart $cart = null,
    ): ?string {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        if ($input->checkoutAttempt !== null) {
            $cartFingerprint = $input->checkoutAttempt.'|'.($input->productId !== null
                ? $input->productId.':'.$input->quantity
                : $input->cartSnapshot);
        } else {
            $cartFingerprint = $input->productId !== null
                ? (string) $cart?->getKey().'|'.$input->productId.':'.$input->quantity
                : $this->guestCarts->fingerprint($cart);
        }
        $payload = implode('|', [
            $sessionId,
            $cartFingerprint,
            mb_strtolower(trim($input->buyerName)),
            $paymentRail ?? $this->paymentRail($input->paymentMethod),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function ensureNativeQrisCreated(Payment $payment): void
    {
        if ($payment->provider === PaymentProvider::KLIKQRIS) {
            $this->klikQrisNativeQris->lifecycle($payment);

            return;
        }

        if ($payment->provider === PaymentProvider::SAKURUPIAH) {
            $this->sakurupiahLifecycle->lifecycle($payment);

            return;
        }

        if ($payment->provider === PaymentProvider::WIJAYAPAY) {
            $this->wijayapayLifecycle->lifecycle($payment);
        }
    }

    private function usesNativeQris(?Payment $payment): bool
    {
        return in_array($payment?->provider, [
            PaymentProvider::KLIKQRIS,
            PaymentProvider::SAKURUPIAH,
            PaymentProvider::WIJAYAPAY,
        ], true);
    }

    private function hasPayableNativeQris(Payment $payment): bool
    {
        if ($payment->provider === PaymentProvider::KLIKQRIS) {
            return $this->klikQrisNativeQris->classify($payment)->outcome() === KlikQrisNativeQrisOutcome::Payable;
        }

        if ($payment->provider === PaymentProvider::WIJAYAPAY) {
            return $this->wijayapayLifecycle->classify($payment)->isPayable();
        }

        return $payment->provider === PaymentProvider::SAKURUPIAH
            && $this->sakurupiahLifecycle->classify($payment)->isPayable();
    }

    private function assertTermsAccepted(StorefrontShopCheckoutInput $input): void
    {
        if (! $input->termsAccepted) {
            throw new StorefrontCheckoutError('agree_required');
        }
    }

    private function currentTermsVersion(): string
    {
        $version = config('storefront.policies.terms.version');
        if (! is_string($version) || trim($version) === '') {
            throw new StorefrontCheckoutError('checkout_terms_unavailable');
        }

        return trim($version);
    }

    private function paymentRail(string $paymentMethod): string
    {
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);
        if (! in_array($paymentRail, [
            CheckoutPaymentIntents::RAIL_QRIS,
            CheckoutPaymentIntents::RAIL_BINANCE_PAY_ID,
            CheckoutPaymentIntents::RAIL_USDT_BEP20,
            CheckoutPaymentIntents::RAIL_STORE_BALANCE,
        ], true)) {
            throw new StorefrontCheckoutError('payment_unavailable');
        }

        return $paymentRail;
    }

    private function storeSupportContact(Order $order, StorefrontShopCheckoutInput $input): void
    {
        $channel = $input->supportContactChannel;
        $value = $this->supportContactValue($channel, $input->supportContactValue);

        if ($channel === null || $value === null) {
            $order->forceFill([
                'support_contact_channel' => null,
                'support_contact_value' => null,
            ])->save();

            return;
        }

        $order->forceFill([
            'support_contact_channel' => $channel,
            'support_contact_value' => $value,
        ])->save();
    }

    private function supportContactValue(?string $channel, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return match ($channel) {
            'telegram' => '@'.ltrim($value, '@'),
            'whatsapp' => preg_replace('/[^\d+]/', '', $value) ?: null,
            default => null,
        };
    }
}
