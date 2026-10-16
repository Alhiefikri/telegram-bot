<?php

namespace App\Services\CustomerApi;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\UsdtBalance\UsdtMicroAmount;

/**
 * Safe, reconstructed API order projection. Every value comes from the durable
 * Order/Payment/items that own it; nothing is read live from catalog pricing or
 * stock, so a replay after a response loss, price edit or key rotation returns
 * the same accepted identity and original currency/amount. No credential, cost,
 * supplier, ledger or secret internals cross this allowlist.
 */
final readonly class CustomerApiOrderResult
{
    public function __construct(public Order $order, public bool $replayed) {}

    public static function created(Order $order): self
    {
        return new self($order, false);
    }

    public static function replay(Order $order): self
    {
        return new self($order, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $requestId): array
    {
        $order = $this->order->loadMissing(['orderItems', 'payments']);
        $item = $order->orderItems->sortBy('id')->first();
        $payment = $order->payments->firstWhere('status', PaymentStatus::Paid)
            ?? $order->payments->sortByDesc('id')->first();
        $currency = $payment?->currency === UsdtMicroAmount::CURRENCY ? 'USD' : $payment?->currency;

        return [
            'id' => (string) $order->getKey(),
            'invoice_number' => $order->invoice_number,
            'origin' => 'api',
            'status' => $order->order_status->value,
            'payment_status' => $order->payment_status->value,
            'currency' => $currency ?? ($order->currency === UsdtMicroAmount::CURRENCY ? 'USD' : $order->currency),
            'amount' => $payment === null
                ? null
                : ($payment->currency === UsdtMicroAmount::CURRENCY
                    ? UsdtMicroAmount::formatUnits((int) $payment->amount)
                    : (string) $payment->amount),
            'quantity' => (int) $order->orderItems->sum('quantity'),
            'product' => $item === null ? null : ['id' => (string) $item->product_id, 'name' => $item->product_name],
            'created_at' => $order->created_at?->toIso8601String(),
            'request_id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $requestId): array
    {
        return ['data' => $this->data($requestId), 'idempotent_replay' => $this->replayed];
    }
}
