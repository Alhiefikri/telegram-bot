<?php

namespace Tests\Unit\Services\UsdtFunding;

use App\Services\UsdtFunding\CustomerUsdtFundingPolicyDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerUsdtFundingPolicyDefinitionTest extends TestCase
{
    #[DataProvider('ttlProvider')]
    public function test_ttl_is_bounded_to_the_technical_window(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, CustomerUsdtFundingPolicyDefinition::parseTtlSeconds($input));
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function ttlProvider(): array
    {
        return [
            'default seconds' => ['600', 600],
            'minimum' => [60, 60],
            'maximum' => ['3600', 3600],
            'below minimum' => ['59', null],
            'above maximum' => ['3601', null],
            'zero' => ['0', null],
            'float' => [600.5, null],
            'suffix' => ['600s', null],
            'empty' => ['', null],
            'negative' => ['-60', null],
        ];
    }

    #[DataProvider('feeProvider')]
    public function test_fee_is_an_exact_nonnegative_micro_usdt_amount(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, CustomerUsdtFundingPolicyDefinition::parseFeeMicro($input));
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function feeProvider(): array
    {
        return [
            'zero int' => [0, 0],
            'zero string' => ['0', 0],
            'zero decimals' => ['0.000000', 0],
            'zero padded' => ['00.000', 0],
            'one and a half' => ['1.5', 1_500_000],
            'smallest unit' => ['0.000001', 1],
            'trailing zeros' => ['2.500000', 2_500_000],
            'negative int' => [-1, null],
            'negative string' => ['-1', null],
            'excess precision' => ['1.1234567', null],
            'exponent' => ['1e3', null],
            'float' => [1.5, null],
            'comma separator' => ['1,5', null],
            'overflow' => ['99999999999999999999999999999999', null],
        ];
    }

    public function test_terms_body_is_bounded_and_control_characters_are_rejected(): void
    {
        $this->assertSame('Syarat top-up.', CustomerUsdtFundingPolicyDefinition::normalizeTermsBody('  Syarat top-up.  '));
        $this->assertStringContainsString("\n", (string) CustomerUsdtFundingPolicyDefinition::normalizeTermsBody("Baris satu\nBaris dua"));
        $this->assertNull(CustomerUsdtFundingPolicyDefinition::normalizeTermsBody('   '));
        $this->assertNull(CustomerUsdtFundingPolicyDefinition::normalizeTermsBody(str_repeat('x', 20_001)));
        $this->assertNull(CustomerUsdtFundingPolicyDefinition::normalizeTermsBody("Syarat\x00terlarang"));
        $this->assertNull(CustomerUsdtFundingPolicyDefinition::normalizeTermsBody(123));
    }

    public function test_definition_reports_every_invalid_field_and_parses_valid_input(): void
    {
        $errors = CustomerUsdtFundingPolicyDefinition::validationErrors('maybe', '59', '-1', '');
        $this->assertSame(['funding_enabled', 'confirmation_ttl_seconds', 'application_fee_micro', 'terms_body'], array_keys($errors));

        $definition = CustomerUsdtFundingPolicyDefinition::from('1', '600', '0', 'Syarat top-up.');
        $this->assertTrue($definition->fundingEnabled);
        $this->assertSame(600, $definition->confirmationTtlSeconds);
        $this->assertSame(0, $definition->applicationFeeMicro);
        $this->assertSame('Syarat top-up.', $definition->termsBody);
    }
}
