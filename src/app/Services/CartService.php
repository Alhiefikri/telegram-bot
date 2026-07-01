<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Exceptions\InvalidCartState;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(private readonly InventoryLedger $inventory) {}

    public function activeCartFor(Customer $customer, ?CarbonInterface $expiresAt = null): Cart
    {
        return DB::transaction(function () use ($customer, $expiresAt) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $cart = $this->activeCartQuery($customer)->lockForUpdate()->first();

            if ($cart && $cart->expires_at && $cart->expires_at->isPast()) {
                $cart->update(['status' => CartStatus::Expired]);
                $cart = null;
            }

            return $cart ?: Cart::create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => $expiresAt ?: now()->addHours(24)]);
        }, 5);
    }

    public function currentActiveCartFor(Customer $customer): ?Cart
    {
        return DB::transaction(function () use ($customer) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $cart = $this->activeCartQuery($customer)->lockForUpdate()->first();

            if ($cart && $cart->expires_at && $cart->expires_at->isPast()) {
                $cart->update(['status' => CartStatus::Expired]);

                return null;
            }

            return $cart;
        }, 5);
    }

    public function addItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        if ($quantity <= 0) {
            throw new InvalidCartState('Quantity must be positive.');
        }

        return $this->mutateItem($cart, $product, fn (?CartItem $item) => ($item?->quantity ?? 0) + $quantity);
    }

    public function setItemQuantity(Cart $cart, Product $product, int $quantity): ?CartItem
    {
        if ($quantity < 0) {
            throw new InvalidCartState('Quantity cannot be negative.');
        }

        if ($quantity === 0) {
            $this->removeItem($cart, $product);

            return null;
        }

        return $this->mutateItem($cart, $product, fn () => $quantity);
    }

    public function removeItem(Cart $cart, Product $product): void
    {
        DB::transaction(function () use ($cart, $product) {
            $lockedCart = $this->lockActiveCart($cart);
            Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            CartItem::where('cart_id', $lockedCart->id)->where('product_id', $product->id)->lockForUpdate()->delete();
        }, 5);
    }

    private function activeCartQuery(Customer $customer)
    {
        return Cart::where('customer_id', $customer->id)->where('status', CartStatus::Active);
    }

    private function mutateItem(Cart $cart, Product $product, callable $quantityResolver): CartItem
    {
        return DB::transaction(function () use ($cart, $product, $quantityResolver) {
            $lockedCart = $this->lockActiveCart($cart);
            $lockedProduct = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $item = CartItem::where('cart_id', $lockedCart->id)->where('product_id', $product->id)->lockForUpdate()->first();
            $quantity = $quantityResolver($item);

            $this->inventory->assertCartProductAvailable($lockedProduct, $quantity, $lockedCart->id);

            return CartItem::updateOrCreate(['cart_id' => $lockedCart->id, 'product_id' => $lockedProduct->id], ['quantity' => $quantity]);
        }, 5);
    }

    private function lockActiveCart(Cart $cart): Cart
    {
        $lockedCart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();

        if ($lockedCart->status !== CartStatus::Active || ($lockedCart->expires_at && $lockedCart->expires_at->isPast())) {
            if ($lockedCart->status === CartStatus::Active) {
                $lockedCart->update(['status' => CartStatus::Expired]);
            }
            throw new InvalidCartState('Cart is not active.');
        }

        return $lockedCart;
    }

}
