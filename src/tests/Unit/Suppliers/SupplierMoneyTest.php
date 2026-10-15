<?php

namespace Tests\Unit\Suppliers;

use App\Services\Suppliers\SupplierMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupplierMoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_decimal_amounts_are_exact(int|float|string $amount, int $expected): void
    {
        $this->assertSame($expected, SupplierMoney::micros($amount));
        $this->assertSame($expected, SupplierMoney::micros(SupplierMoney::decimal($expected)));
    }

    public static function amounts(): array
    {
        return [
            [0, 0], ['0.000001', 1], ['4.99', 4_990_000], [4.99, 4_990_000],
            ['9007199254.740991', 9_007_199_254_740_991],
            ['9223372036854.775807', PHP_INT_MAX],
        ];
    }

    #[DataProvider('balances')]
    public function test_signed_wallet_display_is_exact(int $balance, string $expected): void
    {
        $this->assertSame($expected, SupplierMoney::balanceDecimal($balance));
    }

    public static function balances(): array
    {
        return [[0, '0.000000'], [-1, '-0.000001'], [-3_800_000_000, '-3800.000000'],
            [PHP_INT_MIN, '-9223372036854.775808'], [PHP_INT_MAX, '9223372036854.775807']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_precision_and_overflow_fail_closed(int|float|string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupplierMoney::micros($amount);
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn ($amount) => [$amount], [
            '-1', '+1', '1e3', '1E-6', ' 1', '1 ', '.5', '1.', '01', '0.0000001',
            '9223372036854.775808', '999999999999999999999999', INF, NAN,
        ]);
    }

    public function test_conversion_rounds_up_without_intermediate_overflow(): void
    {
        $this->assertSame(1, SupplierMoney::costIdr(1, 16_000));
        $this->assertSame(79_840, SupplierMoney::costIdr(4_990_000, 16_000));
        $this->assertSame(9_223_372_036_855, SupplierMoney::costIdr(PHP_INT_MAX, 1));
        $this->assertSame(PHP_INT_MAX - intdiv(PHP_INT_MAX, 1_000_000), SupplierMoney::costIdr(999_999, PHP_INT_MAX));
    }

    public function test_fractional_exchange_rate_rounds_once_without_losing_sub_rupiah_costs(): void
    {
        $this->assertSame(19500, SupplierMoney::costIdr(30_000_000_000, '0.650000'));
        $this->assertSame(19501, SupplierMoney::costIdr(30_000_000_000, '0.650001'));
        $this->assertSame(1, SupplierMoney::costIdr(1, '0.000001'));
        $this->assertSame(0, SupplierMoney::costIdr(0, '0.650001'));
        $this->assertSame(79_840, SupplierMoney::costIdr(4_990_000, '16000.000000'));
        $this->expectException(InvalidArgumentException::class);
        SupplierMoney::costIdr(PHP_INT_MAX, '1000000.000001');
    }

    #[DataProvider('overflowOperations')]
    public function test_checked_arithmetic_does_not_wrap(string $operation, int $left, int $right): void
    {
        $this->expectException(InvalidArgumentException::class);
        SupplierMoney::$operation($left, $right);
    }

    public static function overflowOperations(): array
    {
        return [
            ['checkedAdd', PHP_INT_MAX, 1], ['checkedMultiply', PHP_INT_MAX, 2],
            ['checkedAdd', -1, 2], ['checkedMultiply', 1, -1], ['costIdr', PHP_INT_MAX, 1_000_001],
        ];
    }
}
