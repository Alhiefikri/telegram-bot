<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TelegramStars;

use App\Services\TelegramStars\StarsPricingPolicy;
use App\Services\TelegramStars\StarsQuoteCalculator;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StarsQuoteCalculatorTest extends TestCase
{
    #[DataProvider('validQuotes')]
    public function test_quotes_exactly(int $idr, int $rate, int $reward, int $reserve, int $expected): void
    {
        $policy = new StarsPricingPolicy($rate, $reward, $reserve, false, 0);

        self::assertSame($expected, (new StarsQuoteCalculator)->quote($idr, $policy));
    }

    public static function validQuotes(): array
    {
        return [
            '18300 example' => [10_000, 18_300, 13_000, 1500, 50],
            '18500 example' => [10_000, 18_500, 13_000, 1500, 49],
            'three units at a 100 IDR retail tier; ceil only on total' => [300, 18_300, 13_000, 1500, 2],
            'exact upper boundary without rounding up' => [10_000, 1_000_000, 1, 0, StarsQuoteCalculator::MAX_INVOICE_XTR],
            'smallest positive result' => [1, 1_000_000, 1, 0, 1],
            'largest reserve' => [1, 1_000_000, 10_000, 9999, 1],
            'intermediate products exceed PHP_INT_MAX but valid quote' => [PHP_INT_MAX, PHP_INT_MAX, 10_000, 0, 100],
            'no float rounding just above an integer' => [PHP_INT_MAX, PHP_INT_MAX - 1, 1_000_000, 0, 2],
        ];
    }

    #[DataProvider('invalidTotals')]
    public function test_rejects_invalid_totals_instead_of_clamping(int $idr): void
    {
        $this->expectException(DomainException::class);

        (new StarsQuoteCalculator)->quote($idr, new StarsPricingPolicy(1_000_000, 1, 0, true, 1));
    }

    public static function invalidTotals(): array
    {
        return [[0], [-1], [StarsQuoteCalculator::MAX_INVOICE_XTR + 1], [PHP_INT_MAX]];
    }

    public function test_unconfigured_policy_cannot_quote(): void
    {
        $this->expectException(DomainException::class);

        (new StarsQuoteCalculator)->quote(10_000, new StarsPricingPolicy(null, 13_000, null, false, 0));
    }
}
