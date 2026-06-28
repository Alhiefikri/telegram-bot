<?php

namespace App\Services\Telegram;

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

        try {
            $product = Product::findOrFail($productId);
            $cart = $this->carts->activeCartFor($customer);
            $this->carts->addItem($cart, $product, $quantity);
            $this->bot->sendMessage($chatId, "🛒 Masuk keranjang\n━━━━━━━━━━━━━━\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n\nSiap checkout?", ['inline_keyboard' => [[['text' => '💳 Pembayaran', 'callback_data' => 'checkout']]]]);

            return true;
        } catch (Throwable) {
            $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');

            return false;
        }
    }
}
