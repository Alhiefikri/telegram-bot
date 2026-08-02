<?php

namespace Tests\Unit\Services\Pakasir;

use App\Services\Pakasir\QrisEmvPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class QrisEmvPayloadTest extends TestCase
{
    private const VALID_20_450 = '0002010102125303360540820450.005802ID63045B6D';

    public function test_it_reads_a_valid_dynamic_idr_qris_amount(): void
    {
        $qris = QrisEmvPayload::parse(self::VALID_20_450);

        $this->assertSame('360', $qris->currency());
        $this->assertSame(20_450, $qris->amountRupiah());
        $qris->assertMatchesIdrAmount(20_450);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_malformed_or_mismatched_qris_payloads(
        string $payload,
        int $expectedAmount,
        string $message,
    ): void {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        QrisEmvPayload::parse($payload)->assertMatchesIdrAmount($expectedAmount);
    }

    public static function invalidPayloads(): array
    {
        return [
            'non-IDR currency with valid CRC' => ['0002010102125303840540820450.005802ID6304F655', 20_450, 'QRIS currency must be IDR.'],
            'wrong amount with valid CRC' => ['0002010102125303360540820451.005802ID6304200C', 20_450, 'QRIS encoded amount mismatch.'],
            'corrupted CRC' => ['0002010102125303360540820450.005802ID63045B6E', 20_450, 'QRIS CRC mismatch.'],
            'truncated TLV value' => ['0002010102125303360540920450.005802ID63048572', 20_450, 'QRIS payload has an invalid TLV structure.'],
            'missing amount tag' => ['00020101021253033605802ID63044543', 20_450, 'QRIS amount tag is required.'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_amount_formats_that_cannot_be_exact_integer_rupiah(string $payload): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('QRIS amount must be an exact rupiah value.');

        QrisEmvPayload::parse($payload);
    }

    public static function invalidAmounts(): array
    {
        return [
            ['0002010102125303360540420.55802ID63045098'],
            ['0002010102125303360540620.0015802ID6304D34D'],
            ['000201010212530336054032e45802ID6304B000'],
            ['00020101021253033605402-15802ID6304ACFE'],
            ['000201010212530336054005802ID6304D2C1'],
        ];
    }
}
