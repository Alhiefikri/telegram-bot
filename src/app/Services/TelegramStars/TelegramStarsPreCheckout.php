<?php

namespace App\Services\TelegramStars;

use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Models\ProductStockItem;
use App\Services\InventoryLedger;
use Illuminate\Support\Facades\DB;

final readonly class TelegramStarsPreCheckout
{
    public const GRACE_SECONDS = 120;

    public const RESPONSE_BUDGET_NS = 1_700_000_000;

    public function __construct(
        private StarsPricingPolicyManager $policy,
        private InventoryLedger $inventory,
    ) {}

    /** The ingress owns the transaction; lock Order → Payment → inventory, as on release. */
    public function approve(array $query, int $deadline, bool $replayOnly): bool
    {
        if ($query['currency'] !== 'XTR') {
            return false;
        }

        $target = PaymentTelegramStarsDetail::where('invoice_payload', $query['invoice_payload'])->first();
        if ($target === null || $target->telegram_user_id !== $query['from']['id'] || $target->total_xtr !== $query['total_amount']) {
            return false;
        }

        $orderId = Payment::whereKey($target->payment_id)->value('order_id');
        $order = $orderId === null ? null : Order::whereKey($orderId)->lockForUpdate()->first();
        $payment = $order === null ? null : Payment::whereKey($target->payment_id)->lockForUpdate()->first();
        if ($payment === null || ! TelegramStarsInvoiceTarget::isPending($target, $order, $payment)
            || ! TelegramStarsInvoiceOutbound::hasAcknowledgedInvoice($target)) {
            return false;
        }

        $hash = hash('sha256', $query['id']);
        $claim = DB::table('telegram_stars_approvals')->where('payment_id', $payment->id)->first();
        if ($claim !== null) {
            return hash_equals($claim->query_hash, $hash)
                && now()->getTimestamp() < (int) $claim->approved_at_epoch + 10
                && hrtime(true) < $deadline;
        }

        if ($replayOnly || $target->deadline_epoch <= now()->getTimestamp()
            || ! $this->policy->lockForCheckout()->issuanceEnabled
            || ! $this->hasReservation($order)
            || hrtime(true) >= $deadline) {
            return false;
        }

        $now = now()->getTimestamp();

        return $now < $target->deadline_epoch && DB::table('telegram_stars_approvals')->insertOrIgnore([
            'payment_id' => $payment->id,
            'query_hash' => $hash,
            'approved_at_epoch' => $now,
        ]) === 1;
    }

    /** Call while holding the same Order and Payment locks as approval. */
    public static function graceIsActive(Payment $payment): bool
    {
        return $payment->isTelegramStars() && $payment->status === PaymentStatus::Pending
            && DB::table('telegram_stars_approvals')->where('payment_id', $payment->id)
                ->where('approved_at_epoch', '>', now()->getTimestamp() - self::GRACE_SECONDS)->exists();
    }

    private function hasReservation(Order $order): bool
    {
        [$items, $movements, $products] = $this->inventory->orderInventoryState($order);
        $credentialCounts = ProductStockItem::query()->localCatalogStock()->whereNull('retired_at')
            ->whereIn('order_item_id', $items->modelKeys())
            ->selectRaw('order_item_id, product_id, count(*) as quantity')
            ->groupBy('order_item_id', 'product_id')->get()->groupBy('order_item_id');

        return $items->isNotEmpty() && $items->every(function ($item) use ($movements, $products, $credentialCounts): bool {
            $product = $products->get($item->product_id);
            $proof = $movements->where('product_id', $item->product_id);
            $credentials = $credentialCounts->get($item->id);

            return $product !== null && $item->quantity > 0 && $item->supplier_quantity === 0
                && $item->localStockQuantity() === $item->quantity
                && $product->stock >= $item->quantity && $product->reserved_stock >= $item->quantity
                && $proof->count() === 1 && $proof->first()->type === InventoryMovementType::Reservation
                && $proof->first()->quantity === $item->quantity
                && $credentials?->count() === 1 && (int) $credentials->first()->product_id === $item->product_id
                && (int) $credentials->first()->quantity === $item->quantity;
        });
    }
}
