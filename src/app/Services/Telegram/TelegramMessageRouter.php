<?php

namespace App\Services\Telegram;

use App\Enums\CartStatus;
use App\Models\Customer;
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
        private TelegramBroadcastService $broadcast,
        private TelegramCommunityGate $communityGate,
    ) {}

    public function route(Customer $customer, string $chatId, array $message): void
    {
        $text = trim((string) (Arr::get($message, 'text') ?: Arr::get($message, 'caption', '')));

        if ($text === '/start') {
            $this->session->forget($customer);
            $this->menu->sendWelcome($customer, $chatId);
            $this->menu->sendMainMenu($customer, $chatId);

            return;
        }

        if (! $this->communityGate->ensureAllowedOrPrompt($customer, $chatId)) {
            $this->session->forget($customer);

            return;
        }

        if (in_array($text, ['/produk', '/lihat_produk', '/lihat produk', '/katalog', '🛍️ Lihat Produk', '🛍️ Marketplace'], true)) {
            $this->session->forget($customer);
            $this->catalog->sendCategoryCatalog($chatId);

            return;
        }

        if (in_array($text, ['/menu', '☰ Menu'], true)) {
            $this->session->forget($customer);
            $this->menu->sendMainMenu($customer, $chatId);

            return;
        }

        if (in_array($text, ['/support', '💬 Support'], true)) {
            $this->session->forget($customer);
            $this->menu->sendSupport($chatId);

            return;
        }

        if ($text === '📣 Broadcast Info') {
            $this->session->forget($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, 'Akses admin diperlukan.');

                return;
            }

            $this->bot->sendMessage($chatId, "📣 Broadcast Info\n━━━━━━━━━━━━━━\nKirim pesan broadcast dengan format:\n\n/broadcast isi pesan\n\nContoh:\n/broadcast Stok Netflix sudah ready lagi.");

            return;
        }

        if ($text === '/broadcast' || str_starts_with($text, '/broadcast ')) {
            $this->session->forget($customer);
            if (! $this->adminAccess->isAdmin($customer, $chatId)) {
                $this->bot->sendMessage($chatId, 'Akses admin diperlukan.');

                return;
            }

            $message = trim(substr($text, strlen('/broadcast')));
            if ($message === '') {
                $this->bot->sendMessage($chatId, "Isi broadcast-nya belum ada.\n\nGunakan format:\n/broadcast isi pesan");

                return;
            }

            $result = $this->broadcast->sendToActiveUsers($message);
            $this->bot->sendMessage($chatId, $result['skipped']
                ? 'Broadcast dilewati karena token Telegram belum dikonfigurasi.'
                : "✅ Broadcast selesai\n━━━━━━━━━━━━━━\nTerkirim: {$result['sent']}\nGagal: {$result['failed']}");

            return;
        }

        if (in_array($text, ['/akun', '👤 Akun Saya'], true)) {
            $this->session->forget($customer);
            $this->menu->sendAccountSummary($customer, $chatId);

            return;
        }

        if ($text === '/cari' || str_starts_with($text, '/cari ')) {
            $this->session->forget($customer);
            $keyword = trim(substr($text, strlen('/cari')));
            if ($keyword === '') {
                $this->bot->sendMessage($chatId, "Mau cari produk apa?\n\nKetik seperti ini:\n/cari netflix\n\nBisa pakai nama produk, kategori, atau kata kunci lain.");

                return;
            }

            $this->catalog->sendSearchResults($chatId, $keyword);

            return;
        }

        if ($text === '/cancel' || str_starts_with($text, '/cancel ')) {
            $this->session->forget($customer);
            $this->manualPayments->cancelPendingOrderFromTelegram($customer, $chatId, trim(substr($text, strlen('/cancel'))));

            return;
        }

        if ($text === '/batal') {
            $this->session->forget($customer);
            $customer->carts()->where('status', CartStatus::Active)->update(['status' => CartStatus::Abandoned]);
            $this->bot->sendMessage($chatId, "🛒 Keranjang aktif sudah dibatalkan.\n\nKalau mau pilih produk lagi, buka /produk ya.");

            return;
        }

        if ($text === '/payment' || str_starts_with($text, '/payment ')) {
            $this->session->forget($customer);
            $this->manualPayments->submitManualPaymentProof($customer, $chatId, $message, trim(substr($text, strlen('/payment'))));

            return;
        }

        if (in_array($text, ['/status', '/pesanan', '📦 Pesanan'], true)) {
            $this->session->forget($customer);
            $order = $customer->orders()->latest()->first();
            $this->bot->sendMessage($chatId, $order ? "📦 Status pesanan\n━━━━━━━━━━━━━━\n🧾 Invoice: {$order->invoice_number}\n📌 Order: {$order->order_status->value}\n💳 Payment: {$order->payment_status->value}\n\nMau lihat semua riwayat? Ketik /riwayat\nMau detail order? Ketik /order {$order->invoice_number}" : "Belum ada pesanan nih.\n\nBuka /produk dulu untuk mulai belanja 🛍️");

            return;
        }

        if ($text === '/riwayat') {
            $this->session->forget($customer);
            $this->orders->sendOrderHistory($customer, $chatId);

            return;
        }

        if ($text === '/order' || str_starts_with($text, '/order ')) {
            $this->session->forget($customer);
            $invoice = trim(substr($text, strlen('/order')));
            if ($invoice === '') {
                $this->bot->sendMessage($chatId, "Ketik nomor invoice setelah command ya.\n\nContoh:\n/order INV-12345678");

                return;
            }
            $this->orders->sendOrderDetail($customer, $chatId, $invoice);

            return;
        }

        if ($productId = $this->session->getSelectedProductId($customer)) {
            if (ctype_digit($text) && $this->cart->addProductToCart($customer, $chatId, (int) $productId, (int) $text)) {
                $this->session->forget($customer);

                return;
            }

            if (! ctype_digit($text)) {
                $this->bot->sendMessage($chatId, 'Angkanya belum pas nih. Pilih jumlah sesuai stok yang tersedia ya.');
            }

            return;
        }

        $this->session->forget($customer);
        $this->bot->sendMessage($chatId, "Aku belum paham perintah itu 😅\n\nCoba buka /produk untuk belanja, atau /support kalau butuh bantuan.");
    }
}
