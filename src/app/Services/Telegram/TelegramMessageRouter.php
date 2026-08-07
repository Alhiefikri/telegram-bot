<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Arr;

class TelegramMessageRouter
{
    public function __construct(
        private TelegramBotClient $bot,
        private TelegramIntentSession $session,
        private TelegramMenuIntents $menu,
        private TelegramCatalogIntents $catalog,
        private TelegramCartIntents $cart,
        private TelegramOrderIntents $orders,
        private TelegramManualPaymentIntents $manualPayments,
        private TelegramAdminAccess $adminAccess,
        private TelegramAdminStatsIntents $adminStats,
        private TelegramBroadcastIntents $broadcasts,
        private TelegramCommunityGate $communityGate,
        private TelegramChatPrivacyGuard $chatPrivacy,
        private TelegramLocale $locale,
        private TelegramBinanceVerifyIntents $binanceVerify,
    ) {}

    public function route(Customer $customer, string $chatId, array $message, string $chatType = 'private'): void
    {
        $text = trim((string) (Arr::get($message, 'text') ?: Arr::get($message, 'caption', '')));
        $isPrivateChat = $this->chatPrivacy->isPrivate($chatType);
        if (! $isPrivateChat && $this->isPrivateOnlyCommand($text)) {
            $this->session->forget($customer);
            $this->chatPrivacy->denyMessage($customer, $chatId);

            return;
        }

        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->session->forget($customer);
            if ($customer->locale === null) {
                $this->menu->sendLanguagePicker($chatId);

                return;
            }
            if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
                return;
            }
            if (($productId = $this->startProductId($text)) !== null) {
                $product = Product::publicVisible()->whereKey($productId)->first();
                if ($product) {
                    $this->catalog->sendStockChoices($customer, $chatId, $product, $product->availableStock());

                    return;
                }
            }
            $this->menu->sendWelcome($customer, $chatId, $isPrivateChat);
            $this->menu->sendMainMenu($customer, $chatId, $isPrivateChat);

