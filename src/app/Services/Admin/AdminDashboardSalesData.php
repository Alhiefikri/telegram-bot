<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Order;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\SupplierFulfillment;
use App\Models\TelegramOutboundEffect;
use App\Services\PaymentReceiptReport;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class AdminDashboardSalesData
{
    private const ORDERS_PER_PAGE = 10;

    private ?StoreChannelSetting $storeChannelSetting = null;

    public function __construct(private readonly PaymentReceiptReport $receiptReport) {}

    /**
     * @param  array<string, mixed>  $statusCounts
     * @return array<string, mixed>
     */
    public function forSales(array $statusCounts, bool $canRetrySalesProof = false, ?string $activeFilter = null): array
    {
        $activeFilter = $this->normalizeFilter($activeFilter);
        [$orders, $pagination] = $this->latestOrders($canRetrySalesProof, $activeFilter);

        return [
            'orders' => $orders,
            'filters' => $this->salesFilters($statusCounts, $activeFilter),
            'statusCounts' => $statusCounts,
            'active_filter' => $activeFilter,
            'pagination' => $pagination,
        ];
    }

    private function latestOrders(bool $canRetrySalesProof, string $activeFilter): array
    {
        $statuses = $this->statusesForFilter($activeFilter);
        $paginator = Order::query()
            ->with([
                'customer:id,first_name,username',
                'orderItems:id,order_id,product_name,quantity,subtotal',
                'payments' => fn ($query) => $query
                    ->where('status', PaymentStatus::Paid)
                    ->select([
                        'id',
                        'order_id',
                        'amount',
                        'currency',
                        'provider',
                        'method',
                        'provider_payment_method',
                        'provider_fee',
                        'provider_total_payment',
                        'status',
                        'paid_at',
                    ]),
                'salesProofPost:id,order_id,status,telegram_message_id,last_error,attempts',
                'telegramOutboundEffects' => fn ($query) => $query
                    ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                    ->select([
                        'id',
                        'effect_group_key',
                        'supersedes_effect_id',
                        'sequence',
                        'effect_type',
                        'source_type',
                        'source_id',
                        'state',
                        'attempts',
                        'telegram_message_id',
                        'sent_at',
                        'unknown_at',
                        'manual_review_at',
                        'last_error',
                        'last_error_code',
                        'outcome_metadata',
                        'created_at',
                        'updated_at',
                    ]),
            ])
            ->select(['id', 'customer_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'created_at', 'telegram_receipt_sent_at'])
            ->when($statuses !== null, fn ($query) => $query->whereIn('order_status', array_map(fn (OrderStatus $status): string => $status->value, $statuses)))
            ->withCount('orderItems')
            ->latest()
            ->paginate(self::ORDERS_PER_PAGE, ['*'], 'sales_page')
            ->withQueryString();

        $orderIds = $paginator->getCollection()->modelKeys();
        $supplierFulfillments = SupplierFulfillment::query()
            ->select(['id', 'order_id', 'state'])
            ->with(['purchases:id,supplier_fulfillment_id,state'])
            ->withExists('manualHandoffs')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get()
            ->groupBy('order_id');
        $orders = $paginator->getCollection()
            ->map(function (Order $order) use ($canRetrySalesProof, $supplierFulfillments): array {
                $earning = $this->paidReceiptLabel($order);
                $orderStatusTone = $this->orderStatusTone($order->order_status);
                $paymentStatusTone = $this->paymentStatusTone($order->payment_status);
                $supplierProgress = $this->supplierProgress($supplierFulfillments->get($order->id, collect()), $order);

                return [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'detail_url' => route('admin.orders.show', $order),
                    'customer' => $this->customerLabel($order),
                    'product_name' => $this->orderProductName($order),
                    'filter_groups' => $this->orderFilterGroups($order->order_status),
                    'order_status' => $order->order_status->value,
                    'order_status_label' => $this->orderStatusLabel($order->order_status),
                    'order_status_tone' => $orderStatusTone,
                    'payment_status' => $order->payment_status->value,
                    'payment_status_label' => $this->paymentStatusLabel($order->payment_status),
                    'payment_status_tone' => $paymentStatusTone,
                    'payment_attention' => $this->paymentAttention($order),
                    'supplier_delivery_status' => $supplierProgress['delivery'] ?? null,
                    'supplier_purchase_status' => $supplierProgress['purchase'] ?? null,
                    'price' => Money::idr($order->total_amount),
                    'earning' => $earning['label'],
                    'earning_amount' => $earning['amount'],
                    'item_count' => $order->order_items_count,
                    'sales_proof' => $this->salesProofState($order, $canRetrySalesProof),
                    'created_at' => $order->created_at?->toDateTimeString(),
                    'created_at_label' => $order->created_at?->format('d M Y') ?? '-',
                ];
            })
            ->all();

        return [$orders, $this->pagination($paginator)];
    }

    /** @param Collection<int, SupplierFulfillment> $fulfillments
     * @return array{delivery:string,purchase:string}|null
     */
    private function supplierProgress(Collection $fulfillments, Order $order): ?array
    {
        if ($fulfillments->isEmpty()) {
            return null;
        }
        $deliveryStates = $fulfillments->map(fn (SupplierFulfillment $fulfillment): string => match ($fulfillment->state) {
            'delivered' => $fulfillment->manual_handoffs_exists
                ? 'selesai · diserahkan di luar bot'
                : ($order->telegram_receipt_sent_at !== null ? 'receipt Telegram terkirim' : 'fulfillment tersedia · receipt Telegram belum terkonfirmasi'),
            'review' => 'perlu review',
            'cancelled' => 'dibatalkan',
            default => 'sedang diproses',
        })->unique()->implode(', ');
        $purchaseStates = $fulfillments->flatMap(fn (SupplierFulfillment $fulfillment) => $fulfillment->purchases->pluck('state'))
            ->map(fn (string $state): string => match ($state) {
                'ambiguous' => 'ambigu',
                'review' => 'perlu review',
                'settled' => 'debit tercatat',
                'delivered' => 'selesai',
                'rejected' => 'ditolak · no-debit terbukti',
                'sending' => 'sedang dikirim',
                'awaiting_delivery' => 'menunggu hasil provider',
                'reserved', 'pending' => 'menunggu dispatch',
                default => 'belum diketahui',
            })->unique()->implode(', ');

        return [
            'delivery' => $deliveryStates,
            'purchase' => $purchaseStates === '' ? 'belum ada request supplier' : $purchaseStates,
        ];
    }

    /**
     * @return array{label: string, amount: int}
     */
    private function paidReceiptLabel(Order $order): array
    {
        $payment = $this->receiptReport->canonicalFromPayments($order->payments)->first();
        if ($payment === null) {
            return ['label' => Money::idr(0), 'amount' => 0];
        }

        $amount = $this->receiptReport->netAmount($payment);
        if ($this->receiptReport->currency($payment) === 'USDT') {
            return ['label' => Money::usdt($amount), 'amount' => $amount];
        }

        return ['label' => Money::idr($amount), 'amount' => $amount];
    }

    public function paymentAttention(Order $order): ?array
    {
        if (! $this->isLatePaidReleasedOrder($order)) {
            return null;
        }

        return [
            'label' => 'Late paid',
            'message' => 'Perlu refund/support manual.',
            'tone' => 'warning',
        ];
    }

    private function salesFilters(array $statusCounts, string $activeFilter): array
    {
        $countFor = fn (OrderStatus ...$statuses): int => collect($statuses)
            ->sum(fn (OrderStatus $status): int => $statusCounts[$status->value]['count'] ?? 0);

        return collect([
            [
                'key' => 'all',
                'label' => 'Semua',
                'count' => $countFor(...OrderStatus::cases()),
            ],
            [
                'key' => 'completed',
                'label' => 'Selesai',
                'count' => $countFor(OrderStatus::Completed),
            ],
            [
                'key' => 'issue',
                'label' => 'Masalah',
                'count' => $countFor(OrderStatus::Cancelled, OrderStatus::Expired),
            ],
            [
                'key' => 'waiting',
                'label' => 'Menunggu',
                'count' => $countFor(OrderStatus::Draft, OrderStatus::PendingPayment),
            ],
            [
                'key' => 'processing',
                'label' => 'Diproses',
                'count' => $countFor(OrderStatus::Processing),
            ],
        ])
            ->map(fn (array $filter): array => $filter + [
                'active' => $filter['key'] === $activeFilter,
                'url' => route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => $filter['key']]),
            ])
            ->all();
    }

    /**
     * @return list<OrderStatus>|null
     */
    private function statusesForFilter(string $filter): ?array
    {
        return match ($filter) {
            'completed' => [OrderStatus::Completed],
            'issue' => [OrderStatus::Cancelled, OrderStatus::Expired],
            'waiting' => [OrderStatus::Draft, OrderStatus::PendingPayment],
            'processing' => [OrderStatus::Processing],
            default => null,
        };
    }

    private function normalizeFilter(?string $filter): string
    {
        $filter = (string) ($filter ?: 'all');

        return in_array($filter, ['all', 'completed', 'issue', 'waiting', 'processing'], true)
            ? $filter
            : 'all';
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'has_pages' => $paginator->hasPages(),
            'previous_url' => $paginator->previousPageUrl(),
            'next_url' => $paginator->nextPageUrl(),
        ];
    }

    private function orderProductName(Order $order): string
    {
        $firstItem = $order->orderItems->first();

        if (! $firstItem) {
            return 'Produk';
        }

        $remainingItems = max(0, $order->order_items_count - 1);

        return $remainingItems > 0
            ? "{$firstItem->product_name} + {$remainingItems} produk"
            : $firstItem->product_name;
    }

    private function orderFilterGroups(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Completed => ['all', 'completed'],
            OrderStatus::Cancelled, OrderStatus::Expired => ['all', 'issue'],
            OrderStatus::Draft, OrderStatus::PendingPayment => ['all', 'waiting'],
            OrderStatus::Processing => ['all', 'processing'],
        };
    }

    private function customerLabel(Order $order): string
    {
        if (! $order->customer) {
            return 'Pelanggan';
        }

        if ($order->customer->first_name && $order->customer->username) {
            return "{$order->customer->first_name} (@{$order->customer->username})";
        }

        return $order->customer->first_name ?: ($order->customer->username ? "@{$order->customer->username}" : 'Pelanggan');
    }

    public function salesProofState(Order $order, bool $canRetrySalesProof = false): array
    {
        $post = $order->salesProofPost;
        if (! $post) {
            return [
                'status' => 'none',
                'label' => 'Belum ada post',
                'tone' => 'slate',
                'message_id' => null,
                'last_error' => null,
                'attempts' => 0,
                'can_retry' => false,
                'retry_url' => null,
                'review_url' => null,
            ];
        }

        $effect = $this->salesProofEffect($order);
        $effectState = $effect?->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect?->getRawOriginal('state');
        if ($effect !== null && in_array($effectState, [
            TelegramOutboundEffectState::Unknown->value,
            TelegramOutboundEffectState::ManualReview->value,
        ], true)) {
            return [
                'status' => $effectState,
                'label' => $effectState === TelegramOutboundEffectState::ManualReview->value ? 'Manual Delivery Review' : 'Unknown',
                'tone' => 'amber',
                'message_id' => $effect->telegram_message_id,
                'last_error' => $effect->last_error ?: 'Sales Proof delivery outcome requires review.',
                'attempts' => (int) $effect->attempts,
                'can_retry' => false,
                'retry_url' => route('admin.orders.sales-proof.retry', $order),
                'review_url' => route('admin.telegram-outbound-effects.show', $effect),
            ];
        }

        if (in_array($post->status, [SalesProofPost::STATUS_PENDING, SalesProofPost::STATUS_SENDING], true)) {
            $setting = $this->storeChannelSetting();
            if (! $setting->sales_proof_enabled) {
                return $this->salesProofUnavailableState($order, $post, 'disabled', 'Disabled', 'Sales Proof Post is disabled.', false);
            }

            if (! ($setting->telegram_chat_id ?: $setting->channel_username)) {
                return $this->salesProofUnavailableState($order, $post, SalesProofPost::STATUS_FAILED, 'Failed', 'Store Channel chat is not configured.', $canRetrySalesProof);
            }
        }

        $tone = match ($post->status) {
            SalesProofPost::STATUS_SENT => 'emerald',
            SalesProofPost::STATUS_FAILED => 'rose',
            SalesProofPost::STATUS_PENDING, SalesProofPost::STATUS_SENDING, SalesProofPost::STATUS_UNKNOWN, SalesProofPost::STATUS_MANUAL_REVIEW => 'amber',
            default => 'slate',
        };

        return [
            'status' => $post->status,
            'label' => match ($post->status) {
                SalesProofPost::STATUS_PENDING => 'Pending',
                SalesProofPost::STATUS_SENDING => 'Sending',
                SalesProofPost::STATUS_SENT => 'Sent',
                SalesProofPost::STATUS_FAILED => 'Failed',
                SalesProofPost::STATUS_UNKNOWN => 'Unknown',
                SalesProofPost::STATUS_MANUAL_REVIEW => 'Manual Delivery Review',
                default => ucfirst((string) $post->status),
            },
            'tone' => $tone,
            'message_id' => $post->telegram_message_id,
            'last_error' => $post->last_error,
            'attempts' => $post->attempts,
            'can_retry' => $canRetrySalesProof && $post->status === SalesProofPost::STATUS_FAILED,
            'retry_url' => route('admin.orders.sales-proof.retry', $order),
            'review_url' => $effect !== null && in_array($effectState, [
                TelegramOutboundEffectState::Unknown->value,
                TelegramOutboundEffectState::ManualReview->value,
            ], true) ? route('admin.telegram-outbound-effects.show', $effect) : null,
        ];
    }

    private function salesProofUnavailableState(Order $order, SalesProofPost $post, string $status, string $label, string $lastError, bool $canRetry): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'tone' => $status === SalesProofPost::STATUS_FAILED ? 'rose' : 'slate',
            'message_id' => $post->telegram_message_id,
            'last_error' => $lastError,
            'attempts' => $post->attempts,
            'can_retry' => $canRetry,
            'retry_url' => route('admin.orders.sales-proof.retry', $order),
            'review_url' => null,
        ];
    }

    private function salesProofEffect(Order $order): ?TelegramOutboundEffect
    {
        if ($order->relationLoaded('telegramOutboundEffects')) {
            return $order->telegramOutboundEffects
                ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                ->sortByDesc(fn (TelegramOutboundEffect $effect): int => (int) $effect->sequence)
                ->first();
        }

        return TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $order->getKey())
            ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
            ->orderByDesc('sequence')
            ->orderByDesc('created_at')
            ->first();
    }

    private function storeChannelSetting(): StoreChannelSetting
    {
        return $this->storeChannelSetting ??= StoreChannelSetting::current();
    }

    private function orderStatusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
            OrderStatus::PendingPayment => 'Menunggu bayar',
            OrderStatus::Processing => 'Diproses',
            OrderStatus::Completed => 'Selesai',
            OrderStatus::Cancelled => 'Dibatalkan',
            OrderStatus::Expired => 'Expired',
        };
    }

    private function paymentStatusLabel(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'Pending',
            PaymentStatus::Paid => 'Paid',
            PaymentStatus::Failed => 'Failed',
            PaymentStatus::Expired => 'Expired',
            PaymentStatus::Refunded => 'Refunded',
        };
    }

    private function paymentStatusTone(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid => 'emerald',
            PaymentStatus::Failed, PaymentStatus::Expired => 'rose',
            PaymentStatus::Pending => 'amber',
            PaymentStatus::Refunded => 'slate',
        };
    }

    private function orderStatusTone(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Processing => 'amber',
            OrderStatus::Completed => 'emerald',
            OrderStatus::Cancelled, OrderStatus::Expired => 'rose',
            default => 'slate',
        };
    }

    private function isLatePaidReleasedOrder(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Cancelled, OrderStatus::Expired], true);
    }
}
