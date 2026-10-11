<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\OrderCredentialMailService;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use InvalidArgumentException;

class AdminOrderOperations
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderLifecycle $orders,
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly ExternalMoneyReceiptWriter $receipts,
        private readonly OrderCredentialMailService $credentialMails,
    ) {}

    public function canCompleteManualOrder(Order $order): bool
    {
        $order->loadMissing('payments');
        $payment = $order->payments->first();
        $projection = $this->orders->project($order, $payment);

        return $projection->isPendingPayment
            && $payment?->provider === 'manual'
            && $payment->status === PaymentStatus::Pending;
    }

    public function canCancelPendingOrder(Order $order): bool
    {
        $order->loadMissing('payments');
        $payment = $order->payments->first();
        $projection = $this->orders->project($order, $payment);

        return $projection->isPendingPayment
            && $order->payments->contains(fn ($payment): bool => $payment->status === PaymentStatus::Pending);
    }

    public function hasPendingBinanceMismatch(Order $order): bool
    {
        $order->loadMissing('payments');
        $payment = $order->payments->first(fn ($payment): bool => $payment->provider === 'manual' && $payment->method === 'BINANCE_PAY_ID');
        $payload = is_array($payment?->provider_payload) ? $payment->provider_payload : [];

        return $payment?->status === PaymentStatus::Pending
            && filled($payload['transactionId'] ?? null)
            && filled($payload['amount'] ?? null)
            && filled($payload['currency'] ?? null);
    }

    public function cancelPendingOrder(Order $order, ?User $user = null, ?string $ip = null): Order
    {
        try {
            $cancelledOrder = $this->orders->applyPendingCancellation($order)->order;
        } catch (InvalidOrderTransition $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->audit->log('order.pending_cancelled_released', $cancelledOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Cancelled->value, 'payment_status' => PaymentStatus::Failed->value], $user, $ip);

        return $cancelledOrder->fresh(['payments', 'customer', 'orderItems']);
    }

    public function completeManualOrder(Order $order, ?User $user = null, ?string $ip = null): Order
    {
        $order->loadMissing('payments');
        $payment = $order->payments->first();

        if ($payment !== null
            && $payment->provider === 'manual'
            && $payment->status === PaymentStatus::Pending
            && trim((string) $payment->merchant_ref) !== ''
            && $this->receipts->claimOwnerAttestation($payment)->isHeld()) {
            throw new InvalidArgumentException('This manual payment receipt is held for review and cannot be completed.');
        }

        try {
            $completedOrder = $this->orders->applyManualCompletion($order)->order;
        } catch (InvalidOrderTransition $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->audit->log('order.manual_completed', $completedOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Completed->value, 'payment_status' => PaymentStatus::Paid->value], $user, $ip);

        return $completedOrder->fresh(['payments']);
    }

    public function rejectManualOrder(Order $order, ?User $user = null, ?string $ip = null): Order
    {
        try {
            $rejectedOrder = $this->orders->applyManualRejection($order)->order;
        } catch (InvalidOrderTransition $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->audit->log('order.manual_rejected', $rejectedOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Cancelled->value, 'payment_status' => PaymentStatus::Failed->value], $user, $ip);

        return $rejectedOrder->fresh(['payments', 'customer', 'orderItems']);
    }

    public function canResendReceipt(Order $order): bool
    {
        $order->loadMissing('customer');

        return $order->customer?->telegram_id !== null
            && $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
    }

    public function resendReceipt(Order $order, ?User $user = null, ?string $ip = null): void
    {
        if (! $this->canResendReceipt($order)) {
            throw new InvalidArgumentException('Receipt can only be resent for paid orders with a Telegram customer.');
        }

        $effect = $this->outboundEffects->recordReceiptResend($order);
        $this->dispatcher->dispatchExplicitResend($effect);

        $this->audit->log('order.receipt_resent', $order, null, ['queue' => 'notifications'], $user, $ip);
    }

    /**
     * The credential email always targets the address recorded on the order,
     * so an operator can re-arm that same delivery but never redirect it.
     */
    public function canResendCredentialMail(Order $order): bool
    {
        return filled($order->notification_email)
            && $this->credentialMails->isDeliverable($order);
    }

    public function resendCredentialMail(Order $order, ?User $user = null, ?string $ip = null): void
    {
        if (! $this->canResendCredentialMail($order)) {
            throw new InvalidArgumentException('Email data akun hanya bisa dikirim ulang untuk pesanan lunas yang sudah punya alamat email pengiriman.');
        }

        if ($this->credentialMails->rearm($order) === null) {
            throw new InvalidArgumentException('Pengiriman email data akun masih berjalan. Tunggu beberapa menit lalu coba lagi.');
        }

        $this->audit->log('order.credential_mail_resent', $order, null, ['queue' => 'notifications'], $user, $ip);
    }
}
