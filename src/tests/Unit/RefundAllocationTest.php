<?php

namespace Tests\Unit;

use App\Services\Warranty\RefundAllocation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundAllocationTest extends TestCase
{
    public static function units(): array
    {
        return [
            'retail not supplier cost' => [24000, 3, 1, 8000],
            'discounted tier' => [21000, 3, 2, 7000],
            'rounding first' => [10, 3, 1, 4],
            'rounding second' => [10, 3, 2, 3],
            'rounding third' => [10, 3, 3, 3],
            'usdt micros' => [2_400_000, 3, 3, 800000],
            'large integer no multiplication overflow' => [PHP_INT_MAX, 1, 1, PHP_INT_MAX],
        ];
    }

    #[DataProvider('units')]
    public function test_refund_uses_immutable_retail_total_and_assigns_rounding_once(int $total, int $quantity, int $number, int $expected): void
    {
        $this->assertSame($expected, RefundAllocation::unit($total, $quantity, $number));
    }

    public static function invalidUnits(): array
    {
        return [['negative total', -1, 3, 1], ['zero quantity', 8000, 0, 1], ['no unit', 8000, 2, 0], ['past quantity', 8000, 2, 3]];
    }

    #[DataProvider('invalidUnits')]
    public function test_invalid_unit_entitlements_are_rejected(string $name, int $total, int $quantity, int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        RefundAllocation::unit($total, $quantity, $number);
    }

    public function test_full_refund_after_a_partial_refund_only_returns_the_remainder(): void
    {
        $this->assertSame(16000, RefundAllocation::remaining(24000, 8000));
        $this->assertSame(0, RefundAllocation::remaining(24000, 24000));
        $this->expectException(InvalidArgumentException::class);
        RefundAllocation::remaining(24000, 24001);
    }
}
