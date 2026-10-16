<?php

namespace App\Storefront;

use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Exceptions\InvalidCartState;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Pricing\ProductQuantityPricing;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class StorefrontGuestCart
{
    public const CLIENT_KEY = 'storefront.checkout_client_key';

    private const CUSTOMER_ID_KEY = 'storefront.guest_customer_id';

    private const CUSTOMER_CLIENT_KEY = 'storefront.guest_customer_client_key';

    private const GUEST_CUSTOMER_NAME = 'Web Customer';

    public function __construct(private readonly CartService $carts) {}

    public function clientKey(Session $session): string
    {
        $clientKey = $session->get(self::CLIENT_KEY);

        if (! is_string($clientKey) || trim($clientKey) === '') {
            $clientKey = Str::random(40);
            $session->put(self::CLIENT_KEY, $clientKey);
        }

        return trim($clientKey);
    }

    public function current(Session $session): ?Cart
    {
        $customer = $this->sessionCustomer($session);
        if ($customer === null) {
            return null;
        }

        $cart = $this->carts->currentActiveCartFor($customer);

        return $cart === null ? null : $this->load($cart);
    }

    public function active(Session $session): Cart
    {
        $clientKey = $this->clientKey($session);

        try {
            return Cache::lock($this->lockKey($clientKey), 15)
                ->block(5, fn (): Cart => $this->createOrReuseActive($session, $clientKey));
        } catch (LockTimeoutException $exception) {
            throw new InvalidCartState('Keranjang sedang diproses. Coba lagi sebentar.', previous: $exception);
        }
    }

    public function checkoutCandidate(Session $session): ?Cart
    {
        $active = $this->current($session);
        if ($active !== null) {
            return $active;
        }

        $customer = $this->sessionCustomer($session);
        if ($customer === null) {
            return null;
        }

        $cart = Cart::query()
            ->where('customer_id', $customer->id)
            ->where('origin', CartOrigin::Customer)
            ->where('status', CartStatus::CheckedOut)
            ->latest('id')
            ->first();

        return $cart === null ? null : $this->load($cart);
    }

    public function load(Cart $cart): Cart
    {
        return $cart->load([
            'cartItems' => fn ($query) => $query->orderBy('product_id'),
            'cartItems.product' => fn ($query) => $query->withTrashed(),
            'cartItems.product.category',
        ]);
    }

    /**
     * Keep checkout replay identity stable after Product stock reservations
     * update mutable Product timestamps. The submitted snapshot separately
     * validates pricing and availability before a new checkout is created.
     */
    public function fingerprint(Cart $cart): string
    {
        $cart = $this->load($cart);

        return (string) $cart->getKey().'|'.$cart->cartItems
            ->sortBy('product_id')
            ->map(static fn ($item): string => (int) $item->product_id.':'.(int) $item->quantity)
            ->implode(';');
    }

    public function snapshotToken(Cart $cart): string
    {
        $cart = $this->load($cart);
        $payload = [
            'cart_id' => (int) $cart->getKey(),
            'items' => $cart->cartItems->map(function ($item): array {
                $product = $item->product;

                return [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'updated_at' => $product?->updated_at?->toISOString(),
                    'deleted_at' => $product?->deleted_at?->toISOString(),
                    'category_id' => $product?->category_id,
                    'category_active' => $product?->category?->is_active,
                    'is_active' => $product?->is_active,
                    'stock' => $product?->stock,
                    'reserved_stock' => $product?->reserved_stock,
                    'price_amount' => $product?->price_amount,
                    'usdt_price_amount' => $product?->usdt_price_amount,
                    'currency' => $product?->currency,
                    'pricing' => $this->productPricingState($product),
                ];
            })->sortBy('product_id')->values()->all(),
        ];

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    public function matchesSnapshot(Cart $cart, ?string $token): bool
    {
        return is_string($token)
            && trim($token) !== ''
            && hash_equals($this->snapshotToken($cart), trim($token));
    }

    private function createOrReuseActive(Session $session, string $clientKey): Cart
    {
        $customer = $this->sessionCustomer($session);
        if ($customer !== null) {
            $cart = $this->carts->currentActiveCartFor($customer);
            if ($cart !== null) {
                return $this->load($cart);
            }
        }

        $customer = Customer::query()->create([
            'telegram_id' => null,
            'first_name' => self::GUEST_CUSTOMER_NAME,
            'username' => null,
        ]);
        $session->put(self::CUSTOMER_ID_KEY, (int) $customer->getKey());
        $session->put(self::CUSTOMER_CLIENT_KEY, $clientKey);

        return $this->load($this->carts->activeCartFor($customer));
    }

    private function sessionCustomer(Session $session): ?Customer
    {
        $clientKey = $this->clientKey($session);
        if ($session->get(self::CUSTOMER_CLIENT_KEY) !== $clientKey) {
            return null;
        }

        $customerId = $session->get(self::CUSTOMER_ID_KEY);
        if (! is_int($customerId) && ! (is_string($customerId) && ctype_digit($customerId))) {
            return null;
        }

        return Customer::query()->find((int) $customerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPricingState(?Product $product): array
    {
        if ($product === null) {
            return [];
        }

        return [
            'updated_at' => $product->updated_at?->toISOString(),
            'price_amount' => $product->price_amount,
            'usdt_price_amount' => $product->usdt_price_amount,
            'currency' => $product->currency,
            'quantity_price_tiers' => $product->quantityPriceTiers()
                ->select(['id', 'product_id', 'minimum_quantity', 'price_amount', 'usdt_price_amount', 'updated_at'])
                ->limit(ProductQuantityPricing::MAX_TIERS + 1)
                ->get()
                ->map(static fn ($tier): array => [
                    'id' => (int) $tier->getKey(),
                    'product_id' => (int) $tier->product_id,
                    'minimum_quantity' => (int) $tier->minimum_quantity,
                    'price_amount' => (int) $tier->price_amount,
                    'usdt_price_amount' => $tier->usdt_price_amount === null ? null : (int) $tier->usdt_price_amount,
                    'updated_at' => $tier->updated_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    private function lockKey(string $clientKey): string
    {
        return 'storefront:guest-cart:'.hash('sha256', $clientKey);
    }
}
