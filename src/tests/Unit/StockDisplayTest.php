<?php

namespace Tests\Unit;

use App\Support\StockDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockDisplayTest extends TestCase
{
    #[DataProvider('quantities')]
    public function test_display_keeps_normal_quantities_and_legacy_unlimited_ceiling(int $quantity, string $label): void
    {
        self::assertSame($label, StockDisplay::label($quantity));
    }

    public static function quantities(): array
    {
        return [[0, '0'], [12, '12'], [2_146_999_999, '2146999999'], [2_147_000_000, '∞'], [2_147_483_646, '∞']];
    }
}
