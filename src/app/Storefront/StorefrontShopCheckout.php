<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutMaintenanceActive;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
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
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class StorefrontShopCheckout
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderCheckoutService $orders,
        private readonly PakasirNativeQrisService $nativeQris,
        private readonly KlikQrisNativeQrisService $klikQrisNativeQris,
        private readonly PaymentProviderResolver $providerResolver,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontOrderAccess $orderAccess,
        private readonly StorefrontFunnelEvents $events,
        private readonly CheckoutMaintenance $maintenance,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
    ) {}

    public function start(StorefrontShopCheckoutInput $input, ?string $sessionId = null): StorefrontShopCheckoutResult
    {
        $paymentRail = $this->paymentRail($input->paymentMethod);
        $checkoutKey = $this->checkoutKey($input, $sessionId, $paymentRail);
        if ($checkoutKey === null) {
            return $this->startOnce($input, null);
        }

        try {
            return Cache::lock('storefront:checkout:'.$checkoutKey, 45)
                ->block(5, fn (): StorefrontShopCheckoutResult => $this->startOnce($input, $checkoutKey));
        } catch (LockTimeoutException $exception) {
            throw new InvalidCheckout('Checkout sedang diproses. Tunggu sebentar lalu buka kembali invoice Anda.', previous: $exception);
        }
    }

    private function startOnce(StorefrontShopCheckoutInput $input, ?string $checkoutKey): StorefrontShopCheckoutResult
    {
        if ($checkoutKey !== null && ($existing = Order::query()->where('checkout_key', $checkoutKey)->first()) !== null) {
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

            return new StorefrontShopCheckoutResult(redirectUrl: $this->invoiceUrls->show($existing), orderId: $existing->getKey(), recoveryKey: $recoveryKey);
        }
        $maintenanceState = $this->maintenance->current();
        if ($maintenanceState->active) {
            throw new CheckoutMaintenanceActive($maintenanceState);
        }

        $product = $this->product($input->productId);

        $paymentIntent = $this->paymentIntent($this->paymentRail($input->paymentMethod), $product);

        if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
            throw new InsufficientStock('Stok produk tidak cukup atau tidak tersedia.');
        }

        $order = DB::transaction(function () use ($input, $product, $paymentIntent, $checkoutKey): Order {
            $customer = Customer::create([
                'telegram_id' => null,
                'first_name' => $this->buyerName($input->buyerName),
                'username' => null,
            ]);

            $cart = $this->carts->activeCartFor($customer);
            $this->carts->setItemQuantity($cart, $product, $input->quantity);

            return $this->orders->checkout($cart, $paymentIntent, checkoutKey: $checkoutKey);
        });
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
        if ($selection->requestedProvider === PaymentProvider::PAKASIR && $selection->provider === PaymentProvider::MANUAL) {
            throw new InvalidCheckout('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.');
        }

        return $selection->paymentIntent;
    }

    private function ensureNativeQrisCreated(Payment $payment): void
    {
        if ($payment->provider === PaymentProvider::KLIKQRIS) {
            $this->klikQrisNativeQris->lifecycle($payment);

            return;
        }

        if ($payment->provider === PaymentProvider::PAKASIR && $payment->method === PakasirCheckoutFlow::API_QRIS) {
            $this->nativeQris->ensureCreated($payment);
        }
    }

    private function usesNativeQris(?Payment $payment): bool
    {
        return $payment?->provider === PaymentProvider::KLIKQRIS
            || ($payment?->provider === PaymentProvider::PAKASIR && $payment?->method === PakasirCheckoutFlow::API_QRIS);
    }

    private function hasPayableNativeQris(Payment $payment): bool
    {
        if ($payment->provider === PaymentProvider::KLIKQRIS) {
            return $this->klikQrisNativeQris->classify($payment)->outcome() === KlikQrisNativeQrisOutcome::Payable;
        }

        return $payment->isPayableNativePakasirQris();
    }

    private function checkoutKey(StorefrontShopCheckoutInput $input, ?string $sessionId, ?string $paymentRail = null): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $payload = implode('|', [
            $sessionId,
            $input->productId,
            $input->quantity,
            mb_strtolower(trim($input->buyerName)),
            $paymentRail ?? $this->paymentRail($input->paymentMethod),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
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
