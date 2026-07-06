<?php

namespace App\Storefront;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Support\Facades\URL;
use Throwable;

class StorefrontShopCheckout
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderCheckoutService $orders,
        private readonly PakasirNativeQrisService $nativeQris,
        private readonly PakasirProviderReadiness $readiness,
        private readonly PakasirProviderAdapter $pakasir,
        private readonly OrderStateMachine $orderStates,
    ) {}

    public function start(Product $product, int $quantity, string $displayName, ?string $telegramUsername): StorefrontShopCheckoutResult
    {
        $this->assertNativeQrisReady();

        if (! $product->isPublicVisible() || $product->availableStock() < $quantity) {
            throw new InsufficientStock('Stok produk tidak cukup atau tidak tersedia.');
        }

        $customer = Customer::create([
            'telegram_id' => null,
            'first_name' => $displayName,
            'username' => $telegramUsername,
        ]);

        $cart = $this->carts->activeCartFor($customer);
        $this->carts->setItemQuantity($cart, $product, $quantity);

        $order = $this->orders->checkout($cart, PakasirCheckoutFlow::API_QRIS, 'pakasir');
        $payment = $order->payments()->latest('created_at')->firstOrFail();

        try {
            $transaction = $this->nativeQris->ensureCreated($payment);
        } catch (Throwable $exception) {
            report($exception);

            try {
                $this->orderStates->cancelPendingOrder($order);
            } catch (Throwable $cancellationException) {
                report($cancellationException);
            }

            throw new InvalidCheckout('QRIS Pakasir belum bisa dibuat. Stok sudah dilepas; coba lagi beberapa menit.', 0, $exception);
        }

        $payment->refresh();
        $order->refresh();

        return new StorefrontShopCheckoutResult(
            order: $order,
            payment: $payment,
            transaction: $transaction,
            orderUrl: URL::signedRoute('storefront.shop.orders.show', $order),
            statusUrl: URL::signedRoute('storefront.shop.orders.status', $order),
            hostedPaymentUrl: $this->pakasir->hostedPaymentUrl($payment),
        );
    }

    private function assertNativeQrisReady(): void
    {
        $settings = ManualPaymentSetting::current();

        if ($settings?->payment_mode !== 'pakasir'
            || $settings->pakasirCheckoutFlow() !== PakasirCheckoutFlow::API_QRIS
            || ! $this->readiness->checkout(PakasirCheckoutFlow::API_QRIS)->isReady()) {
            throw new InvalidCheckout('QRIS otomatis belum aktif. Hubungi admin atau coba lagi nanti.');
        }
    }
}
