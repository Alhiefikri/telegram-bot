<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Admin\AdminDashboardSalesData;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Bsc\BscExplorerUrl;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminOrderDetailController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminDashboardSalesData $salesData, AdminOrderOperations $operations, BscExplorerUrl $bscExplorer): View
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('view', $order);

        $order->load(['customer', 'orderItems', 'payments.paymentEvents', 'payments.manualFulfillmentReview', 'payments.bscDetail.transferEvidence', 'salesProofPost']);

        $binancePayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID');
        $payload = is_array($binancePayment?->provider_payload) ? $binancePayment->provider_payload : [];
        $hasMismatchEvidence = $operations->hasPendingBinanceMismatch($order);
        $bscPayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL && $payment->method === 'USDT_BEP20');

        return view('admin.orders.show', [
            'order' => $order,
            'customerLabel' => $order->customer?->first_name ?: ($order->customer?->username ? '@'.$order->customer->username : 'Pelanggan'),
            'createdAtLabel' => $order->created_at?->format('d M Y H:i'),
            'total' => Money::idr($order->total_amount),
            'statusLabel' => $order->order_status->value.' / '.$order->payment_status->value,
            'itemLabels' => $order->orderItems->map(fn ($item): string => $item->product_name.' × '.$item->quantity)->all(),
            'paymentLabels' => $order->payments->map(fn ($payment): string => $payment->payment_method_label.' · '.$payment->status->value.' · '.($payment->paid_at?->format('d M Y H:i') ?? 'belum paid'))->all(),
            'paymentAttention' => $salesData->paymentAttention($order),
            'canCancelRelease' => $order->order_status === OrderStatus::PendingPayment && $order->payment_status === PaymentStatus::Pending,
            'canCompleteManual' => $hasMismatchEvidence && Gate::forUser($user)->allows('completeManual', $order) && $operations->canCompleteManualOrder($order),
            'canRejectManual' => $hasMismatchEvidence && Gate::forUser($user)->allows('cancelPending', $order) && $operations->canCancelPendingOrder($order),
            'binanceReview' => $hasMismatchEvidence ? [
                'expectedAmount' => rtrim(rtrim(number_format((int) $binancePayment->amount / 1_000_000, 6, '.', ''), '0'), '.'),
                'receivedAmount' => (string) $payload['amount'],
                'currency' => (string) $payload['currency'],
                'orderId' => $this->maskIdentifier((string) ($payload['orderId'] ?? '-')),
                'transactionId' => $this->maskIdentifier((string) $payload['transactionId']),
                'receivedAt' => is_numeric($payload['transactionTime'] ?? null)
                    ? now()->setTimestamp((int) floor((int) $payload['transactionTime'] / 1_000))->timezone((string) config('app.timezone'))->format('d M Y H:i')
                    : '-',
            ] : null,
            'bscReview' => $this->bscReview($bscPayment, $bscExplorer),
            'salesProof' => $salesData->salesProofState($order, (bool) $user->role?->canManagePaymentSettings()),
            'auditEntries' => $this->auditEntries($order),
            'paymentDetails' => $order->payments->map(fn (Payment $payment): array => $this->paymentDetail($payment))->all(),
        ]);
    }

    private function paymentDetail(Payment $payment): array
    {
        $events = $payment->paymentEvents
            ->sortByDesc(fn (PaymentEvent $event) => $event->received_at?->getTimestamp() ?? 0)
            ->map(fn (PaymentEvent $event): array => [
                'type' => $event->event_type?->value ?? (string) $event->event_type,
                'provider_status' => $this->bounded((string) $event->provider_status, 40),
                'condition' => $this->eventCondition($event),
                'received_at' => $event->received_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
                'processed_at' => $event->processed_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
                'received_count' => $event->received_count,
            ])
            ->values()
            ->all();

        return [
            'provider' => strtolower((string) $payment->provider),
            'merchant_ref_short' => $this->bounded((string) $payment->merchant_ref, 8),
            'merchant_ref_hash' => hash('sha256', (string) $payment->merchant_ref),
            'base_amount' => Money::idr((int) $payment->amount),
            'final_amount' => $payment->provider_total_payment !== null ? Money::idr((int) $payment->provider_total_payment) : '-',
            'provider_create_state' => $payment->provider_create_state ?: '-',
            'provider_status' => $events[0]['provider_status'] ?? '-',
            'expires_at' => $payment->provider_expires_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
            'callback_count' => collect($events)->where('type', 'callback')->sum('received_count'),
            'latest_reconciliation_at' => $payment->paymentEvents
                ->where('event_type', PaymentEventType::Reconciliation)
                ->filter(fn (PaymentEvent $event): bool => $event->processed_at !== null)
                ->sortByDesc(fn (PaymentEvent $event): int => $event->processed_at?->getTimestamp() ?? 0)
                ->first()?->processed_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
            'events' => $events,
        ];
    }

    /** @return array<string, string>|null */
    private function bscReview(?Payment $payment, BscExplorerUrl $bscExplorer): ?array
    {
        $detail = $payment?->bscDetail;
        if ($detail === null) {
            return null;
        }

        $evidence = $detail->transferEvidence->sortByDesc('last_verified_at')->first();
        $hash = trim((string) ($evidence?->tx_hash ?? ''));
        $explorer = $bscExplorer->forTransaction($evidence?->tx_hash);
        $review = $payment->manualFulfillmentReview;

        return [
            'status' => (string) $detail->verification_status,
            'contract' => (string) ($evidence?->token_contract ?? $detail->token_contract),
            'receiver' => (string) ($evidence?->receiver_address ?? $detail->receiver_address),
            'sender' => (string) ($evidence?->from_address ?? $detail->current_from_address ?? '-'),
            'expected_amount' => rtrim(rtrim(number_format((int) $detail->payable_amount_micro / 1_000_000, 6, '.', ''), '0'), '.').' USDT',
            'amount' => (string) ($evidence?->amount_units ?? $detail->expected_token_units),
            'tx_hash' => $hash !== '' ? $hash : '-',
            'from_address' => (string) ($evidence?->from_address ?? $detail->current_from_address ?? '-'),
            'block' => (string) ($evidence?->block_number ?? $detail->current_block_number ?? '-'),
            'block_hash' => (string) ($evidence?->block_hash ?? '-'),
            'block_timestamp' => $evidence?->block_timestamp?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s') ?? '-',
            'receipt_status' => (string) ($evidence?->receipt_status ?? '-'),
            'confirmations' => (string) ($detail->current_confirmations ?? $evidence?->confirmations ?? 0),
            'explorer' => $explorer ?? '',
            'manual_review_url' => $review === null ? '' : route('admin.manual-fulfillment-reviews.show', $review),
        ];
    }

    private function bounded(string $value, int $length): string
    {
        $value = trim($value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }

    private function eventCondition(PaymentEvent $event): string
    {
        $payload = is_array($event->raw_payload) ? $event->raw_payload : [];

        return ($payload['condition'] ?? null) === 'unknown_callback_status'
            ? 'unknown_callback_status'
            : '-';
    }

    private function auditEntries(Order $order): array
    {
        return AuditLog::query()
            ->with('user:id,name,email')
            ->where('subject_type', Order::class)
            ->where('subject_id', (string) $order->getKey())
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'label' => $this->auditActionLabel((string) $log->action),
                'actor' => $log->user?->name ?: $log->user?->email ?: 'Sistem',
                'created_at' => $log->created_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
                'ip_address' => $log->ip_address,
            ])
            ->all();
    }

    private function maskIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return '-';
        }

        if (str_contains($value, '•')) {
            return $value;
        }

        return strlen($value) <= 8 ? str_repeat('•', strlen($value)) : substr($value, 0, 4).'••••'.substr($value, -4);
    }

    private function auditActionLabel(string $action): string
    {
        return match ($action) {
            'order.pending_cancelled_released' => 'Dibatalkan & stok dilepas',
            'order.manual_completed' => 'Diselesaikan manual',
            'order.manual_rejected' => 'Ditolak manual',
            'order.receipt_resent' => 'Receipt dikirim ulang',
            default => str_replace(['.', '_'], ' ', $action),
        };
    }
}
