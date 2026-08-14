<?php

namespace App\Storefront;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Read-only public projection of the canonical Paid Order Proof Event. */
class VerifiedPurchaseActivity
{
    private const CACHE_KEY = 'storefront.verified-purchase-activity.v1';

    public const MAX_ITEMS = 8;

    /** @return Collection<int, array{id:string,buyer:string,product:string,occurred_at:string,relative_time:string}> */
    public function recent(): Collection
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(45), function (): Collection {
            return Order::query()
                ->select(['id', 'customer_id', 'order_status', 'payment_status', 'created_at'])
                ->with(['customer:id,first_name', 'orderItems:id,order_id,product_name,product_id,quantity', 'inventoryMovements:id,order_id,product_id,type,quantity,created_at'])
                ->whereIn('order_status', [OrderStatus::Processing->value, OrderStatus::Completed->value])
                ->where('payment_status', PaymentStatus::Paid)
                ->whereHas('inventoryMovements', fn ($query) => $query->where('type', InventoryMovementType::Sale))
                ->latest('updated_at')
                ->limit(self::MAX_ITEMS * 5)
                ->get()
                ->filter(fn (Order $order) => $this->hasMatchingSaleProof($order))
                ->take(self::MAX_ITEMS)
                ->map(fn (Order $order) => $this->project($order))
                ->values();
        });
    }

    private function hasMatchingSaleProof(Order $order): bool
    {
        return $order->orderItems->isNotEmpty() && $order->orderItems->every(function ($item) use ($order): bool {
            $sale = $order->inventoryMovements
                ->first(fn ($movement) => $movement->product_id === $item->product_id && $movement->type === InventoryMovementType::Sale);

            return $sale !== null && $sale->quantity === -$item->quantity;
        });
    }

    /** @return array{id:string,buyer:string,product:string,occurred_at:string,relative_time:string} */
    private function project(Order $order): array
    {
        $occurredAt = $order->inventoryMovements
            ->where('type', InventoryMovementType::Sale)
            ->min('created_at') ?? $order->created_at;
        $buyer = trim((string) $order->customer?->first_name);

        return [
            'id' => hash_hmac('sha256', (string) $order->getKey(), (string) config('app.key')),
            'buyer' => $this->mask($buyer),
            'product' => (string) $order->orderItems->first()->product_name,
            'occurred_at' => $occurredAt->toIso8601String(),
            'relative_time' => $occurredAt->locale('id')->diffForHumans(),
        ];
    }

    private function mask(string $buyer): string
    {
        $first = mb_substr($buyer, 0, 1);

        return $first === '' ? 'Pembeli terverifikasi' : $first.'***';
    }
}
