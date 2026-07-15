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
use App\Jobs\SendBinanceCheckoutInstructions;
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
        private TelegramMenuIntents $menu,
        private TelegramCheckoutIntents $checkoutIntents,
        private TelegramManualPaymentIntents $manualPayments,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramAdminAccess $adminAccess,
        private TelegramAdminStatsIntents $adminStats,
        private TelegramBroadcastIntents $broadcasts,
        private TelegramLocale $locale,
    ) {}

    public function route(Customer $customer, string $chatId, string $id, string $data, string $chatType = 'private'): void
    {
        if (preg_match('/^locale:(id|en)$/', $data, $matches)) {
            $this->communityGate->clearPrompt($customer);
            $customer->update(['locale' => $matches[1]]);
            $customer->refresh();
            $this->bot->answerCallbackQuery($id);
            if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
                return;
            }
            $this->menu->sendWelcome($customer, $chatId);
            $this->menu->sendMainMenu($customer, $chatId, $this->chatPrivacy->isPrivate($chatType));

            return;
        }
        if ($data === TelegramCommunityGate::VERIFY_CALLBACK) {
            $this->session->forget($customer);
            if ($this->communityGate->verify($customer, $chatId)) {
                $this->bot->answerCallbackQuery($id);
            } else {
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Kamu belum terdeteksi join channel nih.', 'You have not joined the channel yet.'));
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
            $this->chatPrivacy->denyCallback($customer, $id);

            return;
        }

        if ($this->broadcasts->handleCallback($customer, $chatId, $id, $data)) {
            return;
        }

        if ($data === 'statistik_harian') {
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Akses ditolak.', 'Access denied.'));

                return;
            }

            $this->adminStats->showDailyStats($customer, $chatId, $id);

            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forget($customer);
            $this->bot->answerCallbackQuery($id, $this->text($customer, 'Join Store Channel dulu ya biar bisa lanjut belanja.', 'Join the Store Channel first to continue shopping.'));

            return;
        }

        if ($data === 'katalog' || $data === 'back:categories') {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendCategoryCatalog($customer, $chatId);

            return;
        }

        if (preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $data, $matches)) {
            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendCategoryProducts($customer, $chatId, $matches[1]);

            return;
        }

        if ($data === 'balance') {
            $this->bot->answerCallbackQuery($id, $this->text($customer, 'Fitur saldo segera hadir.', 'Balance is coming soon.'));

            return;
        }

        if ($data === 'status') {
            $this->bot->answerCallbackQuery($id);
            $order = $customer->orders()->latest()->first();
            $orderStatus = $order ? $this->locale->orderStatus($customer, $order->order_status) : null;
            $paymentStatus = $order ? $this->locale->paymentStatus($customer, $order->payment_status) : null;
            $this->bot->sendMessage($chatId, $order ? $this->text($customer, "📦 Status pesanan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Pesanan: {$orderStatus}\n💳 Pembayaran: {$paymentStatus}", "📦 Order status\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Order: {$orderStatus}\n💳 Payment: {$paymentStatus}") : $this->text($customer, 'Belum ada pesanan nih.', 'You do not have any orders yet.'));

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
            $this->bot->sendMessage($chatId, $this->text($customer, "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.", "🛒 Your active cart has been cancelled.\n\nOpen /products to browse again."));

            return;
        }

        if (preg_match('/^product:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Produk ini belum bisa dibeli sekarang.', 'This product cannot be purchased right now.'));

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            $this->catalog->sendStockChoices($customer, $chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^buy:(qris|binance):([1-9][0-9]*)$/', $data, $matches)) {
            $paymentMethod = $matches[1];
            $product = Product::publicVisible()->whereKey((int) $matches[2])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Produk ini belum bisa dibeli sekarang.', 'This product cannot be purchased right now.'));

                return;
            }
            if ($paymentMethod === 'binance' && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Binance Pay sedang tidak tersedia untuk produk ini.', 'Binance Pay is not available for this product.'));

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->put($customer, $product->id);
            $this->catalog->sendQuantityChoices($customer, $chatId, $product, $availableStock, $paymentMethod);

            return;
        }

        if (preg_match('/^buy:([1-9][0-9]*)$/', $data, $matches)) {
            $product = Product::publicVisible()->whereKey((int) $matches[1])->first();
            $availableStock = $product ? $product->availableStock() : 0;
            if (! $product || $availableStock < 1) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Produk ini belum bisa dibeli sekarang.', 'This product cannot be purchased right now.'));

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->put($customer, $product->id);
            $this->catalog->sendQuantityChoices($customer, $chatId, $product, $availableStock);

            return;
        }

        if (preg_match('/^qty:(qris|binance):([1-9][0-9]*):([1-9][0-9]*)$/', $data, $matches)) {
            $paymentMethod = $matches[1];
            $productId = (int) $matches[2];
            $quantity = (int) $matches[3];
            $product = Product::publicVisible()->whereKey($productId)->first();
            if (! $product || $product->availableStock() < $quantity) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Produk atau jumlah ini sudah tidak tersedia.', 'This product or quantity is no longer available.'));

                return;
            }
            if ($paymentMethod === 'binance' && ($product->usdt_price_amount === null || ! $this->checkoutIntents->binancePayEnabled())) {
                $this->session->forget($customer);
                $this->bot->answerCallbackQuery($id, $this->text($customer, 'Binance Pay sedang tidak tersedia untuk produk ini.', 'Binance Pay is not available for this product.'));

                return;
            }

            $this->bot->answerCallbackQuery($id);
            $this->session->forget($customer);
            if ($this->cart->addProductToCart($customer, $chatId, $productId, $quantity, true, false)) {
                $this->checkout($customer, $chatId, $id, $paymentMethod === 'binance', false);
            }

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
            $this->bot->answerCallbackQuery($id, $this->text($customer, 'Kirim foto bukti pembayaran dengan /payment INVOICE di caption.', 'Send a photo with /payment INVOICE in its caption.'));

            return;
        }

        $this->bot->answerCallbackQuery($id, $this->text($customer, 'Aksi ini belum dikenali.', 'This action is not recognized.'));
    }

    private function isNonPrivateSafeCallback(string $data): bool
    {
        return in_array($data, ['katalog', 'back:categories', 'balance'], true)
            || preg_match('/^category:([1-9][0-9]*|uncategorized)$/', $data) === 1
            || preg_match('/^product:([1-9][0-9]*)$/', $data) === 1;
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
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
            $this->bot->answerCallbackQuery($id, $this->text($customer, 'Pesanan sudah tidak bisa dibatalkan.', 'This order can no longer be cancelled.'));
            $this->bot->sendMessage($chatId, $this->text($customer, "Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.\n\nCek status terbaru lewat /status.", "Order not found or can no longer be cancelled.\n\nCheck the latest status with /status."));

            return;
        }

        try {
            $cancelled = $this->orderStates->cancelPendingOrder($order);
        } catch (InvalidOrderTransition) {
            $this->bot->answerCallbackQuery($id, $this->text($customer, 'Pesanan sudah tidak bisa dibatalkan.', 'This order can no longer be cancelled.'));
            $this->bot->sendMessage($chatId, $this->text($customer, "Pesanan sudah diproses atau tidak bisa dibatalkan lagi.\n\nCek status terbaru lewat /status.", "This order has been processed or can no longer be cancelled.\n\nCheck the latest status with /status."));

            return;
        }

        $this->bot->answerCallbackQuery($id, $this->text($customer, 'Order dibatalkan dan stok sudah dilepas.', 'Order cancelled and stock released.'));
        $this->bot->sendMessage($chatId, $this->text($customer, "❌ Pesanan dibatalkan\n━━━━━━━━━━━━━━\nInvoice {$cancelled->invoice_number} dibatalkan dan stok sudah dilepas kembali.\n\nKalau mau pesan lagi, buka /produk ya.", "❌ Order cancelled\n━━━━━━━━━━━━━━\nInvoice {$cancelled->invoice_number} was cancelled and the stock was released.\n\nOpen /products if you would like to order again."));
    }

    private function choosePaymentMethod(Customer $customer, string $chatId, string $id): void
    {
        $cart = $this->carts->currentActiveCartFor($customer);
        if (! $cart) {
            $this->checkout($customer, $chatId, $id);

            return;
        }
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
        $this->bot->sendMessage($chatId, $this->text($customer, 'Pilih metode pembayaran:', 'Choose a payment method:'), ['inline_keyboard' => $buttons]);
    }

    private function checkout(Customer $customer, string $chatId, string $id, bool $binance = false, bool $answerCallback = true): void
    {
        if ($answerCallback) {
            $this->bot->answerCallbackQuery($id);
        }

        try {
            $cart = $this->carts->currentActiveCartFor($customer);
            if (! $cart) {
                throw new InvalidCheckout('Cart is empty.');
            }
            if ($binance) {
                $cart->load('cartItems.product');
                if (! $this->checkoutIntents->binancePayEnabled() || ! $cart->cartItems->every(fn ($item) => $item->product?->usdt_price_amount !== null)) {
                    $this->bot->sendMessage($chatId, $this->text($customer, 'Binance Pay sedang tidak tersedia untuk keranjang ini. Pilih QRIS untuk melanjutkan.', 'Binance Pay is not available for this cart. Choose QRIS to continue.'), [
                        'inline_keyboard' => [[['text' => '💳 QRIS', 'callback_data' => 'checkout:current']]],
                    ]);

                    return;
                }
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
            $this->bot->sendMessage($chatId, $this->text($customer, "Checkout belum berhasil.\nKeranjang kosong, stok berubah, atau produknya sudah tidak tersedia.\n\nCoba buka /produk lagi dan pilih produk ulang ya.", "Checkout could not be completed.\nYour cart is empty, stock changed, or the product is no longer available.\n\nOpen /products and choose the product again."));

            return;
        }

        if ($requestedPaymentMode === 'pakasir' && $paymentMode === 'manual') {
            $this->bot->sendMessage($chatId, $this->checkoutIntents->pakasirUnavailableCustomerMessage($customer));
        }

        $payment = $order->payments()->latest()->first();
        if ($payment?->method === 'BINANCE_PAY_ID') {
            SendBinanceCheckoutInstructions::dispatch($order->id, now()->toISOString())->onQueue('telegram-outbound')->afterCommit();
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