            return;
        }

        if (in_array($text, ['/language', '/bahasa', '🌐 Language', '🌐 Bahasa'], true)) {
            $this->menu->sendLanguagePicker($chatId);

            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forget($customer);

            return;
        }

        if (in_array($text, ['📊 Statistik', '📊 Statistik Harian'], true)) {
            $this->session->forget($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, $this->text($customer, 'Akses ditolak.', 'Access denied.'));

                return;
            }

            $this->adminStats->showStoreGrowth($customer, $chatId);

            return;
        }

        if ($text === '📦 Cek Stok' || $text === '/stok') {
            $this->session->forget($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, $this->text($customer, 'Akses ditolak.', 'Access denied.'));

                return;
            }

            $this->adminStats->showProductStock($chatId);

            return;
        }

        if (in_array($text, ['/produk', '/products', '/lihat_produk', '/lihat produk', '/katalog', '🛍️ Lihat Produk', '🛍️ Browse Products'], true)) {
            $this->session->forget($customer);
            $this->catalog->sendCategoryCatalog($customer, $chatId);

            return;
        }

        if (in_array($text, ['/menu', '☰ Menu'], true)) {
            $this->session->forget($customer);
            $this->menu->sendMainMenu($customer, $chatId, $isPrivateChat);

            return;
        }

        if (in_array($text, ['/support', '💬 Support'], true)) {
            $this->session->forget($customer);
            $this->menu->sendSupport($customer, $chatId);

            return;
        }

        if ($text === '📣 Broadcast Info') {
            $this->session->forget($customer);
            $this->broadcasts->handleMenu($customer, $chatId);

            return;
        }

        if ($text === '/broadcast' || str_starts_with($text, '/broadcast ')) {
            $this->session->forget($customer);
            $this->broadcasts->handleCommand($customer, $chatId, $text);

            return;
        }

        if (in_array($text, ['/akun', '/account', '👤 Akun Saya', '👤 My Account'], true)) {
            $this->session->forget($customer);
            $this->menu->sendAccountSummary($customer, $chatId);

            return;
        }

        if ($text === '/cari' || str_starts_with($text, '/cari ') || $text === '/search' || str_starts_with($text, '/search ')) {
            $this->session->forget($customer);
            $command = str_starts_with($text, '/search') ? '/search' : '/cari';
            $keyword = trim(substr($text, strlen($command)));
            if ($keyword === '') {
                $this->bot->sendMessage($chatId, $this->text($customer, "Mau cari produk apa?\n\nKetik seperti ini:\n/cari netflix\n\nBisa pakai nama produk, kategori, atau kata kunci lain.", "What product are you looking for?\n\nType it like this:\n/search netflix\n\nYou can use a product name, category, or other keyword."));

                return;
            }

            $this->catalog->sendSearchResults($customer, $chatId, $keyword);

            return;
        }

        if ($text === '/cancel' || str_starts_with($text, '/cancel ')) {
            $this->session->forget($customer);
            $this->manualPayments->cancelPendingOrderFromTelegram($customer, $chatId, trim(substr($text, strlen('/cancel'))));

            return;
        }

        if ($text === '/batal') {
            $this->session->forget($customer);
            $this->binanceVerify->cancelPrompt($customer);
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, $this->text($customer, "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.", "🛒 Your active cart has been cancelled.\n\nOpen /products to browse again."));

            return;
        }

        if ($text === '/payment' || str_starts_with($text, '/payment ')) {
            $this->session->forget($customer);
            $this->manualPayments->submitManualPaymentProof($customer, $chatId, $message, trim(substr($text, strlen('/payment'))));

            return;
        }

        if ($isPrivateChat && $text === '' && $this->manualPayments->rememberPendingProofPhoto($customer, $message)) {
            $this->bot->sendMessage($chatId, $this->text(
                $customer,
                '📸 Screenshot bukti sudah diterima. Sekarang kirim /payment untuk meneruskannya ke admin.',
                '📸 Your payment screenshot has been received. Now send /payment to forward it to the admin.',
            ));

            return;
        }

        if (in_array($text, ['/status', '/pesanan', '/orders', '📦 Pesanan', '📦 Orders'], true)) {
            $this->session->forget($customer);
            $order = $customer->orders()->latest()->first();
            $orderStatus = $order ? $this->locale->orderStatus($customer, $order->order_status) : null;
            $paymentStatus = $order ? $this->locale->paymentStatus($customer, $order->payment_status) : null;
            $this->bot->sendMessage($chatId, $order ? $this->text($customer, "📦 Status pesanan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Pesanan: {$orderStatus}\n💳 Pembayaran: {$paymentStatus}\n\nMau lihat semua riwayat? Ketik /riwayat\nMau detail order? Ketik /order {$order->invoice_number}", "📦 Order status\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Order: {$orderStatus}\n💳 Payment: {$paymentStatus}\n\nView your history with /history\nView this order with /order {$order->invoice_number}") : $this->text($customer, "Belum ada pesanan nih.\n\nBuka /produk dulu untuk mulai belanja 🛍️", "You do not have any orders yet.\n\nOpen /products to start shopping 🛍️"));

            return;
        }

        if (in_array($text, ['/riwayat', '/history'], true)) {
            $this->session->forget($customer);
            $this->orders->sendOrderHistory($customer, $chatId);

            return;
        }

        if ($text === '/order' || str_starts_with($text, '/order ')) {
            $this->session->forget($customer);
            $invoice = trim(substr($text, strlen('/order')));
            if ($invoice === '') {
                $this->bot->sendMessage($chatId, $this->text($customer, "Ketik nomor invoice setelah command ya.\n\nContoh:\n/order INV-12345678", "Type the invoice number after the command.\n\nExample:\n/order INV-12345678"));

                return;
            }
            $this->orders->sendOrderDetail($customer, $chatId, $invoice);

            return;
        }

        if ($isPrivateChat && $this->binanceVerify->handleOrderIdInput($customer, $chatId, $text)) {
            return;
        }

        $productId = $this->session->getSelectedProductId($customer);
        if ($productId !== null && ! $isPrivateChat) {
            $this->chatPrivacy->denyMessage($customer, $chatId);

            return;
        }

        if ($productId !== null) {
            if (ctype_digit($text) && $this->cart->addProductToCart($customer, $chatId, (int) $productId, (int) $text, true)) {
                $this->session->forget($customer);

                return;
            }

            if (! ctype_digit($text)) {
                $this->bot->sendMessage($chatId, $this->text($customer, 'Angkanya belum pas. Pilih jumlah sesuai stok yang tersedia.', 'That quantity is not available. Please choose a quantity within the available stock.'));
            }

            return;
        }

        $this->session->forget($customer);
        $this->bot->sendMessage($chatId, $this->text($customer, "Aku belum paham perintah itu 😅\n\nCoba buka /produk untuk belanja, atau /support kalau butuh bantuan.", "I did not understand that command 😅\n\nOpen /products to shop or /support if you need help."));
    }

    private function isPrivateOnlyCommand(string $text): bool
    {
        return in_array($text, ['/akun', '/account', '👤 Akun Saya', '👤 My Account', '/batal', '/payment', '/status', '/pesanan', '/orders', '📦 Pesanan', '📦 Orders', '/riwayat', '/history', '/order', '/broadcast', '📣 Broadcast Info', '📊 Statistik', '📊 Statistik Harian', '📦 Cek Stok', '/stok'], true)
            || str_starts_with($text, '/payment ')
            || str_starts_with($text, '/order ')
            || str_starts_with($text, '/broadcast ');
    }

    private function text(Customer $customer, string $id, string $en): string
    {
        return $this->locale->text($customer, $id, $en);
    }

    private function startProductId(string $text): ?int
    {
        if (preg_match('/^\/start\s+product_([1-9][0-9]*)$/', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
