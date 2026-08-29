<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntent;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\PaymentProviderResolver;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\GuestOrderNotificationService;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
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
        private readonly PaymentProviderResolver $providerResolver,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontOrderAccess $orderAccess,
        private readonly GuestOrderNotificationService $guestOrderNotifications,
        private readonly StorefrontFunnelEvents $events,
        private readonly CheckoutMaintenance $maintenance,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function start(StorefrontShopCheckoutInput $input, ?string $sessionId = null, ?Session $session = null): StorefrontShopCheckoutResult
    {
        $this->assertTermsAccepted($input);
        $termsVersion = $this->currentTermsVersion();
        $paymentRail = $this->paymentRail($input->paymentMethod);
        if ($sessionId === null || trim($sessionId) === '') {
            return $this->startOnce($input, null, $termsVersion, null, $paymentRail);
        }

        $session ??= app('session.store');
        $session->put(StorefrontGuestCart::CLIENT_KEY, trim($sessionId));
        $sessionId = $this->guestCarts->clientKey($session);

        try {
            return Cache::lock('storefront:checkout:'.hash('sha256', $sessionId), 45)
                ->block(5, fn (): StorefrontShopCheckoutResult => $this->startOnce($input, $sessionId, $termsVersion, $session, $paymentRail));
        } catch (LockTimeoutException $exception) {
            throw new InvalidCheckout('Checkout sedang diproses. Tunggu sebentar lalu buka kembali invoice Anda.', previous: $exception);
        }
    }

    private function startOnce(
        StorefrontShopCheckoutInput $input,
        ?string $sessionId,
        string $termsVersion,
        ?Session $session,
        string $paymentRail,
    ): StorefrontShopCheckoutResult {
        if ($session !== null) {
            return $this->startSessionCheckout($input, $sessionId, $session, $termsVersion, $paymentRail);
        }

        $maintenanceState = $this->maintenance->current();
        if ($maintenanceState->active) {
            throw new CheckoutMaintenanceActive($maintenanceState);
        }

        if ($input->productId === null) {
            throw new InvalidCheckout('Keranjang kosong.');
        }
        $product = $this->product($input->productId);
        $paymentIntent = $this->paymentIntent($paymentRail, $product);

        if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
            throw new InsufficientStock('Stok produk tidak cukup atau tidak tersedia.');
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
    ): StorefrontShopCheckoutResult {
        $candidate = $this->guestCarts->checkoutCandidate($session);
        $candidateKey = $candidate === null
            ? null
            : $this->checkoutKey($input, $sessionId, $paymentRail, $candidate);

        if ($candidateKey !== null
            && ($existing = Order::query()->where('checkout_key', $candidateKey)->first()) !== null
            && (string) $existing->cart_id === (string) $candidate?->getKey()) {
            return $this->reuseExistingCheckout($existing, $input);
        }

        $maintenanceState = $this->maintenance->current();
        if ($maintenanceState->active) {
            throw new CheckoutMaintenanceActive($maintenanceState);
        }

        if ($input->productId !== null) {
            $product = $this->product($input->productId);
            $paymentIntent = $this->paymentIntent($paymentRail, $product);

            if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
                throw new InsufficientStock('Stok produk tidak cukup atau tidak tersedia.');
            }

            $cart = DB::transaction(function () use ($session, $product, $input): Cart {
                $cart = $this->guestCarts->active($session);
                $this->carts->setItemQuantity($cart, $product, $input->quantity);

                return $cart;
            });
        } else {
            $cart = $this->guestCarts->current($session);
            if ($cart === null || $cart->cartItems->isEmpty()) {
                throw new InvalidCheckout('Keranjang kosong.');
            }

            if ($input->cartSnapshot !== null && ! $this->guestCarts->matchesSnapshot($cart, $input->cartSnapshot)) {
                throw new InvalidCheckout('Keranjang berubah. Periksa kembali item dan jumlah sebelum checkout.');
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
        if ($existing !== null) {
            $checkoutKey = $this->checkoutKey($input, $sessionId, $paymentRail, $cart, forceCartSnapshot: true);
        }

        $order = DB::transaction(function () use ($input, $cart, $paymentIntent, $checkoutKey, $termsVersion): Order {
            $customer = Customer::query()->whereKey($cart->customer_id)->lockForUpdate()->firstOrFail();
            $customer->forceFill(['first_name' => $this->buyerName($input->buyerName)])->save();

            return $this->orders->checkout(
                $cart,
                $paymentIntent,
                checkoutKey: $checkoutKey,
                termsVersion: $termsVersion,
                termsAcceptedAt: now(),
            );
        });

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
            $recoveryKey = $this->orderAccess->issue($order);
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
            throw new InvalidCheckout('Produk tidak ditemukan atau sudah tidak tersedia.');
        }

        return $product;
    }

    private function issueRecoveryKeyIfMissing(Order $order): ?string
    {
        return $this->orderAccess->hasKey($order) ? null : $this->orderAccess->issue($order);
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
                || (! filled(config('services.binance.pay_id')) && ! filled(config('services.binance.pay_uid')))
                || $product->usdt_price_amount === null) {
                throw new InvalidCheckout('Binance Pay belum tersedia untuk produk ini.');
            }

            return $this->providerResolver->resolve($paymentRail)->paymentIntent;
        }

        if (CheckoutPaymentIntents::isUsdtBep20Rail($paymentRail)) {
            if ($product->usdt_price_amount === null) {
                throw new InvalidCheckout('BSC payment rail is not available for this product.');
            }

            // Readiness is deliberately checked by OrderCheckoutService after
            // it locks the authoritative checkout setting inside its transaction.
            return $this->providerResolver->resolve($paymentRail)->paymentIntent;
        }

        if (ManualPaymentSetting::current() === null) {
            throw new InvalidCheckout('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.');
        }

        $selection = $this->providerResolver->resolve('qris');

        return $selection->paymentIntent;
    }

    private function paymentIntentForCart(string $paymentRail, Cart $cart): CheckoutPaymentIntent
    {
        $items = $cart->cartItems;
        if ($items->isEmpty()) {
            throw new InvalidCheckout('Keranjang kosong.');
        }

        $products = $items->map(fn ($item) => $item->product)->values();
        if ($products->contains(fn ($product): bool => $product === null)) {
            throw new InvalidCheckout('Keranjang berisi produk yang tidak tersedia.');
        }

        if (CheckoutPaymentIntents::isBinancePaymentRail($paymentRail)
            && $products->contains(fn (Product $product): bool => $product->usdt_price_amount === null)) {
            throw new InvalidCheckout('Semua produk di keranjang harus memiliki harga USDT untuk pembayaran Binance.');
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
        bool $forceCartSnapshot = false,
    ): ?string {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $isLegacySingleProduct = ! $forceCartSnapshot
            && ($cart === null
                || ($input->productId !== null
                    && $cart->cartItems->count() === 1
                    && (int) $cart->cartItems->first()->product_id === $input->productId
                    && (int) $cart->cartItems->first()->quantity === $input->quantity));
        $cartFingerprint = $isLegacySingleProduct
            ? (string) $input->productId.'|'.$input->quantity
            : $this->guestCarts->fingerprint($cart);
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

    }

    private function usesNativeQris(?Payment $payment): bool
    {
        return $payment?->provider === PaymentProvider::KLIKQRIS;
    }

    private function hasPayableNativeQris(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::KLIKQRIS
            && $this->klikQrisNativeQris->classify($payment)->outcome() === KlikQrisNativeQrisOutcome::Payable;
    }

    private function assertTermsAccepted(StorefrontShopCheckoutInput $input): void
    {
        if (! $input->termsAccepted) {
            throw new InvalidCheckout('Setujui Syarat Penggunaan dan Kebijakan Refund untuk melanjutkan checkout.');
        }
    }

    private function currentTermsVersion(): string
    {
        $version = config('storefront.policies.terms.version');
        if (! is_string($version) || trim($version) === '') {
            throw new InvalidCheckout('Checkout terms are not configured.');
        }

        return trim($version);
    }

    private function paymentRail(string $paymentMethod): string
    {
        $paymentRail = CheckoutPaymentIntents::normalizePaymentRail($paymentMethod);
        if (! CheckoutPaymentIntents::isSupportedPaymentRail($paymentRail)) {
            throw new InvalidCheckout('Metode pembayaran tidak tersedia.');
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
