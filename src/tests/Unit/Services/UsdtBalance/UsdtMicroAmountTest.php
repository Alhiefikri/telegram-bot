<?php

namespace Tests\Unit\Services\UsdtBalance;

use App\Services\UsdtBalance\UsdtMicroAmount;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\RoundingNecessaryException;
use Composer\InstalledVersions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UsdtMicroAmountTest extends TestCase
{
    public function test_decimal_strings_are_parsed_into_exact_positive_micro_units(): void
    {
        $this->assertSame(1, UsdtMicroAmount::fromDecimalString('0.000001')->units());
        $this->assertSame(502_814, UsdtMicroAmount::fromDecimalString('0.502814')->units());
        $this->assertSame(2_500_000, UsdtMicroAmount::fromDecimalString('2.5')->units());
        $this->assertSame(5_000_000, UsdtMicroAmount::fromDecimalString('5')->units());
        $this->assertSame(1_200_000, UsdtMicroAmount::fromDecimalString('1.2000000')->units());
        $this->assertSame(PHP_INT_MAX, UsdtMicroAmount::fromDecimalString('9223372036854.775807')->units());
    }

    public function test_float_exponent_negative_zero_excess_precision_and_overflow_are_rejected(): void
    {
        foreach ([
            '0',
            '-1',
            '-0.000001',
            '+1',
            '1e3',
            '1E6',
            '.5',
            '1.',
            '01',
            '0001.5',
            '1.2.3',
            'abc',
            '',
            ' 1',
            '1 ',
            '1,5',
            "1\n",
            '1.0000001',
            '0.0000001',
            '9223372036854.775808',
            '999999999999999999999999',
            str_repeat('9', 33),
        ] as $invalid) {
            try {
                UsdtMicroAmount::fromDecimalString($invalid);
                $this->fail("Expected [{$invalid}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_units_canonicity_and_serializers_are_exact(): void
    {
        $amount = UsdtMicroAmount::fromUnits(2_500_000);

        $this->assertSame('2500000', $amount->integerUnits());
        $this->assertSame('2.500000', $amount->decimal());
        $this->assertSame('USDT', $amount->currency());
        $this->assertTrue($amount->equals(UsdtMicroAmount::fromDecimalString('2.5')));
        $this->assertSame('0.000001', UsdtMicroAmount::fromUnits(1)->decimal());
        $this->assertSame('9223372036854.775807', UsdtMicroAmount::fromUnits(PHP_INT_MAX)->decimal());

        foreach ([0, -1] as $invalidUnits) {
            try {
                UsdtMicroAmount::fromUnits($invalidUnits);
                $this->fail("Expected [{$invalidUnits}] units to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_raw_chain_units_convert_exactly_and_reject_any_nonzero_remainder(): void
    {
        $this->assertSame(1, UsdtMicroAmount::fromRawTokenUnits('1000000000000', 18)->units());
        $this->assertSame(2_500_000, UsdtMicroAmount::fromRawTokenUnits('2500000000000000000', 18)->units());
        $this->assertSame(1, UsdtMicroAmount::fromRawTokenUnits('1', 6)->units());
        $this->assertSame(10_000, UsdtMicroAmount::fromRawTokenUnits('1', 2)->units());
        $this->assertSame(1_000_000, UsdtMicroAmount::fromRawTokenUnits('1', 0)->units());

        foreach ([
            ['1', 18],
            ['1000000000000000001', 18],
            ['0', 18],
            ['01', 18],
            ['-1', 18],
            ['1e3', 18],
            ['', 18],
            ['999999999999999999999999999999999999999999999999999999999999999999999999999999999', 18],
            ['1', -1],
            ['1', 37],
        ] as [$raw, $decimals]) {
            try {
                UsdtMicroAmount::fromRawTokenUnits($raw, $decimals);
                $this->fail("Expected raw [{$raw}] with decimals [{$decimals}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(PHP_INT_MAX, UsdtMicroAmount::fromRawTokenUnits((string) PHP_INT_MAX.'000000000000', 18)->units());

        $overflowRaw = '9223372036854775808'.'000000000000';
        try {
            UsdtMicroAmount::fromRawTokenUnits($overflowRaw, 18);
            $this->fail('Expected an overflowing raw amount to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Pins the installed brick/math ^0.18 API semantics the serializer relies
     * on: exact scale checks, exact integer division and overflow signalling.
     */
    public function test_installed_brick_math_api_contract_used_by_the_serializer(): void
    {
        $this->assertSame('0.18', implode('.', array_slice(explode('.', InstalledVersions::getPrettyVersion('brick/math')), 0, 2)));

        $this->assertSame(1_200_000, BigDecimal::of('1.2000000')->toScale(6)->getUnscaledValue()->toInt());
        $this->assertSame('0.000001', BigDecimal::ofUnscaledValue(1, 6)->toString());
        $this->assertSame('1000000000000', BigInteger::ten()->power(12)->toString());
        $this->assertSame(2, BigInteger::of('2000000000000000000')->dividedBy(BigInteger::ten()->power(18))->toInt());

        try {
            BigDecimal::of('1.0000001')->toScale(6);
            $this->fail('Expected RoundingNecessaryException for a nonzero remainder.');
        } catch (RoundingNecessaryException) {
            $this->addToAssertionCount(1);
        }

        try {
            BigInteger::of(str_repeat('9', 25))->toInt();
            $this->fail('Expected IntegerOverflowException beyond the native integer range.');
        } catch (IntegerOverflowException) {
            $this->addToAssertionCount(1);
        }
    }
}
