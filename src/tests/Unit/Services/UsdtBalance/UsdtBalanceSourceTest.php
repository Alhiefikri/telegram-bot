<?php

namespace Tests\Unit\Services\UsdtBalance;

use App\Enums\UsdtBalanceEntryKind;
use App\Services\UsdtBalance\ApprovedUsdtReversal;
use App\Services\UsdtBalance\UsdtBalanceSource;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtPurchase;
use App\Services\UsdtBalance\UsdtRefund;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UsdtBalanceSourceTest extends TestCase
{
    private function units(int $micro): UsdtMicroAmount
    {
        return UsdtMicroAmount::fromUnits($micro);
    }

    public function test_each_variant_declares_its_closed_economic_identity(): void
    {
        $funding = new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-1', 'receipt-1', 'v1');
        $this->assertSame(UsdtBalanceEntryKind::FundingCredit, $funding->kind());
        $this->assertSame('customer_usdt_top_up', $funding->sourceType());
        $this->assertSame('topup-1', $funding->sourceId());
        $this->assertSame(2_500_000, $funding->amountMicro());
        $this->assertNull($funding->originalEntryId());
        $this->assertNull($funding->refundOrderId());

        $purchase = new UsdtPurchase(7, $this->units(2_500_000), 'order-1', 'payment-1');
        $this->assertSame(UsdtBalanceEntryKind::PurchaseDebit, $purchase->kind());
        $this->assertSame('order', $purchase->sourceType());
        $this->assertSame('order-1', $purchase->sourceId());

        $refund = new UsdtRefund(7, $this->units(1_000_000), 'refund-1', 'order-1', 42, 'payment-1');
        $this->assertSame(UsdtBalanceEntryKind::RefundCredit, $refund->kind());
        $this->assertSame('order_refund', $refund->sourceType());
        $this->assertSame('refund-1', $refund->sourceId());
        $this->assertSame(42, $refund->originalEntryId());
        $this->assertSame('order-1', $refund->refundOrderId());

        $reversal = new ApprovedUsdtReversal(7, $this->units(2_500_000), 42, 'approval-1');
        $this->assertSame(UsdtBalanceEntryKind::ReversalDebit, $reversal->kind());
        $this->assertSame('approved_reversal', $reversal->sourceType());
        $this->assertSame('42', $reversal->sourceId());
        $this->assertSame(42, $reversal->originalEntryId());
    }

    public function test_sources_bind_the_exact_parsed_amount_instead_of_a_raw_integer(): void
    {
        $funding = new VerifiedUsdtFunding(7, UsdtMicroAmount::fromDecimalString('2.5'), 'topup-1', 'receipt-1', 'v1');

        $this->assertSame(2_500_000, $funding->amountMicro());
        $this->assertSame('2.500000', $funding->amount->decimal());

        $purchase = new UsdtPurchase(7, UsdtMicroAmount::fromRawTokenUnits('2500000000000000000', 18), 'order-1', 'payment-1');
        $this->assertSame(2_500_000, $purchase->amountMicro());
    }

    public function test_fingerprint_binds_variant_identity_customer_amount_and_receipt_payload(): void
    {
        $source = new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-1', 'receipt-1', 'v1');

        $this->assertSame($source->fingerprint(), (new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-1', 'receipt-1', 'v1'))->fingerprint());
        $this->assertSame(64, strlen($source->fingerprint()));

        $changed = [
            new VerifiedUsdtFunding(8, $this->units(2_500_000), 'topup-1', 'receipt-1', 'v1'),
            new VerifiedUsdtFunding(7, $this->units(2_500_001), 'topup-1', 'receipt-1', 'v1'),
            new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-2', 'receipt-1', 'v1'),
            new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-1', 'receipt-2', 'v1'),
            new VerifiedUsdtFunding(7, $this->units(2_500_000), 'topup-1', 'receipt-1', 'v2'),
            new UsdtRefund(7, $this->units(2_500_000), 'topup-1', 'order-1', 1, 'payment-1'),
        ];

        foreach ($changed as $other) {
            $this->assertNotSame($source->fingerprint(), $other->fingerprint());
        }
    }

    public function test_sources_reject_foreign_customers_and_unbounded_identities(): void
    {
        $invalid = [
            fn () => new VerifiedUsdtFunding(0, $this->units(1_000_000), 'topup-1', 'receipt-1', 'v1'),
            fn () => new VerifiedUsdtFunding(7, $this->units(1_000_000), '', 'receipt-1', 'v1'),
            fn () => new VerifiedUsdtFunding(7, $this->units(1_000_000), str_repeat('a', 65), 'receipt-1', 'v1'),
            fn () => new UsdtPurchase(7, $this->units(1_000_000), 'order-1', ''),
            fn () => new UsdtRefund(7, $this->units(1_000_000), 'refund-1', 'order-1', 0, 'payment-1'),
            fn () => new ApprovedUsdtReversal(7, $this->units(1_000_000), 0, 'approval-1'),
            fn () => new ApprovedUsdtReversal(7, $this->units(1_000_000), 42, ''),
        ];

        foreach ($invalid as $factory) {
            try {
                $factory();
                $this->fail('Expected the source to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_source_exposes_a_stable_string_source_type_and_id(): void
    {
        $sources = [
            new VerifiedUsdtFunding(7, $this->units(1_000_000), 'topup-1', 'receipt-1', 'v1'),
            new UsdtPurchase(7, $this->units(1_000_000), 'order-1', 'payment-1'),
            new UsdtRefund(7, $this->units(1_000_000), 'refund-1', 'order-1', 42, 'payment-1'),
            new ApprovedUsdtReversal(7, $this->units(1_000_000), 42, 'approval-1'),
        ];

        foreach ($sources as $source) {
            $this->assertInstanceOf(UsdtBalanceSource::class, $source);
            $this->assertNotSame('', $source->sourceType());
            $this->assertNotSame('', $source->sourceId());
            $this->assertLessThanOrEqual(64, mb_strlen($source->sourceType()));
            $this->assertLessThanOrEqual(64, mb_strlen($source->sourceId()));
        }
    }
}
