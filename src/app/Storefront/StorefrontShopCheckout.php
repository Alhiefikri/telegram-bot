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
        private readonly OrderStateMachine $orderStates,
    ) {}

    public function start(StorefrontShopCheckoutInput $input): StorefrontShopCheckoutResult
    {
        $product = $this->product($input->productId);

        $this->assertNativeQrisReady();

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

        $order = $this->orders->checkout($cart, PakasirCheckoutFlow::API_QRIS, 'pakasir');
        $payment = $order->payments()->latest('created_at')->firstOrFail();

        try {
            $this->nativeQris->ensureCreated($payment);
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
            redirectUrl: URL::signedRoute('storefront.shop.orders.show', $order),
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
