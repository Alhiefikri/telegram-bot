<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Binance\BinancePaymentVerification;
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
        private TelegramSupportContact $supportContact,
        private BinancePaymentVerification $verification,
    ) {}

    public function submitManualPaymentProof(Customer $customer, string $chatId, array $message, string $invoice): void
    {
        $order = $this->manualPaymentOrderForCustomer($customer, $invoice)
            ?? $this->expiredBinancePaymentOrderForCustomer($customer, $invoice);
        if (! $order) {
            $this->bot->sendMessage(
                $chatId,
                $this->locale->translate($customer, $invoice === '' ? 'payment.proof.no_pending' : 'payment.proof.invoice_not_found'),
            );

            return;
        }

        $photoFileId = $this->largestPhotoFileId($message) ?? $this->pendingProofPhotoFileId($customer, $order);
        if (! $photoFileId) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.photo_required', [
                'invoice' => $order->invoice_number,
            ]));

            return;
        }

        $adminTelegramChatId = $this->adminTelegramChatId();
        if (! $adminTelegramChatId) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.admin_unavailable'));

            return;
        }

        $claim = DB::transaction(function () use ($order, $photoFileId, $adminTelegramChatId): ?array {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $isPendingOrder = $lockedOrder->order_status === OrderStatus::PendingPayment
                && $lockedOrder->payment_status === PaymentStatus::Pending;
            $isLateBinanceOrder = $lockedOrder->order_status === OrderStatus::Expired
                && $lockedOrder->payment_status === PaymentStatus::Expired;
            if (! $isPendingOrder && ! $isLateBinanceOrder) {
                return null;
            }

            $payment = $lockedOrder->payments()
                ->where('provider', 'manual')
                ->when($isLateBinanceOrder, fn ($query) => $query
                    ->where('method', 'BINANCE_PAY_ID')
                    ->where('status', PaymentStatus::Expired), fn ($query) => $query->where('status', PaymentStatus::Pending))
                ->whereNull('payment_proof_submitted_at')
                ->lockForUpdate()
                ->first();
            if (! $payment) {
                return null;
            }

            $deadline = $payment->expired_at ?? $lockedOrder->expires_at;
            if ($isPendingOrder && $deadline !== null && ! $deadline->isFuture()) {
                return ['status' => 'expired'];
            }

            $verificationStatus = $this->verification->status($payment);
            $automaticBinance = $isPendingOrder
                && $payment->method === 'BINANCE_PAY_ID'
                && $verificationStatus->automatic
                && in_array($verificationStatus->mode, ['amount_unique', 'order_id'], true);
            if ($automaticBinance
                && ($verificationStatus->mode === 'amount_unique' || ! $verificationStatus->closed())) {
                return ['status' => $verificationStatus->mode === 'order_id'
                    ? 'order_id_required'
                    : 'automatic_verification_required'];
            }

            // Proof is review evidence, not payment completion. Keep the
            // original deadlines so unattended review cannot hold stock forever.
            $payment->update([
                'payment_proof_file_id' => $photoFileId,
                'payment_proof_submitted_at' => now(),
                'payment_proof_admin_chat_id' => $adminTelegramChatId,
            ]);

            return [
                'order' => $lockedOrder->fresh(['customer', 'orderItems', 'payments']),
                'payment' => $payment->fresh(),
                'late' => $isLateBinanceOrder,
                'status' => 'accepted',
            ];
        });
        if ($claim === null) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.duplicate'));

            return;
        }
        $this->forgetPendingProofPhoto($customer);

        if ($claim['status'] === 'order_id_required') {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.binance_order_id_required'));

            return;
        }

        if ($claim['status'] === 'automatic_verification_required') {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.binance_amount_unique'));

            return;
        }

        if ($claim['status'] === 'expired') {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'payment.proof.deadline_passed', [
                'invoice' => $order->invoice_number,
            ]));

            return;
        }

        $order = $claim['order'];
        $payment = $claim['payment'];
        $forwarded = false;
        try {
            $forwarded = $this->forwardSubmittedManualPaymentProof($order, $payment);
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($claim['late']) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, $forwarded
                ? 'payment.proof.late_forwarded'
                : 'payment.proof.late_forwarding'));

            return;
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, $forwarded
            ? 'payment.proof.forwarded'
            : 'payment.proof.forwarding'));
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
            ->where(function ($query): void {
                $query
                    ->where('status', PaymentStatus::Pending->value)
                    ->orWhere(fn ($late) => $late
                        ->where('method', 'BINANCE_PAY_ID')
                        ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value]));
            })
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

        $lateEvidence = $payment->method === 'BINANCE_PAY_ID'
            && in_array($payment->status, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);

        if ($lateEvidence) {
            $caption[] = '⚠️ Late Binance evidence: order sudah expired dan stok sudah dilepas.';
            $caption[] = 'Jangan approve lewat tombol otomatis. Review/refund/fulfill manual sesuai kondisi stok.';
        } else {
            $caption[] = '';
            $caption[] = $isBinance
                ? 'Cek pembayaran Binance Pay dan pastikan nominalnya sama persis, lalu setujui.'
                : 'Cek pembayaran QR manual dulu. Kalau pembayaran masuk dan nominal sama persis, setujui.';
            $caption[] = 'Kalau belum cocok, tolak agar stok balik lagi.';
        }

        $replyMarkup = $lateEvidence ? [] : [
            'inline_keyboard' => [[
                ['text' => '✅ Setujui & Kirim Produk', 'callback_data' => 'admin:approve-payment:'.$order->id],
                ['text' => '❌ Tolak Pembayaran', 'callback_data' => 'admin:reject-payment:'.$order->id],
            ]],
        ];

        try {
            $this->bot->sendPhotoReference($adminTelegramChatId, $photoFileId, implode("\n", $caption), $replyMarkup);
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

    private function expiredBinancePaymentOrderForCustomer(Customer $customer, string $invoice): ?Order
    {
        if ($invoice === '') {
            return null;
        }

        return $customer->orders()
            ->where('invoice_number', $invoice)
            ->where('order_status', OrderStatus::Expired)
            ->where('payment_status', PaymentStatus::Expired)
            ->whereHas('payments', fn ($query) => $query
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Expired)
                ->whereNull('payment_proof_submitted_at'))
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

        $message = $this->locale->translate($customer, 'payment.manual.rejected', [
            'invoice' => $order->invoice_number,
            'contact' => $this->paymentRejectedContactLine($customer, $support['username']),
        ]);

        $this->bot->sendMessage($customer->telegram_id, $message, $this->buyerSupportReplyMarkup($customer, $support['url']));
    }

    private function paymentRejectedContactLine(Customer $customer, string $username): string
    {
        return $this->locale->translate($customer, 'payment.manual.rejected_contact', [
            'contact' => $username !== '' ? '@'.$username : 'admin',
        ]);
    }

    /**
     * @return array{username: string, url: string}
     */
    private function buyerSupport(): array
    {
        return $this->supportContact->resolve();
    }

    private function buyerSupportReplyMarkup(Customer $customer, string $url): array
    {
        if ($url === '') {
            return [];
        }

        return ['inline_keyboard' => [[[
            'text' => $this->locale->translate($customer, 'payment.action.contact_admin'),
            'url' => $url,
        ]]]];
    }
}
