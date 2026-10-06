<?php

namespace Tests\Unit\Services\Bsc;

use App\Exceptions\InvalidCheckout;
use App\Services\Bsc\BscTokenAmount;
use PHPUnit\Framework\TestCase;

class BscTokenAmountTest extends TestCase
{
    public function test_it_converts_micro_usdt_into_exact_raw_units(): void
    {
        $amount = new BscTokenAmount;

        $this->assertSame('0', $amount->expectedTokenUnits(0, 18));
        $this->assertSame('1000000000000', $amount->expectedTokenUnits(1, 18));
        $this->assertSame('2500000000000000000', $amount->expectedTokenUnits(2_500_000, 18));
        $this->assertSame('1', $amount->expectedTokenUnits(1, 6));
        $this->assertSame('1000000', $amount->expectedTokenUnits(1_000_000, 6));
    }

    public function test_it_rejects_negative_amounts_and_decimals_that_cannot_represent_micro_usdt(): void
    {
        $amount = new BscTokenAmount;

        foreach ([[-1, 18], [0, 5], [1_000_000, 5]] as [$micro, $decimals]) {
            try {
                $amount->expectedTokenUnits($micro, $decimals);
                $this->fail('Expected an InvalidCheckout for '.$micro.' micro at '.$decimals.' decimals.');
            } catch (InvalidCheckout $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_large_micro_amounts_stay_exact_without_float_rounding(): void
    {
        $units = (new BscTokenAmount)->expectedTokenUnits(PHP_INT_MAX, 18);

        $this->assertSame((string) PHP_INT_MAX.'000000000000', $units);
        $this->assertSame(strlen((string) PHP_INT_MAX) + 12, strlen($units));
    }
}
