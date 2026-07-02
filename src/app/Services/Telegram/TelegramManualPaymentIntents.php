<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TelegramManualPaymentIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private AdminOrderOperations $adminOrders,
        private TelegramAdminAccess $adminAccess,
    ) {}

    public function submitManualPaymentProof(Customer $customer, string $chatId, array $message, string $invoice): void
    {
        $order = $this->manualPaymentOrderForCustomer($customer, $invoice);
        if (! $order) {
            $this->bot->sendMessage($chatId, $invoice === ''
                ? "Belum ada pesanan manual yang menunggu pembayaran.\n\nCheckout dulu lewat /produk, lalu kirim screenshot bukti transfer sebagai FOTO dengan caption:\n\n/payment"
                : "Invoice tidak ditemukan atau bukan pesanan manual milik kamu.\n\nCoba cek lagi di /status atau /riwayat ya.");

            return;
        }

        $photoFileId = $this->largestPhotoFileId($message);
        if (! $photoFileId) {
            $this->bot->sendMessage($chatId, "Bukti pembayaran harus dikirim sebagai FOTO ya.\n\nCaranya:\n1. Upload screenshot transfer\n2. Isi caption dengan:\n\n/payment\n\nKalau punya beberapa order, pakai format:\n\n/payment {$order->invoice_number}");

            return;
        }

        $adminTelegramChatId = $this->adminTelegramChatId();
        if (! $adminTelegramChatId) {
            $this->bot->sendMessage($chatId, "📸 Bukti pembayaran sudah diterima.\n\nTapi chat admin belum dikonfigurasi, jadi verifikasi belum bisa diteruskan otomatis.\nSilakan hubungi admin untuk konfirmasi manual.");

            return;
        }

        $order->loadMissing(['customer', 'orderItems', 'payments']);
        $payment = $order->payments->first();
        $caption = [
            '📸 Bukti pembayaran masuk',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '👤 Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            '💳 Metode: '.($payment?->method ?: 'manual'),
            '💰 Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $caption[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $caption[] = '';
        $caption[] = 'Cek pembayaran QR manual dulu. Kalau pembayaran masuk dan nominal sama persis, setujui.';
        $caption[] = 'Kalau belum cocok, tolak agar stok balik lagi.';

        $this->bot->sendPhotoReference($adminTelegramChatId, $photoFileId, implode("\n", $caption), [
            'inline_keyboard' => [[
                ['text' => '✅ Setujui & Kirim Produk', 'callback_data' => 'admin:approve-payment:'.$order->id],
                ['text' => '❌ Tolak Pembayaran', 'callback_data' => 'admin:reject-payment:'.$order->id],
            ]],
        ]);

        $this->bot->sendMessage($chatId, "📸 Bukti pembayaran sudah masuk\n━━━━━━━━━━━━━━\nBuktinya sudah dikirim ke admin.\n\nSekarang tinggal tunggu dicek ya. Kalau pembayaran sesuai, produk akan dikirim otomatis.");
    }

    public function approveManualPaymentFromTelegram(Customer $admin, string $chatId, string $callbackQueryId, string $orderId): void
    {
        if (! $this->isTelegramAdmin($admin, $chatId)) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Akses admin diperlukan.');

            return;
        }

        $order = Str::isUuid($orderId) ? Order::find($orderId) : null;
        if (! $order) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Order tidak ditemukan.');

            return;
        }

        if (! $this->adminOrders->canCompleteManualOrder($order)) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Order sudah diproses atau tidak bisa diubah.');

            return;
        }

        $completed = $this->adminOrders->completeManualOrder($order);
        $this->bot->answerCallbackQuery($callbackQueryId, 'Pembayaran disetujui. Produk dikirim ke pembeli.');
        $this->bot->sendMessage($chatId, "✅ Pembayaran disetujui\n━━━━━━━━━━━━━━\nInvoice {$completed->invoice_number} sudah diproses. Bot akan mengirim produk ke pembeli.");
    }

    public function rejectManualPaymentFromTelegram(Customer $admin, string $chatId, string $callbackQueryId, string $orderId): void
    {
        if (! $this->isTelegramAdmin($admin, $chatId)) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Akses admin diperlukan.');

            return;
        }

        $order = Order::find($orderId);
        if (! $order) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Order tidak ditemukan.');

            return;
        }

        if (! $this->adminOrders->canCompleteManualOrder($order)) {
            $this->bot->answerCallbackQuery($callbackQueryId, 'Order sudah diproses atau tidak bisa diubah.');

            return;
        }

        $rejected = $this->adminOrders->rejectManualOrder($order);
        $this->bot->answerCallbackQuery($callbackQueryId, 'Pembayaran ditolak. Pembeli sudah diberi info.');
        $this->bot->sendMessage($chatId, "❌ Pembayaran ditolak\n━━━━━━━━━━━━━━\nInvoice {$rejected->invoice_number} dibatalkan dan stok sudah dilepas kembali.");
        $this->notifyBuyerPaymentRejected($rejected);
    }

    public function manualPaymentOrderForCustomer(Customer $customer, string $invoice): ?Order
    {
        return $customer->orders()
            ->where('order_status', OrderStatus::PendingPayment)
            ->where('payment_status', PaymentStatus::Pending)
            ->when($invoice !== '', fn ($query) => $query->where('invoice_number', $invoice))
            ->whereHas('payments', fn ($query) => $query->where('provider', 'manual')->where('status', PaymentStatus::Pending))
            ->latest()
            ->first();
    }

    public function largestPhotoFileId(array $message): ?string
    {
        $photos = Arr::get($message, 'photo', []);
        if (! is_array($photos) || $photos === []) {
            return null;
        }

        $photo = collect($photos)->sortByDesc(fn ($photo) => (int) ($photo['file_size'] ?? (($photo['width'] ?? 0) * ($photo['height'] ?? 0))))->first();

        return is_array($photo) ? ($photo['file_id'] ?? null) : null;
    }

    public function adminTelegramChatId(): ?string
    {
        return $this->adminAccess->adminChatId();
    }

    public function isTelegramAdmin(Customer $customer, string $chatId): bool
    {
        return $this->adminAccess->isAdmin($customer, $chatId);
    }

    private function notifyBuyerPaymentRejected(Order $order): void
    {
        $order->loadMissing(['customer', 'orderItems']);
        if (! $order->customer?->telegram_id) {
            return;
        }

        $lines = [
            '❌ Pembayaran belum bisa diterima',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '📌 Status: Ditolak admin',
            '',
            'Kemungkinan penyebab:',
            '• Nominal pembayaran belum sama persis',
            '• Nama QR tujuan bukan LHIE STORE',
            '• Bukti pembayaran kurang jelas',
            '• Pembayaran belum masuk saat dicek',
            '',
            'Pesanan ini sudah dibatalkan, jadi stok tidak tertahan.',
            '',
            'Kalau kamu merasa sudah transfer dengan benar, kirim ulang bukti yang lebih jelas atau hubungi @Alhie02 agar bisa dicek manual.',
        ];

        $this->bot->sendMessage($order->customer->telegram_id, implode("\n", $lines));
    }
}
