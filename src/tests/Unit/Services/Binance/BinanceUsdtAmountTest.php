<?php

namespace Tests\Unit\Services\Binance;

use App\Services\Binance\BinanceUsdtAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BinanceUsdtAmountTest extends TestCase
{
    #[DataProvider('exactAmounts')]
    public function test_history_amount_preserves_exact_micro_units(string $amount, int $expected): void
    {
        $this->assertSame($expected, BinanceUsdtAmount::toMicro($amount));
    }

    public static function exactAmounts(): iterable
    {
        yield 'smallest credit' => ['0.000001', 1];
        yield 'smallest debit' => ['-0.000001', -1];
        yield 'decimal credit' => ['1.234567', 1234567];
        yield 'decimal debit' => ['-1.234567', -1234567];
        yield 'provider zero' => ['0', 0];
        yield 'trailing zero precision' => ['1.2345670000', 1234567];
        yield 'maximum exact credit' => ['9223372036854.775807', PHP_INT_MAX];
        yield 'maximum exact debit' => ['-9223372036854.775807', -PHP_INT_MAX];
    }

    #[DataProvider('unrepresentableAmounts')]
    public function test_invalid_or_unrepresentable_history_amount_is_rejected(string $amount): void
    {
        $this->assertNull(BinanceUsdtAmount::toMicro($amount));
    }

    public static function unrepresentableAmounts(): iterable
    {
        foreach (['0.0000001', '-0.0000001', '1.00000001', '9223372036854.775808',
            '-9223372036854.775808', '9223372036855', str_repeat('9', 40),
            '1e6', '+1', '.5', '1.', '1,5', '1 000', 'NaN', 'Infinity', ''] as $amount) {
            yield $amount === '' ? 'empty' : $amount => [$amount];
        }
    }
}
