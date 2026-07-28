<?php

namespace Tests\Support;

use App\Enums\PaymentStatus;
use App\Models\Payment;

final class KlikQrisSandboxScenarios
{
    public static function readyConfig(): array
    {
        return [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://klikqris.com/api/sandbox',
            'sandbox_api_key' => 'test-only-key',
            'sandbox_merchant_id' => 'merchant-test',
            'qris_ttl_minutes' => 15,
            'http_timeout_seconds' => 4.5,
            'http_connect_timeout_seconds' => 1.5,
            'status_max_attempts' => 3,
            'status_retry_delay_ms' => 0,
            'webhook_rate_limit_per_minute' => 30,
            'status_refresh_rate_limit_per_minute' => 3,
            'reconciliation_command' => 'klikqris:reconcile --chunk=100',
        ];
    }

    public static function configureReadySandbox(): void
    {
        config()->set('services.klikqris', self::readyConfig());
        config()->set('queue.default', 'database');
    }

    public static function pngDataUri(string $body = 'test-qris-png'): string
    {
        return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1A\n".$body);
    }

    public static function klikQrisPayment(array $attributes = []): Payment
    {
        return Payment::factory()->create(array_replace([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => 'INV/ORDER 100% A+B',
            'amount' => 10000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
        ], $attributes));
    }

    public static function createPayload(Payment $payment, array $overrides = []): array
    {
        return ['data' => array_replace([
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'total_amount' => $payment->amount + 16,
            'status' => 'PENDING',
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png',
            'expired_at' => '2026-07-28T07:16:00Z',
            'signature' => 'opaque-test-signature',
        ], $overrides)];
    }

    public static function statusPayload(Payment $payment, array $overrides = []): array
    {
        return ['data' => array_replace([
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'total_amount' => $payment->amount + 16,
            'status' => 'SUCCESS',
            'paid_at' => '2026-07-28T07:05:00Z',
            'signature' => 'opaque-test-signature',
        ], $overrides)];
    }
}
