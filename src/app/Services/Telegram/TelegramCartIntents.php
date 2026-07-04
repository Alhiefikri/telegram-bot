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
            $this->bot->sendMessage($chatId, 'Angkanya belum pas nih. Pilih jumlah sesuai stok yang tersedia ya.');

            return false;
        }

        $cart = null;

        try {
            $product = Product::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->firstOrFail();

            if ($product->availableStock() < $quantity) {
                $this->bot->sendMessage($chatId, 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.');

                return false;
            }

            $cart = $this->carts->activeCartFor($customer);
            $cartItem = $this->carts->setItemQuantity($cart, $product, $quantity);
            if ($cartItem && ! $cartItem->wasRecentlyCreated && ! $cartItem->wasChanged('quantity')) {
                return true;
            }

            $subtotal = $product->price_amount * $quantity;
            $this->bot->sendMessage($chatId, "🛒 Masuk keranjang\n━━━━━━━━━━━━━━\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n💰 Subtotal: Rp".number_format($subtotal, 0, ',', '.')."\n\nSudah cocok? Lanjutkan checkout di bawah.", [
                'inline_keyboard' => [
                    [
                        ['text' => '💳 Lanjut Checkout', 'callback_data' => 'checkout'],
                    ],
                ],
            ]);

            return true;
        } catch (Throwable) {
            $this->deleteFreshEmptyCart($cart);
            $this->bot->sendMessage($chatId, 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.');

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
