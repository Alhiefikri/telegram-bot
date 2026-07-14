<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCartState;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Support\DogfoodTiming;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramBotClient $bot,
        private CartService $carts,
        private OrderCheckoutService $checkout,
        private OrderStateMachine $orderStates,
        private TelegramIntentSession $session,
        private TelegramCatalogIntents $catalog,
        private TelegramCartIntents $cart,
        private TelegramOrderIntents $orders,
        private TelegramCheckoutIntents $checkoutIntents,
        private TelegramManualPaymentIntents $manualPayments,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramAdminAccess $adminAccess,
        private TelegramAdminStatsIntents $adminStats,
        private TelegramBroadcastIntents $broadcasts,
    ) {}

    public function route(Customer $customer, string $chatId, string $id, string $data, string $chatType = 'private'): void
    {
        if (preg_match('/^locale:(id|en)$/', $data, $matches)) {
            $customer->update(['locale' => $matches[1]]);
            $customer->refresh();
            $this->bot->answerCallbackQuery($id);
            $this->menu->sendWelcome($customer, $chatId);
            $this->menu->sendMainMenu($customer, $chatId, $this->chatPrivacy->isPrivate($chatType));
            return;
        }
        if ($data === TelegramCommunityGate::VERIFY_CALLBACK) {
            $this->session->forget($customer);
            if ($this->communityGate->verify($customer, $chatId)) {
                $this->bot->answerCallbackQuery($id);
            } else {
                $this->bot->answerCallbackQuery($id, 'Kamu belum terdeteksi join channel nih.');
            }

            return;
        }

        if (preg_match('/^admin:approve-payment:([^\s:]+)$/', $data, $matches)) {
            $this->manualPayments->approveManualPaymentFromTelegram($customer, $chatId, $id, $matches[1]);

            return;
        }

        if (preg_match('/^admin:reject-payment:([^\s:]+)$/', $data, $matches)) {
            $this->manualPayments->rejectManualPaymentFromTelegram($customer, $chatId, $id, $matches[1]);

            return;
        }

        if (! $this->chatPrivacy->isPrivate($chatType) && ! $this->isNonPrivateSafeCallback($data)) {
            $this->chatPrivacy->denyCallback($id);

            return;
        }

        if ($this->broadcasts->handleCallback($customer, $chatId, $id, $data)) {
            return;
        }

        if ($data === 'statistik_harian') {
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->answerCallbackQuery($id, 'Akses ditolak.');

                return;
            }

            $this->adminStats->showDailyStats($customer, $chatId, $id);

            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forget($customer);
            $this->bot->answerCallbackQuery($id, 'Join Store Channel dulu ya biar bisa lanjut belanja.');

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
            $this->bot->sendMessage($chatId, $order ? "📦 Status pesanan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Order: {$order->order_status->value}\n💳 Payment: {$order->payment_status->value}" : 'Belum ada pesanan nih.');

            return;
        }

        if (preg_match('/^order:([^\s:]+)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->orders->sendOrderDetail($customer, $chatId, $matches[1]);

            return;
        }

        if (preg_match('/^buyer:cancel-pending:([0-9a-fA-F-]+)$/', $data, $matches)) {
            $this->cancelPendingOrder($customer, $chatId, $id, $matches[1]);

            return;
        }

        if ($data === 'batal') {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.");

            return;
        }

        if (preg_match('/^product:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, 'Produk ini belum bisa dibeli sekarang.');

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendStockChoices($chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^buy:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, 'Produk ini belum bisa dibeli sekarang.');

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->put($customer, $product->id);
            $this->catalog->sendQuantityChoices($chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^qty:([1-9][0-9]*):([1-9][0-9]*)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $productId = (int) $matches[1];
            $selectedProductId = $this->session->getSelectedProductId($customer);
            $this->session->forget($customer);
            $this->cart->addProductToCart($customer, $chatId, $productId, (int) $matches[2], (int) $selectedProductId === $productId);

            return;
        }

        if ($data === 'checkout') {
            $this->choosePaymentMethod($customer, $chatId, $id);
            return;
        }

        if (in_array($data, ['checkout:current', 'checkout:binance'], true)) {
            $this->checkout($customer, $chatId, $id, $data === 'checkout:binance');

            return;
        }

        if (str_starts_with($data, 'payment-proof:')) {
            $this->bot->answerCallbackQuery($id, 'Send a photo with /payment INVOICE in its caption.');

            return;
        }

        $this->bot->answerCallbackQuery($id, 'Aksi ini belum dikenali.');
    }

    private function isNonPrivateSafeCallback(string $data): bool
    {
        return in_array($data, ['katalog', 'back:categories', 'balance'], true)
            || preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $data) === 1
            || preg_match('/^product:([1-9][0-9]*)$/', $data) === 1;
    }

    private function cancelPendingOrder(Customer $customer, string $chatId, string $id, string $orderId): void
    {
        $this->session->forget($customer);

        $order = $customer->orders()
            ->whereKey($orderId)
            ->where('order_status', OrderStatus::PendingPayment->value)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereDoesntHave('payments', fn ($query) => $query->whereNotNull('payment_proof_submitted_at'))
            ->first();

        if (! $order) {
            $this->bot->answerCallbackQuery($id, 'Pesanan sudah tidak bisa dibatalkan.');
            $this->bot->sendMessage($chatId, "Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.\n\nCek status terbaru lewat /status.");

            return;
        }

        try {
            $cancelled = $this->orderStates->cancelPendingOrder($order);
        } catch (InvalidOrderTransition) {
            $this->bot->answerCallbackQuery($id, 'Pesanan sudah tidak bisa dibatalkan.');
            $this->bot->sendMessage($chatId, "Pesanan sudah diproses atau tidak bisa dibatalkan lagi.\n\nCek status terbaru lewat /status.");

            return;
        }

        $this->bot->answerCallbackQuery($id, 'Order dibatalkan dan stok sudah dilepas.');
        $this->bot->sendMessage($chatId, "❌ Pesanan dibatalkan\n━━━━━━━━━━━━━━\nInvoice {$cancelled->invoice_number} dibatalkan dan stok sudah dilepas kembali.\n\nKalau mau pesan lagi, buka /produk ya.");
    }

    private function choosePaymentMethod(Customer $customer, string $chatId, string $id): void
    {
        $cart = $this->carts->currentActiveCartFor($customer);
        if (! $cart) { $this->checkout($customer, $chatId, $id); return; }
        $cart->load('cartItems.product');
        if (! $this->checkoutIntents->binancePayEnabled() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
            $this->checkout($customer, $chatId, $id);
            return;
        }
        $micro = $cart->cartItems->sum(fn ($item) => $item->product->usdt_price_amount * $item->quantity);
        $buttons = [
            [['text' => '💳 QRIS', 'callback_data' => 'checkout:current']],
            [['text' => '💎 USDT · Binance Pay ('.$this->checkoutIntents->formatUsdt($micro).' USDT)', 'callback_data' => 'checkout:binance']],
        ];
        $this->bot->answerCallbackQuery($id);
        $this->bot->sendMessage($chatId, $customer->locale === 'en' ? 'Choose a payment method:' : 'Pilih metode pembayaran:', ['inline_keyboard' => $buttons]);
    }

    private function checkout(Customer $customer, string $chatId, string $id, bool $binance = false): void
    {
        $this->bot->answerCallbackQuery($id);

        try {
            $cart = $this->carts->currentActiveCartFor($customer);
            if (! $cart) {
                throw new InvalidCheckout('Cart is empty.');
            }

            $requestedPaymentMode = $this->checkoutIntents->requestedPaymentMode();
            $paymentMode = $this->checkoutIntents->paymentMode();
            $order = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => 'order_checkout',
                'payment_mode' => $paymentMode,
                'customer_correlation_id' => DogfoodTiming::correlation($customer->telegram_id),
                'cart_correlation_id' => DogfoodTiming::correlation($cart->id),
            ], fn () => $binance
                ? $this->checkout->checkout($cart, 'BINANCE_PAY_ID', 'manual')
                : ($paymentMode === 'pakasir'
                    ? $this->checkout->checkout($cart, $this->checkoutIntents->pakasirPaymentMethod(), 'pakasir')
                    : $this->checkout->checkout($cart, $this->checkoutIntents->manualPaymentMethod(), 'manual')));
        } catch (InvalidCheckout|InvalidCartState|InsufficientStock) {
            $this->bot->sendMessage($chatId, "Checkout belum berhasil.\nKeranjang kosong, stok berubah, atau produknya sudah tidak tersedia.\n\nCoba buka /produk lagi dan pilih produk ulang ya.");

            return;
        }

        if ($requestedPaymentMode === 'pakasir' && $paymentMode === 'manual') {
            $this->bot->sendMessage($chatId, $this->checkoutIntents->pakasirUnavailableCustomerMessage());
        }

        $payment = $order->payments()->latest()->first();
        if ($payment?->method === 'BINANCE_PAY_ID') {
            $this->checkoutIntents->sendBinancePaymentInstructions($chatId, $order, $payment, $customer);
            SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
        } elseif ($payment?->provider === 'pakasir') {
            $this->checkoutIntents->sendPakasirPaymentInstructions($chatId, $order, $payment);
            SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
        } else {
            SendManualCheckoutNotifications::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
            SendAdminOrderPendingNotification::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
        }
    }
}
