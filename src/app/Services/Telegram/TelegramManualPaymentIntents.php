<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Support\Arr;

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
                ? 'Tidak ada pesanan manual pending. Checkout dulu, lalu kirim screenshot bukti pembayaran dengan caption /payment.'
                : 'Invoice tidak ditemukan atau bukan pesanan manual pending milik kamu. Cek /status atau /riwayat.');

            return;
        }

        $photoFileId = $this->largestPhotoFileId($message);
        if (! $photoFileId) {
            $this->bot->sendMessage($chatId, "Kirim screenshot bukti pembayaran sebagai foto dengan caption /payment.\nKalau punya beberapa order, gunakan caption: /payment {$order->invoice_number}");

            return;
        }

        $adminTelegramChatId = $this->adminTelegramChatId();
        if (! $adminTelegramChatId) {
            $this->bot->sendMessage($chatId, 'Bukti pembayaran diterima, tapi chat admin belum dikonfigurasi. Hubungi admin untuk konfirmasi manual.');

            return;
        }

        $order->loadMissing(['customer', 'orderItems', 'payments']);
        $payment = $order->payments->first();
        $caption = [
            '📸 Bukti pembayaran masuk',
            'Invoice: '.$order->invoice_number,
            'Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            'Metode: '.($payment?->method ?: 'manual'),
            'Total: Rp '.number_format($order->total_amount, 0, ',', '.'),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $caption[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $caption[] = '';
        $caption[] = 'Jika dana sudah masuk, klik Setujui & Kirim Produk.';

        $this->bot->sendPhotoReference($adminTelegramChatId, $photoFileId, implode("\n", $caption), [
            'inline_keyboard' => [[['text' => '✅ Setujui & Kirim Produk', 'callback_data' => 'admin:approve-payment:'.$order->id]]],
        ]);

        $this->bot->sendMessage($chatId, 'Bukti pembayaran sudah dikirim ke admin. Produk akan dikirim otomatis setelah admin menyetujui pembayaran.');
    }

    public function approveManualPaymentFromTelegram(Customer $admin, string $chatId, string $callbackQueryId, string $orderId): void
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
            $this->bot->answerCallbackQuery($callbackQueryId, 'Order sudah diproses atau tidak bisa di-approve.');

            return;
        }

        $completed = $this->adminOrders->completeManualOrder($order);
        $this->bot->answerCallbackQuery($callbackQueryId, 'Pembayaran disetujui. Produk dikirim ke pembeli.');
        $this->bot->sendMessage($chatId, '✅ Pembayaran disetujui untuk '.$completed->invoice_number.'. Bot akan mengirim produk ke pembeli.');
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
}
