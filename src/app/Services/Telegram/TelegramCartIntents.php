<?php

namespace App\Services\Telegram;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use Throwable;

class TelegramCartIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private TelegramLocale $locale,
        private CheckoutMaintenance $maintenance,
    ) {}

    public function addProductToCart(Customer $customer, string $chatId, int $productId, int $quantity, bool $forceConfirmation = false, bool $sendConfirmation = true): bool
    {
        if ($quantity < 1) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.quantity_invalid'));

            return false;
        }

        $cart = null;

        try {
            $product = Product::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->firstOrFail();

            if ($product->availableStock() < $quantity) {
                $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.stock_conflict'));

                return false;
            }

            $cart = $this->carts->activeCartFor($customer);
            $existingItem = $cart->cartItems()->where('product_id', $product->id)->first();
            if (! $forceConfirmation && $existingItem && (int) $existingItem->quantity === $quantity) {
                return true;
            }

            $this->carts->setItemQuantity($cart, $product, $quantity);
            $maintenance = $this->maintenance->current();
            $checkoutMaintenanceActive = $maintenance->active;
            if (! $sendConfirmation) {
                return true;
            }

            $subtotal = $product->price_amount * $quantity;
            $message = $checkoutMaintenanceActive
                ? $this->locale->translate($customer, 'cart.added_maintenance', [
                    'product' => $product->name,
                    'quantity' => $quantity,
                    'subtotal' => number_format($subtotal, 0, ',', '.'),
                ]).$this->locale->checkoutMaintenanceNote($customer, $maintenance->publicNote)
                : $this->locale->translate($customer, 'cart.added', [
                    'product' => $product->name,
                    'quantity' => $quantity,
                    'subtotal' => number_format($subtotal, 0, ',', '.'),
                ]);
            $this->bot->sendMessage($chatId, $message, [
                'inline_keyboard' => [[
                    [
                        'text' => $checkoutMaintenanceActive
                            ? $this->locale->translate($customer, 'cart.status_button')
                            : $this->locale->translate($customer, 'cart.checkout_button'),
                        'callback_data' => $checkoutMaintenanceActive ? 'checkout-maintenance:status' : 'checkout',
                    ],
                ]],
            ]);

            return true;
        } catch (Throwable) {
            $this->deleteFreshEmptyCart($cart);
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'cart.stock_conflict'));

            return false;
        }
    }

    private function deleteFreshEmptyCart(?Cart $cart): void
    {
        if ($cart?->wasRecentlyCreated && ! $cart->cartItems()->exists()) {
            $cart->delete();
        }
    }
}
