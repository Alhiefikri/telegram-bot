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
use App\Models\SupplierFulfillment;
use App\Models\User;
use App\Services\Admin\AdminDashboardSalesData;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\Bsc\BscExplorerUrl;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminOrderDetailController extends Controller
{
    public function __invoke(Request $request, Order $order, AdminDashboardSalesData $salesData, AdminOrderOperations $operations, BscExplorerUrl $bscExplorer, AdminPaymentOperations $paymentOperations): View
    {
        $user = $request->attributes->get('adminUser');
        $canViewSupplierOperations = $user?->role?->canManageStoreOperations() === true;

        Gate::authorize('view', $order);

        $order->load([
            'customer',
            'orderItems',
            'orderItems.supplierFulfillment' => fn ($query) => $query->withExists('manualHandoffs'),
            'payments' => function ($payments): void {
                $payments
                    ->withCount('paymentEvents')
                    ->withSum([
                        'paymentEvents as callback_received_count' => fn ($events) => $events
                            ->where('event_type', PaymentEventType::Callback->value),
                    ], 'received_count')
                    ->withMax([
                        'paymentEvents as latest_reconciliation_processed_at' => fn ($events) => $events
                            ->where('event_type', PaymentEventType::Reconciliation->value)
                            ->whereNotNull('processed_at'),
                    ], 'processed_at');
            },
            'payments.paymentEvents' => fn ($events) => $events
                ->latest('received_at')
                ->latest('id')
                ->limit(10),
            'payments.manualFulfillmentReview',
            'payments.bscDetail.transferEvidence',
            'telegramOutboundEffects',
            'salesProofPost',
        ]);
        if ($canViewSupplierOperations) {
            $order->load([
                'orderItems.supplierFulfillment' => fn ($query) => $query->withExists('manualHandoffs'),
                'orderItems.supplierFulfillment.currentPurchase.supplier',
                'orderItems.supplierFulfillment.manualHandoffs.actor',
                'orderItems.supplierFulfillment.purchases.supplier',
            ]);
        }

        $binancePayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID');
        $payload = is_array($binancePayment?->provider_payload) ? $binancePayment->provider_payload : [];
        $hasMismatchEvidence = $operations->hasPendingBinanceMismatch($order);
        $bscPayment = $order->payments->first(fn (Payment $payment): bool => $payment->provider === PaymentProvider::MANUAL && $payment->method === 'USDT_BEP20');
        $supplierFulfillments = $order->orderItems->map(function ($item) use ($order, $canViewSupplierOperations): ?array {
            $fulfillment = $item->supplierFulfillment;
            if (! $fulfillment instanceof SupplierFulfillment) {
                return null;
            }
            $deliveryStatus = match ($fulfillment->state) {
                'delivered' => $fulfillment->manual_handoffs_exists
                    ? 'Selesai · diserahkan di luar bot'
                    : ($order->telegram_receipt_sent_at !== null ? 'Supplier fulfillment selesai · receipt Telegram tercatat terkirim' : 'Supplier fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review'),
                'review' => 'Perlu review operator',
                'cancelled' => 'Dibatalkan',
                default => 'Sedang diproses',
            };

            return [
                'fulfillment_id' => $fulfillment->id,
                'product_name' => $item->product_name,
                'quantity' => $fulfillment->quantity,
                'delivery_status' => $deliveryStatus,
                'reserved_quantity' => $fulfillment->reserved_quantity,
                'reserved_cost' => SupplierMoney::decimal($fulfillment->reserved_cost_micros),
                'last_wallet_observed_at' => $canViewSupplierOperations ? $fulfillment->currentPurchase?->supplier?->balance_observed_at
                    ?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') : null,
                'purchases' => $canViewSupplierOperations ? $fulfillment->purchases->map(fn ($purchase): array => [
                    'id' => $purchase->id,
                    'state' => $purchase->state,
                    'supplier_name' => $purchase->supplier?->name ?? 'Supplier tidak tersedia',
                    'supplier_order_id' => $purchase->supplier_order_id,
                    'quantity' => $purchase->quantity,
                    'expected_cost' => SupplierMoney::decimal($purchase->expected_cost_micros),
                    'actual_cost' => $purchase->actual_cost_micros === null ? null : SupplierMoney::decimal($purchase->actual_cost_micros),
                    'currency' => $purchase->supplier?->currency ?? 'USD',
                    'committed_at' => $purchase->committed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T'),
                ])->values()->all() : [],
                'handoffs' => $canViewSupplierOperations ? $fulfillment->manualHandoffs->map(fn ($handoff): array => [
                    'quantity' => $handoff->quantity,
                    'operator' => $handoff->actor?->name ?? 'operator dihapus',
                    'at' => $handoff->handed_off_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T'),
                    'note' => $handoff->note,
                ])->values()->all() : [],
            ];
        })->filter()->values()->all();
        $hasPendingSupplierPurchase = collect($supplierFulfillments)->flatMap(fn (array $fulfillment): array => $fulfillment['purchases'])
            ->contains(fn (array $purchase): bool => in_array($purchase['state'], ['sending', 'awaiting_delivery', 'ambiguous', 'review'], true));

        return view('admin.orders.show', [
            'order' => $order,
            'customerLabel' => $order->customer?->first_name ?: ($order->customer?->username ? '@'.$order->customer->username : 'Pelanggan'),
            'createdAtLabel' => $order->created_at?->format('d M Y H:i'),
            'total' => Money::idr($order->total_amount),
            'orderStatusLabel' => $order->order_status->value,
            'paymentStatusLabel' => $order->payment_status->value,
            'itemLabels' => $order->orderItems->map(fn ($item): string => $item->product_name.' × '.$item->quantity)->all(),
            'supplierFulfillments' => $supplierFulfillments,
            'canViewSupplierOperations' => $canViewSupplierOperations,
            'hasPendingSupplierPurchase' => $hasPendingSupplierPurchase,
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
            'paymentDetails' => $order->payments->map(fn (Payment $payment): array => $this->paymentDetail($payment, $order, $paymentOperations, $user))->all(),
        ]);
    }

    private function paymentDetail(Payment $payment, Order $order, AdminPaymentOperations $paymentOperations, ?User $user): array
    {
        $events = $payment->paymentEvents
            ->sortByDesc(fn (PaymentEvent $event) => $event->received_at?->getTimestamp() ?? 0);
        $eventTotal = (int) ($payment->payment_events_count ?? $events->count());
        $eventSummary = $events
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

        $checkoutEffect = $payment->provider === PaymentProvider::KLIKQRIS
            ? $order->telegramOutboundEffects->first(fn ($effect): bool => $effect->effect_type === TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT)
            : null;
        $deliveryMetadata = is_array($checkoutEffect?->outcome_metadata) ? $checkoutEffect->outcome_metadata : [];
        $latestReconciliation = $payment->paymentEvents
            ->where('event_type', PaymentEventType::Reconciliation)
            ->filter(fn (PaymentEvent $event): bool => $event->processed_at !== null)
            ->sortByDesc(fn (PaymentEvent $event): int => $event->processed_at?->getTimestamp() ?? 0)
            ->first();
        $latestReconciliationAt = $payment->latest_reconciliation_processed_at !== null
            ? Carbon::parse((string) $payment->latest_reconciliation_processed_at)
            : $latestReconciliation?->processed_at;
        $callbackCount = $payment->callback_received_count
            ?? collect($eventSummary)->where('type', 'callback')->sum('received_count');

        return [
            'provider' => strtolower((string) $payment->provider),
            'merchant_ref_short' => $payment->provider === PaymentProvider::SAKURUPIAH
                ? $this->maskIdentifier((string) $payment->merchant_ref)
                : $this->bounded((string) $payment->merchant_ref, 8),
            'merchant_ref_hash' => hash('sha256', (string) $payment->merchant_ref),
            'base_amount' => Money::idr((int) $payment->amount),
            'final_amount' => $payment->provider_total_payment !== null ? Money::idr((int) $payment->provider_total_payment) : '-',
            'provider_create_state' => $payment->provider_create_state ?: '-',
            'provider_status' => $eventSummary[0]['provider_status'] ?? '-',
            'expires_at' => $payment->provider_expires_at?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
            'callback_count' => (int) $callbackCount,
            'latest_reconciliation_at' => $latestReconciliationAt?->copy()->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '-',
            'checkout_delivery_state' => $checkoutEffect?->state?->value ?? '-',
            'checkout_delivery_outcome' => (string) ($deliveryMetadata['delivery_outcome'] ?? '-'),
            'checkout_delivery_method' => (string) ($deliveryMetadata['delivery_method'] ?? '-'),
            'can_reconcile' => $user !== null
                && Gate::forUser($user)->allows('reconcile', $payment)
                && $paymentOperations->canReconcile($payment),
            'reconcile_url' => route('admin.payments.reconcile', $payment),
            'event_total' => $eventTotal,
            'events' => $eventSummary,
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
