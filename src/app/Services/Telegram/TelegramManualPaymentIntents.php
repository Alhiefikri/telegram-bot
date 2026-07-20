<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramManualPaymentIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private AdminOrderOperations $adminOrders,
        private TelegramAdminAccess $adminAccess,
        private TelegramLocale $locale,
    ) {}

    public function submitManualPaymentProof(Customer $customer, string $chatId, array $message, string $invoice): void
    {
        $order = $this->manualPaymentOrderForCustomer($customer, $invoice);
        if (! $order) {
            $this->bot->sendMessage($chatId, $invoice === ''
                ? $this->locale->text(
                    $customer,
                    "Belum ada pesanan manual yang menunggu pembayaran.\n\nCheckout dulu lewat /produk, lalu kirim screenshot bukti transfer sebagai FOTO dengan caption:\n\n/payment",
                    "No manual order is awaiting payment.\n\nCheck out first using /products, then send a payment screenshot as a PHOTO with this caption:\n\n/payment",
                )
                : $this->locale->text(
                    $customer,
                    "Invoice tidak ditemukan atau bukan pesanan manual milik kamu.\n\nCoba cek lagi di /status atau /riwayat ya.",
                    "The invoice was not found or is not your manual order.\n\nCheck again using /status or /history.",
                ));

            return;
        }

        $photoFileId = $this->largestPhotoFileId($message) ?? $this->pendingProofPhotoFileId($customer, $order);
        if (! $photoFileId) {
            $this->bot->sendMessage($chatId, $this->locale->text(
                $customer,
                "Bukti pembayaran harus dikirim sebagai FOTO ya.\n\nPilih salah satu cara:\n1. Upload screenshot dan isi caption dengan /payment\n2. Jika screenshot sudah terkirim, balas foto itu dengan /payment\n\nKalau punya beberapa order, pakai:\n/payment {$order->invoice_number}",
                "Payment proof must be sent as a PHOTO.\n\nUse either method:\n1. Upload the screenshot with /payment as its caption\n2. If the screenshot was already sent, reply to that photo with /payment\n\nIf you have multiple orders, use:\n/payment {$order->invoice_number}",
            ));

            return;
        }

        $adminTelegramChatId = $this->adminTelegramChatId();
        if (! $adminTelegramChatId) {
            $this->bot->sendMessage($chatId, $this->locale->text(
                $customer,
                "📸 Bukti pembayaran sudah diterima.\n\nTapi chat admin belum dikonfigurasi, jadi verifikasi belum bisa diteruskan otomatis.\nSilakan hubungi admin untuk konfirmasi manual.",
                "📸 Payment proof received.\n\nHowever, the admin chat has not been configured, so verification cannot be forwarded automatically.\nPlease contact the admin for manual confirmation.",
            ));

            return;
        }

        $claim = DB::transaction(function () use ($order, $photoFileId, $adminTelegramChatId): ?array {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->order_status !== OrderStatus::PendingPayment || $lockedOrder->payment_status !== PaymentStatus::Pending) {
                return null;
            }

            $payment = $lockedOrder->payments()
                ->where('provider', 'manual')
                ->where('status', PaymentStatus::Pending)
                ->whereNull('payment_proof_submitted_at')
                ->lockForUpdate()
                ->first();
            if (! $payment) {
                return null;
            }

            $payment->update([
                'payment_proof_file_id' => $photoFileId,
                'payment_proof_submitted_at' => now(),
                'payment_proof_admin_chat_id' => $adminTelegramChatId,
                'expired_at' => null,
            ]);
            $lockedOrder->update(['expires_at' => null]);

            return [
                'order' => $lockedOrder->fresh(['customer', 'orderItems', 'payments']),
                'payment' => $payment->fresh(),
            ];
        });
        if ($claim === null) {
            $this->bot->sendMessage($chatId, $this->locale->text(
                $customer,
                '📸 Bukti pembayaran sudah diterima dan sedang menunggu verifikasi admin, atau order sudah tidak menunggu pembayaran.',
                '📸 Payment proof has already been received and is awaiting admin verification, or this order is no longer awaiting payment.',
            ));

            return;
        }
        $this->forgetPendingProofPhoto($customer);
        $order = $claim['order'];
        $payment = $claim['payment'];
        $forwarded = false;
        try {
            $forwarded = $this->forwardSubmittedManualPaymentProof($order, $payment);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->bot->sendMessage($chatId, $forwarded
            ? $this->locale->text(
                $customer,
                "📸 Bukti pembayaran sudah masuk\n━━━━━━━━━━━━━━\nBuktinya sudah dikirim ke admin.\n\nSekarang tinggal tunggu dicek ya. Kalau pembayaran sesuai, produk akan dikirim otomatis.",
                "📸 Payment proof received\n━━━━━━━━━━━━━━\nIt has been sent to the admin.\n\nPlease wait while it is checked. If the payment matches, the product will be sent automatically.",
            )
            : $this->locale->text(
                $customer,
                "📸 Bukti pembayaran sudah masuk\n━━━━━━━━━━━━━━\nBukti sedang diteruskan ke admin untuk diverifikasi. Kamu tidak perlu kirim ulang.\n\nKalau pembayaran sesuai, produk akan dikirim otomatis.",
                "📸 Payment proof received\n━━━━━━━━━━━━━━\nIt is being forwarded to the admin for verification. You do not need to send it again.\n\nIf the payment matches, the product will be sent automatically.",
            ));
    }

    public function forwardSubmittedManualPaymentProof(Order $order, $payment): bool
    {
        $order->loadMissing(['customer', 'orderItems']);
        $adminTelegramChatId = $this->adminTelegramChatId();
        if ($adminTelegramChatId === null) {
            throw new \RuntimeException('Manual payment proof cannot be forwarded without an admin chat and photo.');
        }

        $token = (string) Str::uuid();
        $claimed = DB::table('payments')
            ->where('id', $payment->id)
            ->where('provider', 'manual')
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('payment_proof_file_id')
            ->whereNotNull('payment_proof_submitted_at')
            ->whereNull('payment_proof_forwarded_at')
            ->where(fn ($query) => $query
                ->whereNull('payment_proof_forwarding_started_at')
                ->orWhere('payment_proof_forwarding_started_at', '<=', now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)))))
            ->update([
                'payment_proof_forwarding_started_at' => now(),
                'payment_proof_forwarding_token' => $token,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return false;
        }

        $photoFileId = (string) $payment->payment_proof_file_id;

        $isBinance = $payment->method === 'BINANCE_PAY_ID';
        $caption = [
            '📸 Bukti pembayaran masuk',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '👤 Customer: @'.($order->customer?->username ?: '-').' ('.$order->customer?->telegram_id.')',
            '💳 Metode: '.($payment?->payment_method_label ?: 'manual'),
            '💰 Total: '.($payment?->currency === 'USDT' ? number_format($payment->amount / 1_000_000, 6, '.', '').' USDT' : 'Rp '.number_format($order->total_amount, 0, ',', '.')),
            '',
            'Item:',
        ];
        foreach ($order->orderItems as $item) {
            $caption[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $caption[] = '';
        $caption[] = $isBinance
            ? 'Cek pembayaran Binance Pay dan pastikan nominalnya sama persis, lalu setujui.'
            : 'Cek pembayaran QR manual dulu. Kalau pembayaran masuk dan nominal sama persis, setujui.';
        $caption[] = 'Kalau belum cocok, tolak agar stok balik lagi.';

        try {
            $this->bot->sendPhotoReference($adminTelegramChatId, $photoFileId, implode("\n", $caption), [
                'inline_keyboard' => [[
                    ['text' => '✅ Setujui & Kirim Produk', 'callback_data' => 'admin:approve-payment:'.$order->id],
                    ['text' => '❌ Tolak Pembayaran', 'callback_data' => 'admin:reject-payment:'.$order->id],
                ]],
            ]);
        } catch (\Throwable $exception) {
            DB::table('payments')
                ->where('id', $payment->id)
                ->where('payment_proof_forwarding_token', $token)
                ->update([
                    'payment_proof_forwarding_started_at' => null,
                    'payment_proof_forwarding_token' => null,
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        DB::table('payments')
            ->where('id', $payment->id)
            ->where('payment_proof_forwarding_token', $token)
            ->update([
                'payment_proof_admin_chat_id' => $adminTelegramChatId,
                'payment_proof_forwarding_started_at' => null,
                'payment_proof_forwarding_token' => null,
                'payment_proof_forwarded_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }

    public function approveManualPaymentFromTelegram(Customer $admin, string $chatId, string $callbackQueryId, string $orderId): void
    {
        if (! $this->isTelegramAdmin($admin, $chatId)) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->locale->text($admin, 'Akses admin diperlukan.', 'Admin access is required.'));

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

        $this->adminOrders->completeManualOrder($order);
        $this->bot->answerCallbackQuery($callbackQueryId, 'Pembayaran disetujui. Produk dikirim ke pembeli.');
    }

    public function rejectManualPaymentFromTelegram(Customer $admin, string $chatId, string $callbackQueryId, string $orderId): void
    {
        if (! $this->isTelegramAdmin($admin, $chatId)) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->locale->text($admin, 'Akses admin diperlukan.', 'Admin access is required.'));

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

    public function cancelPendingOrderFromTelegram(Customer $admin, string $chatId, string $invoice): void
    {
        if (! $this->isTelegramAdmin($admin, $chatId)) {
            $this->bot->sendMessage($chatId, $this->locale->text($admin, 'Akses admin diperlukan.', 'Admin access is required.'));

            return;
        }

        $invoice = trim($invoice);
        if ($invoice === '') {
            $this->bot->sendMessage($chatId, "Invoice belum diisi.\n\nGunakan format:\n/cancel INV-xxxx");

            return;
        }

        $order = Order::query()
            ->where('invoice_number', $invoice)
            ->where('order_status', OrderStatus::PendingPayment)
            ->where('payment_status', PaymentStatus::Pending)
            ->first();

        if (! $order) {
            $this->bot->sendMessage($chatId, 'Order pending tidak ditemukan.');

            return;
        }

        if (! $this->adminOrders->canCancelPendingOrder($order)) {
            $this->bot->sendMessage($chatId, 'Order sudah diproses atau tidak bisa dibatalkan.');

            return;
        }

        $cancelled = $this->adminOrders->cancelPendingOrder($order);
        $this->bot->sendMessage($chatId, "✅ Order dibatalkan\n━━━━━━━━━━━━━━\nInvoice {$cancelled->invoice_number} dibatalkan dan stok sudah dilepas kembali.");
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
        $photos = Arr::get($message, 'photo', Arr::get($message, 'reply_to_message.photo', []));
        if (! is_array($photos) || $photos === []) {
            return null;
        }

        $photo = collect($photos)->sortByDesc(fn ($photo) => (int) ($photo['file_size'] ?? (($photo['width'] ?? 0) * ($photo['height'] ?? 0))))->first();

        return is_array($photo) ? ($photo['file_id'] ?? null) : null;
    }

    public function rememberPendingProofPhoto(Customer $customer, array $message): bool
    {
        $photoFileId = $this->largestPhotoFileId($message);
        $order = $this->manualPaymentOrderForCustomer($customer, '');
        if ($photoFileId === null || $order === null) {
            return false;
        }

        Cache::put($this->pendingProofPhotoCacheKey($customer), [
            'order_id' => $order->id,
            'file_id' => $photoFileId,
        ], now()->addMinutes(30));

        return true;
    }

    private function pendingProofPhotoFileId(Customer $customer, Order $order): ?string
    {
        $proof = Cache::get($this->pendingProofPhotoCacheKey($customer));
        if (! is_array($proof) || ($proof['order_id'] ?? null) !== $order->id) {
            return null;
        }

        $photoFileId = $proof['file_id'] ?? null;

        return is_string($photoFileId) && $photoFileId !== '' ? $photoFileId : null;
    }

    private function forgetPendingProofPhoto(Customer $customer): void
    {
        Cache::forget($this->pendingProofPhotoCacheKey($customer));
    }

    private function pendingProofPhotoCacheKey(Customer $customer): string
    {
        return 'telegram:manual-payment-proof:'.$customer->telegram_id;
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
        $customer = $order->customer;
        if (! $customer?->telegram_id) {
            return;
        }
        $support = $this->buyerSupport();

        $lines = $this->locale->code($customer) === 'en' ? [
            '❌ Payment could not be accepted',
            '━━━━━━━━━━━━━━',
            '🧾 Invoice: '.$order->invoice_number,
            '📌 Status: Rejected by admin',
            '',
            'Possible reasons:',
            '• The payment amount did not match exactly',
            '• The destination QR name was not LHIE STORE',
            '• The payment proof was unclear',
            '• The payment had not arrived when it was checked',
            '',
            'This order has been cancelled, so the stock is no longer reserved.',
            '',
            $this->paymentRejectedContactLine('en', $support['username']),
        ] : [
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
            $this->paymentRejectedContactLine('id', $support['username']),
        ];

        $this->bot->sendMessage($customer->telegram_id, implode("\n", $lines), $this->buyerSupportReplyMarkup($customer, $support['url']));
    }

    private function paymentRejectedContactLine(string $locale, string $username): string
    {
        if ($username !== '') {
            return $locale === 'en'
                ? 'If you believe the transfer was correct, resend a clearer payment proof or contact @'.$username.' for a manual check.'
                : 'Kalau kamu merasa sudah transfer dengan benar, kirim ulang bukti yang lebih jelas atau hubungi @'.$username.' agar bisa dicek manual.';
        }

        return $locale === 'en'
            ? 'If you believe the transfer was correct, resend a clearer payment proof or contact the admin for a manual check.'
            : 'Kalau kamu merasa sudah transfer dengan benar, kirim ulang bukti yang lebih jelas atau hubungi admin agar bisa dicek manual.';
    }

    /**
     * @return array{username: string, url: string}
     */
    private function buyerSupport(): array
    {
        $settings = ManualPaymentSetting::current();
        $username = ltrim(trim((string) ($settings?->admin_username ?: config('services.telegram.admin_username'))), '@');
        $url = trim((string) ($settings?->admin_contact_url ?: config('services.telegram.admin_contact_url')));
        if ($url === '' && $username !== '') {
            $url = 'https://t.me/'.$username;
        }

        return ['username' => $username, 'url' => $url];
    }

    private function buyerSupportReplyMarkup(Customer $customer, string $url): array
    {
        if ($url === '') {
            return [];
        }

        return ['inline_keyboard' => [[[
            'text' => $this->locale->text($customer, 'Hubungi Admin', 'Chat with Admin'),
            'url' => $url,
        ]]]];
    }
}
