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
            'source' => $metadata['source'] ?? 'product_page',
        ] + $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function checkoutSubmitted(?Product $product = null, ?Order $order = null, array $metadata = []): void
    {
        $subject = $order ?? $product;
        $base = array_filter([
            'product_id' => $product?->getKey(),
            'order_id' => $order?->getKey(),
            'invoice_number' => $order?->invoice_number,
            'order_status' => $order?->order_status?->value,
            'payment_status' => $order?->payment_status?->value,
        ], fn ($value): bool => $value !== null);

        $this->log('storefront.checkout_submitted', $subject, $base + $metadata);
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
        $this->audit->log($action, $subject, new: $this->sanitize($this->withRequestContext($metadata)));
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
            'path',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'session_hash',
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
            ->map(function ($value, string $key): mixed {
                if (! is_scalar($value) && $value !== null) {
                    return null;
                }
                if ($value === null) {
                    return null;
                }

                if (is_string($value)) {
                    $value = trim($value);
                }
                if (is_string($value) && ($value === '' || mb_strlen($value) > $this->maxLength($key) || preg_match('/[\\x00-\\x1F\\x7F]/', $value) === 1)) {
                    return null;
                }
                if (in_array($key, ['source', 'utm_source', 'utm_medium', 'utm_campaign'], true)
                    && (! is_string($value) || ! $this->isSafeAttribution($value))) {
                    return null;
                }
                if ($key === 'session_hash' && (! is_string($value) || preg_match('/^[a-f0-9]{64}$/iD', $value) !== 1)) {
                    return null;
                }
                if ($key === 'path' && (! is_string($value) || preg_match('/^\\/[A-Za-z0-9._~%\\/-]{0,199}$/D', $value) !== 1)) {
                    return null;
                }

                return $value;
            })
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->all();
    }

    private function withRequestContext(array $metadata): array
    {
        $context = [
            'source' => 'direct',
            'path' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'session_hash' => $this->anonymousSessionHash(),
        ];

        if (app()->bound('request')) {
            $request = request();
            $context['path'] = '/'.ltrim($request->path(), '/');
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $field) {
                $context[$field] = $request->query($field);
            }
        }

        return array_replace($context, $metadata);
    }

    private function anonymousSessionHash(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();
        if (! $request->hasSession()) {
            return null;
        }

        $sessionId = (string) $request->session()->getId();

        return $sessionId === '' ? null : hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    private function maxLength(string $key): int
    {
        return match ($key) {
            'path' => 200,
            'failure_kind' => 120,
            'invoice_number', 'provider_expires_at' => 120,
            default => 80,
        };
    }

    private function isSafeAttribution(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._~%+\\/:\\-]{1,80}$/D', $value) === 1
            && preg_match('/^\\+?\\d[\\d-]{6,}$/D', $value) !== 1;
    }
}
