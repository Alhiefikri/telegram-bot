<?php

namespace App\Storefront;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class StorefrontShopCheckout
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderCheckoutService $orders,
        private readonly PakasirNativeQrisService $nativeQris,
        private readonly PakasirProviderReadiness $readiness,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontOrderAccess $orderAccess,
    ) {}

    public function start(StorefrontShopCheckoutInput $input, ?string $sessionId = null): StorefrontShopCheckoutResult
    {
        $checkoutKey = $this->checkoutKey($input, $sessionId);
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
            $payment = $existing->payments()->latest('created_at')->first();
            if ($payment?->provider === 'pakasir') {
                try {
                    $this->nativeQris->ensureCreated($payment);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return new StorefrontShopCheckoutResult(redirectUrl: $this->invoiceUrls->show($existing), orderId: $existing->getKey());
        }

        $product = $this->product($input->productId);

        $this->assertPaymentMethodReady($input->paymentMethod, $product);

        if (! $product->isPublicVisible() || $product->availableStock() < $input->quantity) {
            throw new InsufficientStock('Stok produk tidak cukup atau tidak tersedia.');
        }

        $buyer = $this->buyerIdentity($input->buyerHandle);

        $customer = Customer::create([
            'telegram_id' => null,
            'first_name' => $buyer['display_name'],
            'username' => $buyer['telegram_username'],
        ]);

        $cart = $this->carts->activeCartFor($customer);
        $this->carts->setItemQuantity($cart, $product, $input->quantity);

        $order = $this->orders->checkout(
            $cart,
            $input->paymentMethod === 'binance_pay' ? 'BINANCE_PAY_ID' : PakasirCheckoutFlow::API_QRIS,
            $input->paymentMethod === 'binance_pay' ? 'manual' : 'pakasir',
            null,
            $checkoutKey,
        );
        $payment = $order->payments()->latest('created_at')->firstOrFail();

        if ($input->paymentMethod === 'binance_pay') {
            $recoveryKey = $this->orderAccess->issue($order);
            SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();

            return new StorefrontShopCheckoutResult(
                redirectUrl: $this->invoiceUrls->show($order),
                orderId: $order->getKey(),
                recoveryKey: $recoveryKey,
            );
        }

        try {
            $this->nativeQris->ensureCreated($payment);
        } catch (Throwable $exception) {
            report($exception);

            return new StorefrontShopCheckoutResult(
                redirectUrl: $this->invoiceUrls->show($order),
                orderId: $order->getKey(),
            );
        }

        $payment->refresh();
        $order->refresh();
        $recoveryKey = $this->orderAccess->issue($order);
        SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();

        return new StorefrontShopCheckoutResult(
            redirectUrl: $this->invoiceUrls->show($order),
            orderId: $order->getKey(),
            recoveryKey: $recoveryKey,
        );
    }

    private function product(int $productId): Product
    {
        $product = Product::query()->whereKey($productId)->first();

        if (! $product) {
            throw new InvalidCheckout('Produk tidak ditemukan atau sudah tidak tersedia.');
        }

        return $product;
    }

    /**
     * @return array{display_name: string, telegram_username: ?string}
     */
    private function buyerIdentity(string $buyerHandle): array
    {
        $value = trim($buyerHandle);

        if (preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $value) === 1) {
            $username = ltrim($value, '@');

            return [
                'display_name' => $username,
                'telegram_username' => $username,
            ];
        }

        return [
            'display_name' => $value,
            'telegram_username' => null,
        ];
    }

    private function assertPaymentMethodReady(string $paymentMethod, Product $product): void
    {
        if ($paymentMethod === 'binance_pay') {
            $settings = ManualPaymentSetting::current();
            if (! $settings?->binance_pay_enabled || ! filled(config('services.binance.pay_id')) || $product->usdt_price_amount === null) {
                throw new InvalidCheckout('Binance Pay belum tersedia untuk produk ini.');
            }

            return;
        }

        $settings = ManualPaymentSetting::current();

        if ($settings?->payment_mode !== 'pakasir'
            || $settings->pakasirCheckoutFlow() !== PakasirCheckoutFlow::API_QRIS
            || ! $this->readiness->checkout(PakasirCheckoutFlow::API_QRIS)->isReady()) {
            throw new InvalidCheckout('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.');
        }
    }

    private function checkoutKey(StorefrontShopCheckoutInput $input, ?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $payload = implode('|', [
            $sessionId,
            $input->productId,
            $input->quantity,
            mb_strtolower(trim($input->buyerHandle)),
            $input->paymentMethod,
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
