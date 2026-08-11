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
            $this->bot->sendMessage($chatId, $this->locale->text($customer, 'Angkanya belum pas. Pilih jumlah sesuai stok yang tersedia.', 'That quantity is not available. Please choose a quantity within the available stock.'));

            return false;
        }

        $cart = null;

        try {
            $product = Product::query()
                ->whereKey($productId)
                ->where('is_active', true)
                ->firstOrFail();

            if ($product->availableStock() < $quantity) {
                $this->bot->sendMessage($chatId, $this->locale->text($customer, 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.', 'This product could not be added to your cart because stock changed or is no longer available.'));

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
                ? $this->locale->text($customer, "🛒 Masuk keranjang\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n💰 Subtotal: Rp".number_format($subtotal, 0, ',', '.')."\n\nCheckout sedang maintenance. Cek status untuk melihat kapan Order baru bisa dibuat.", "🛒 Added to cart\n📦 {$product->name}\n🔢 Quantity: {$quantity}\n💰 Subtotal: Rp".number_format($subtotal, 0, ',', '.')."\n\nCheckout is under maintenance. Check the status to see when new Orders can be created.")
                    .$this->locale->checkoutMaintenanceNote($customer, $maintenance->publicNote)
                : $this->locale->text($customer, "🛒 Masuk keranjang\n📦 {$product->name}\n🔢 Jumlah: {$quantity}\n💰 Subtotal: Rp".number_format($subtotal, 0, ',', '.')."\n\nSudah cocok? Lanjutkan checkout di bawah.", "🛒 Added to cart\n📦 {$product->name}\n🔢 Quantity: {$quantity}\n💰 Subtotal: Rp".number_format($subtotal, 0, ',', '.')."\n\nLooks right? Continue to checkout below.");
            $this->bot->sendMessage($chatId, $message, [
                'inline_keyboard' => [[
                    [
                        'text' => $checkoutMaintenanceActive
                            ? $this->locale->text($customer, '🔄 Cek Status Checkout', '🔄 Check Checkout Status')
                            : $this->locale->text($customer, '💳 Lanjut Checkout', '💳 Continue to Checkout'),
                        'callback_data' => $checkoutMaintenanceActive ? 'checkout-maintenance:status' : 'checkout',
                    ],
                ]],
            ]);

            return true;
        } catch (Throwable) {
            $this->deleteFreshEmptyCart($cart);
            $this->bot->sendMessage($chatId, $this->locale->text($customer, 'Produk belum bisa masuk keranjang. Stoknya berubah atau sudah tidak tersedia.', 'This product could not be added to your cart because stock changed or is no longer available.'));

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
