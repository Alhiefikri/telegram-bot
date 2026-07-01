<?php

namespace App\Services\Telegram;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use Throwable;

class TelegramCartIntents
{
    public function __construct(private TelegramBotClient $bot, private CartService $carts) {}

    public function addProductToCart(Customer $customer, string $chatId, int $productId, int $quantity): bool
    {
        if ($quantity < 1) {
            $this->bot->sendMessage($chatId, 'Jumlah tidak valid.');

            return false;
        }

        $cart = null;

        try {
            $product = Product::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->firstOrFail();

            if (($product->stock - $product->reserved_stock) < $quantity) {
                $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');

                return false;
            }

            $cart = $this->carts->activeCartFor($customer);
            $cartItem = $this->carts->setItemQuantity($cart, $product, $quantity);
            if ($cartItem && ! $cartItem->wasRecentlyCreated && ! $cartItem->wasChanged('quantity')) {
                return true;
            }

            $subtotal = $product->price_amount * $quantity;
            $this->bot->sendMessage($chatId, "🛒 Masuk keranjang\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n💰 Subtotal item: Rp".number_format($subtotal, 0, ',', '.')."\n\nSiap checkout? Pilih pembayaran anda.", [
                'inline_keyboard' => [
                    [
                        ['text' => 'DANA', 'callback_data' => 'payment:dana'],
                        ['text' => 'GOPAY', 'callback_data' => 'payment:gopay'],
                        ['text' => 'SHOPEEPAY', 'callback_data' => 'payment:shopepay'],
                    ],
                ],
            ]);

            return true;
        } catch (Throwable) {
            $this->deleteFreshEmptyCart($cart);
            $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');

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
