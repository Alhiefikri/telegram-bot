<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderCheckoutService;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private OrderCheckoutService $checkout,
        private TelegramIntentSession $session,
        private TelegramCatalogIntents $catalog,
        private TelegramCartIntents $cart,
        private TelegramOrderIntents $orders,
        private TelegramCheckoutIntents $checkoutIntents,
        private TelegramManualPaymentIntents $manualPayments,
    ) {}

    public function route(Customer $customer, string $chatId, string $id, string $data): void
    {
        if (preg_match('/^admin:approve-payment:([^\s:]+)$/', $data, $matches)) {
            $this->manualPayments->approveManualPaymentFromTelegram($customer, $chatId, $id, $matches[1]);

            return;
        }

        if (preg_match('/^admin:reject-payment:([^\s:]+)$/', $data, $matches)) {
            $this->manualPayments->rejectManualPaymentFromTelegram($customer, $chatId, $id, $matches[1]);

            return;
        }

        if ($data === 'katalog' || $data === 'back:categories') {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendCategoryCatalog($chatId);

            return;
        }

        if (preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendCategoryProducts($chatId, $matches[1]);

            return;
        }

        if ($data === 'balance') {
            $this->bot->answerCallbackQuery($id, 'Fitur saldo segera hadir.');

            return;
        }

        if ($data === 'status') {
            $this->bot->answerCallbackQuery($id);
            $order = $customer->orders()->latest()->first();
            $this->bot->sendMessage($chatId, $order ? '📦 Status pesanan '.$order->invoice_number.': '.$order->order_status->value.' / '.$order->payment_status->value : 'Belum ada pesanan.');

            return;
        }

        if (preg_match('/^order:([^\s:]+)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->orders->sendOrderDetail($customer, $chatId, $matches[1]);

            return;
        }

        if ($data === 'batal') {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, 'Keranjang aktif dibatalkan.');

            return;
        }

        if (preg_match('/^product:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::whereKey((int) $matches[1])->where('is_active', true)->first();
            $availableStock = $product ? $product->stock - $product->reserved_stock : 0;
            if (! $product || $availableStock < 1) {
                $this->bot->answerCallbackQuery($id, 'Produk tidak tersedia.');

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->catalog->sendStockChoices($chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^buy:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::whereKey((int) $matches[1])->where('is_active', true)->first();
            $availableStock = $product ? $product->stock - $product->reserved_stock : 0;
            if (! $product || $availableStock < 1) {
                $this->bot->answerCallbackQuery($id, 'Produk tidak tersedia.');

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->put($customer, $product->id);
            $this->catalog->sendQuantityChoices($chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^qty:([1-9][0-9]*):([1-9][0-9]*)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->cart->addProductToCart($customer, $chatId, (int) $matches[1], (int) $matches[2]);

            return;
        }

        if ($data === 'checkout') {
            $this->checkout($customer, $chatId, $id, null);

            return;
        }

        if (preg_match('/^payment:(dana|gopay|shopepay)$/', $data, $matches)) {
            $this->checkout($customer, $chatId, $id, $matches[1]);

            return;
        }

        $this->bot->answerCallbackQuery($id, 'Aksi tidak dikenal.');
    }

    private function checkout(Customer $customer, string $chatId, string $id, ?string $manualMethod): void
    {
        $this->bot->answerCallbackQuery($id);

        try {
            $cart = $this->carts->currentActiveCartFor($customer);
            if (! $cart) {
                throw new InvalidCheckout('Cart is empty.');
            }

            $paymentMode = $this->checkoutIntents->paymentMode();
            $order = $paymentMode === 'pakasir' && $manualMethod === null
                ? $this->checkout->checkout($cart, 'hosted', 'pakasir')
                : $this->checkout->checkout($cart, $this->checkoutIntents->manualPaymentMethod($manualMethod), 'manual');
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
            $this->bot->sendMessage($chatId, 'Checkout gagal. Pastikan keranjang tidak kosong.');

            return;
        }

        $payment = $order->payments()->latest()->first();
        if ($payment?->provider === 'pakasir') {
            $this->checkoutIntents->sendPakasirPaymentInstructions($chatId, $order, $payment);
        } else {
            $this->checkoutIntents->sendPaymentInstructions($chatId, $order, $payment);
            $this->checkoutIntents->notifyAdminAboutManualOrder($order);
        }
    }
}
