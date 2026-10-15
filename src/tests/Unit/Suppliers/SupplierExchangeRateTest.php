<?php

namespace Tests\Unit\Suppliers;

use App\Services\Suppliers\SupplierExchangeRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierExchangeRateTest extends TestCase
{
    #[DataProvider('vndRates')]
    public function test_only_plausible_idr_values_for_one_vnd_are_accepted(string|int $rate, bool $expected): void
    {
        $this->assertSame($expected, SupplierExchangeRate::isPlausible('VND', $rate));
    }

    public static function vndRates(): array
    {
        return [
            'owner funding decision 18.000 / 26.000' => ['0.692308', true],
            'conservative owner estimate 18.000 / 25.500' => ['0.705882', true],
            'existing synthetic qualification rate' => ['0.650001', true],
            'lower band edge' => ['0.1', true],
            'upper band edge' => ['0.999999', true],
            'rupiah per USDT from the production incident' => ['18000', false],
            'one rupiah per dong' => ['1', false],
            'above the band' => ['1.000001', false],
            'form minimum placeholder' => ['0.000001', false],
            'order-of-magnitude typo' => ['0.05', false],
            'zero' => ['0', false],
            'not a decimal token' => ['Rp0,69', false],
            'empty' => ['', false],
        ];
    }

    public function test_a_currency_without_a_band_is_not_restricted(): void
    {
        foreach (['USD', 'USDT', 'IDR'] as $currency) {
            $this->assertNull(SupplierExchangeRate::band($currency));
            $this->assertNull(SupplierExchangeRate::reason($currency));
            $this->assertTrue(SupplierExchangeRate::isPlausible($currency, '18000'));
        }
        $this->assertNotNull(SupplierExchangeRate::band('VND'));
    }

    public function test_the_vnd_band_and_operator_reason_stay_explicit_without_echoing_a_submitted_value(): void
    {
        $this->assertSame(['min' => '0.1', 'max' => '0.999999'], SupplierExchangeRate::band('VND'));

        $reason = SupplierExchangeRate::reason('VND');
        $this->assertNotNull($reason);
        $this->assertStringContainsString('1 VND', $reason);
        $this->assertStringContainsString('per USDT', $reason);
        $this->assertStringContainsString('0.692308', $reason);
        $this->assertStringNotContainsString('18000', $reason);
        $this->assertNull(SupplierExchangeRate::reason('USDT'));

        $requirement = SupplierExchangeRate::requirement('Nastele', 'VND');
        $this->assertNotNull($requirement);
        $this->assertStringContainsString('Nastele', $requirement);
        $this->assertStringContainsString('VND', $requirement);
        $this->assertNull(SupplierExchangeRate::requirement('ProdSeller', 'USDT'));
    }
}
