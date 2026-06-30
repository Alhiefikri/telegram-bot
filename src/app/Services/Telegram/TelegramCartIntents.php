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
            $this->bot->sendMessage($chatId, "🛒 Masuk keranjang\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n\nSiap checkout? Pilih pembayaran anda.", [
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
            $this->bot->sendMessage($chatId, 'Produk tidak tersedia atau stok tidak cukup.');

            return false;
        }
    }
}
