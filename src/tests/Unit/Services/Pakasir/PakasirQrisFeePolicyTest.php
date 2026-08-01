<?php

namespace Tests\Unit\Services\Pakasir;

use App\Services\Pakasir\PakasirQrisFeePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class PakasirQrisFeePolicyTest extends TestCase
{
    public function test_official_july_2026_customer_fee_schedule_and_threshold(): void
    {
        $policy = $this->policy('customer');

        $this->assertSame([450], $policy->allowedFees(20_000));
        $this->assertSame([1_045], $policy->allowedFees(105_000));
        $this->assertSame([1_050, 1_051], $policy->allowedFees(105_001));
        $this->assertSame(20_450, $policy->buyerTotal(20_000, 450));
        $policy->assertValid(20_000, 450, 20_450);
    }

    public function test_merchant_fee_is_not_added_to_the_buyer_total(): void
    {
        $policy = $this->policy('merchant');

        $this->assertSame(20_000, $policy->buyerTotal(20_000, 450));
        $policy->assertValid(20_000, 450, 20_000);
    }

    #[DataProvider('invalidFinancials')]
    public function test_inconsistent_or_unreasonable_provider_financials_are_rejected(
        string $payer,
        int $amount,
        int $fee,
        int $total,
        string $message,
    ): void {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $this->policy($payer)->assertValid($amount, $fee, $total);
    }

    public static function invalidFinancials(): array
    {
        return [
            'reported production regression' => ['customer', 20_000, 2_660, 268_660, 'Pakasir QRIS fee mismatch.'],
            'correct fee with wrong customer total' => ['customer', 20_000, 450, 268_660, 'Pakasir QRIS total mismatch.'],
            'merchant fee added to buyer total' => ['merchant', 20_000, 450, 20_450, 'Pakasir QRIS total mismatch.'],
            'negative amount' => ['customer', -1, 310, 309, 'Pakasir QRIS amount must be positive.'],
            'negative fee' => ['customer', 20_000, -1, 19_999, 'Pakasir QRIS fee mismatch.'],
        ];
    }

    public function test_invalid_policy_configuration_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('PAKASIR_QRIS_FEE_PAYER must be customer or merchant.');

        new PakasirQrisFeePolicy('buyer', 105_000, 70, 310, 100, 0);
    }

    private function policy(string $payer): PakasirQrisFeePolicy
    {
        return new PakasirQrisFeePolicy($payer, 105_000, 70, 310, 100, 0);
    }
}
