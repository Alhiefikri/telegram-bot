<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class StorefrontFunnelEvents
{
    /**
     * @var list<string>
     */
    public const ACTIONS = [
        'storefront.product_opened',
        'storefront.checkout_opened',
        'storefront.checkout_submitted',
        'storefront.qris_created',
        'storefront.payment_verified',
        'storefront.credentials_opened',
        'storefront.support_selected',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function productOpened(Product $product, array $metadata = []): void
    {
        $this->log('storefront.product_opened', $product, [
            'product_id' => $product->getKey(),
            'product_public' => $product->isPublicVisible(),
        ] + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function checkoutOpened(Product $product, array $metadata = []): void
    {
        $this->log('storefront.checkout_opened', $product, [
            'product_id' => $product->getKey(),
            'source' => 'product_page',
        ] + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function checkoutSubmitted(?Product $product = null, ?Order $order = null, array $metadata = []): void
    {
        $subject = $order ?? $product;

        $this->log('storefront.checkout_submitted', $subject, array_filter([
            'product_id' => $product?->getKey(),
            'order_id' => $order?->getKey(),
            'invoice_number' => $order?->invoice_number,
            'order_status' => $order?->order_status?->value,
            'payment_status' => $order?->payment_status?->value,
        ], fn ($value): bool => $value !== null) + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function qrisCreated(Order $order, Payment $payment, array $metadata = []): void
    {
        $this->log('storefront.qris_created', $payment, $this->orderPaymentMetadata($order, $payment) + [
            'provider_create_state' => $payment->provider_create_state,
            'provider_fee' => $payment->provider_fee,
            'provider_total_payment' => $payment->provider_total_payment,
            'provider_expires_at' => $payment->provider_expires_at?->toAtomString(),
        ] + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function paymentVerified(Order $order, Payment $payment, array $metadata = []): void
    {
        $this->log('storefront.payment_verified', $payment, $this->orderPaymentMetadata($order, $payment) + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function credentialsOpened(Order $order, ?Payment $payment, array $metadata = []): void
    {
        $this->log('storefront.credentials_opened', $order, array_filter($this->orderPaymentMetadata($order, $payment) + $metadata, fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function supportSelected(?Order $order, ?Product $product, array $metadata = []): void
    {
        $subject = $order ?? $product;

        $this->log('storefront.support_selected', $subject, array_filter([
            'order_id' => $order?->getKey(),
            'invoice_number' => $order?->invoice_number,
            'order_status' => $order?->order_status?->value,
            'payment_status' => $order?->payment_status?->value,
            'product_id' => $product?->getKey(),
        ], fn ($value): bool => $value !== null) + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logAllowed(string $action, ?Order $order, ?Product $product, array $metadata): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return;
        }

        match ($action) {
            'storefront.support_selected' => $this->supportSelected($order, $product, $metadata),
            'storefront.checkout_opened' => $product ? $this->checkoutOpened($product, $metadata) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(string $action, ?Model $subject, array $metadata): void
    {
        $this->audit->log($action, $subject, new: $this->sanitize($metadata));
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPaymentMetadata(Order $order, ?Payment $payment): array
    {
        return [
            'order_id' => $order->getKey(),
            'invoice_number' => $order->invoice_number,
            'order_status' => $order->order_status instanceof OrderStatus ? $order->order_status->value : $order->order_status,
            'payment_status' => $payment?->status instanceof PaymentStatus ? $payment->status->value : ($payment?->status ?? $order->payment_status?->value),
            'payment_id' => $payment?->getKey(),
            'provider' => $payment?->provider,
            'payment_method' => $payment?->method,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $allowed = [
            'product_id',
            'product_public',
            'source',
            'order_id',
            'invoice_number',
            'order_status',
            'payment_status',
            'payment_id',
            'provider',
            'payment_method',
            'provider_create_state',
            'provider_fee',
            'provider_total_payment',
            'provider_expires_at',
            'qris_readiness_state',
            'recovery_cause',
            'failure_kind',
            'delivery_type',
            'manual_fulfillment_status',
            'support_channel',
            'support_category',
            'status_endpoint_result',
            'idempotent_reuse',
        ];

        return collect($metadata)
            ->only($allowed)
            ->map(fn ($value) => is_scalar($value) || $value === null ? $value : (string) $value)
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->all();
    }
}
