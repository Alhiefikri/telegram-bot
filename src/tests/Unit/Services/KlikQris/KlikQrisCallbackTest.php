<?php

namespace Tests\Unit\Services\KlikQris;

use App\Services\KlikQris\KlikQrisCallback;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class KlikQrisCallbackTest extends TestCase
{
    public function test_paid_callback_parses_as_supported_trigger_and_sanitizes_signature(): void
    {
        $callback = KlikQrisCallback::fromPayload([
            'order_id' => 'STEST-123',
            'amount' => '1000',
            'total_amount' => '1016.00',
            'status' => 'paid',
            'signature' => 'opaque-test-signature',
            'qris_url' => 'https://klikqris.com/qr/callback',
        ]);

        $this->assertSame('STEST-123', $callback->orderId());
        $this->assertSame(1000, $callback->amount()->rupiah());
        $this->assertSame(1016, $callback->totalAmount());
        $this->assertSame('PAID', $callback->status());
        $this->assertTrue($callback->isSupportedStatus());
        $this->assertTrue($callback->isPaidTrigger());
        $this->assertArrayNotHasKey('signature', $callback->sanitizedPayload());
        $this->assertSame('https://klikqris.com/qr/callback', $callback->sanitizedPayload()['qris_url']);
    }

    public function test_safe_unknown_status_is_observable_without_being_supported_or_a_paid_trigger(): void
    {
        $callback = KlikQrisCallback::fromPayload([
            'order_id' => 'STEST-123',
            'amount' => '1000',
            'total_amount' => '1016.00',
            'status' => ' settled.review-1 ',
            'signature' => 'opaque-test-signature',
            'merchant_id' => 'opaque-merchant-id',
        ]);

        $this->assertSame('SETTLED.REVIEW-1', $callback->status());
        $this->assertFalse($callback->isSupportedStatus());
        $this->assertFalse($callback->isPaidTrigger());
        $this->assertSame([
            'condition' => 'unknown_callback_status',
            'status' => 'SETTLED.REVIEW-1',
            'merchant_ref_hash' => hash('sha256', 'STEST-123'),
        ], $callback->sanitizedPayload());
    }

    #[DataProvider('malformedCallbacks')]
    public function test_callback_rejects_malformed_identity_amounts_or_status(array $payload, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        KlikQrisCallback::fromPayload($payload);
    }

    public static function malformedCallbacks(): array
    {
        return [
            'blank order id' => [[
                'order_id' => '   ',
                'amount' => '1000',
                'total_amount' => '1016.00',
                'status' => 'PAID',
            ], 'KlikQRIS order_id is required.'],
            'invalid amount' => [[
                'order_id' => 'STEST-123',
                'amount' => '1000.01',
                'total_amount' => '1016.00',
                'status' => 'PAID',
            ], 'KlikQRIS amount must be a positive whole-rupiah value.'],
            'status with control character' => [[
                'order_id' => 'STEST-123',
                'amount' => '1000',
                'total_amount' => '1016.00',
                'status' => "REVIEW\nREQUIRED",
            ], 'KlikQRIS callback status is invalid.'],
            'unbounded status' => [[
                'order_id' => 'STEST-123',
                'amount' => '1000',
                'total_amount' => '1016.00',
                'status' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            ], 'KlikQRIS callback status is invalid.'],
        ];
    }
}
