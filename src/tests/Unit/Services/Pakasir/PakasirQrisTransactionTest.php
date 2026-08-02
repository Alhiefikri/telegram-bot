<?php

namespace Tests\Unit\Services\Pakasir;

use App\Models\Payment;
use App\Services\Pakasir\PakasirQrisTransaction;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class PakasirQrisTransactionTest extends TestCase
{
    public function test_it_rejects_the_reported_inconsistent_fee_and_total_regression(): void
    {
        $payment = new Payment([
            'merchant_ref' => 'INV-UNIT-QRIS-BAD-TOTAL',
            'amount' => 20000,
        ]);
        $transaction = PakasirQrisTransaction::fromPayload([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'INV-UNIT-QRIS-BAD-TOTAL',
                'amount' => 20000,
                'fee' => 2660,
                'total_payment' => 268660,
                'payment_method' => 'qris',
                'payment_number' => '00020101021253033605409268660.005802ID6304D63B',
            ],
        ]);

        $this->assertFalse($transaction->hasValidFinalAmount());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Pakasir QRIS fee mismatch.');
        $transaction->assertMatches($payment, 'warung-ai');
    }

    public function test_it_reads_native_qris_response_and_sanitizes_sensitive_provider_fields(): void
    {
        $paymentNumber = '00020101021226610016ID.CO.QRIS.UNIT-QRIS-1';
        $transaction = PakasirQrisTransaction::fromPayload([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'INV-UNIT-QRIS-1',
                'amount' => 25000,
                'fee' => '103',
                'total_payment' => '25103',
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => '2026-07-04T16:30:00.123456789Z',
                'api_key' => 'provider-secret',
                'metadata' => [
                    'safe_note' => 'keep',
                    'authorization' => 'Bearer provider-token',
                ],
            ],
        ]);

        $this->assertSame('warung-ai', $transaction->project());
        $this->assertSame('INV-UNIT-QRIS-1', $transaction->orderId());
        $this->assertSame(25000, $transaction->amount());
        $this->assertSame(103, $transaction->fee());
        $this->assertSame(25103, $transaction->totalPayment());
        $this->assertSame('qris', $transaction->paymentMethod());
        $this->assertSame($paymentNumber, $transaction->paymentNumber());
        $this->assertSame('2026-07-04T16:30:00+00:00', $transaction->expiresAt()?->utc()->toAtomString());

        $sanitized = $transaction->sanitizedPayload();
        $this->assertArrayNotHasKey('payment_number', $sanitized['payment']);
        $this->assertArrayNotHasKey('api_key', $sanitized['payment']);
        $this->assertArrayNotHasKey('authorization', $sanitized['payment']['metadata']);
        $this->assertSame('keep', $sanitized['payment']['metadata']['safe_note']);
    }

    public function test_it_fails_closed_when_required_native_qris_fields_are_missing(): void
    {
        $payload = [
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'INV-UNIT-QRIS-2',
                'amount' => 25000,
                'payment_method' => 'qris',
            ],
        ];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Pakasir QRIS create response is missing payment_number.');

        PakasirQrisTransaction::fromPayload($payload);
    }

    public function test_it_rejects_provider_response_that_does_not_match_local_payment(): void
    {
        $payment = new Payment([
            'merchant_ref' => 'INV-UNIT-QRIS-3',
            'amount' => 25000,
        ]);

        $cases = [
            'project mismatch' => ['project' => 'wrong-project'],
            'order mismatch' => ['order_id' => 'INV-WRONG'],
            'amount mismatch' => ['amount' => 25001],
            'method mismatch' => ['payment_method' => 'hosted'],
        ];

        foreach ($cases as $case => $overrides) {
            $transaction = PakasirQrisTransaction::fromPayload([
                'payment' => array_merge([
                    'project' => 'warung-ai',
                    'order_id' => 'INV-UNIT-QRIS-3',
                    'amount' => 25000,
                    'payment_method' => 'qris',
                    'payment_number' => '00020101021226610016ID.CO.QRIS.UNIT-QRIS-3',
                ], $overrides),
            ]);

            try {
                $transaction->assertMatches($payment, 'warung-ai');
                $this->fail("Expected mismatch to be rejected for {$case}.");
            } catch (UnexpectedValueException $exception) {
                $this->assertStringContainsString('Pakasir QRIS create response', $exception->getMessage(), $case);
            }
        }
    }
}
