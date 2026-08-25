<?php

namespace Tests\Unit\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\ManualPaymentSetting;
use App\Services\Bsc\BscPaymentReceiverSnapshot;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPlan;
use App\Services\Checkout\CheckoutPaymentPreflight;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationContext;
use App\Services\Checkout\CheckoutPaymentPreparationIdentity;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\InMemoryCheckoutPaymentPreparation;
use App\Services\Checkout\PaymentPlan\BscCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\ProviderBoundCheckoutPaymentIntent;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutPaymentPreparationTest extends TestCase
{
    public function test_preflight_is_immutable_and_rejects_a_stale_setting_fingerprint(): void
    {
        $setting = new ManualPaymentSetting([
            'payment_provider_version' => 4,
            'payment_mode' => 'manual',
        ]);
        $setting->id = 9;
        $context = new CheckoutPaymentPreflightContext($setting, PaymentProvider::MANUAL, 'QR_MANUAL');
        $preflight = CheckoutPaymentPreflight::none($context);

        $this->assertTrue($preflight->matches($context));
        $this->assertSame(
            CheckoutPaymentPreparationIdentity::key('manual', 'QR_MANUAL'),
            $preflight->identity(),
        );

        $staleContext = new CheckoutPaymentPreflightContext(
            $setting,
            PaymentProvider::MANUAL,
            'QR_MANUAL',
        );
        $setting->payment_provider_version = 5;

        $this->assertFalse($preflight->matches($staleContext));
        $this->expectException(InvalidCheckout::class);
        $preflight->assertMatches($staleContext);
    }

    public function test_legacy_manual_intent_can_bridge_to_a_typed_plan(): void
    {
        $deadline = CarbonImmutable::parse('2030-02-03 04:05:06');
        $intent = CheckoutPaymentIntents::manualQris($deadline);
        $context = new CheckoutPaymentPreparationContext(
            cart: new Cart,
            items: new Collection([(object) ['product_id' => 1, 'quantity' => 2]]),
            products: new Collection([1 => (object) ['price_amount' => 15000]]),
            lockedSetting: null,
            preflight: CheckoutPaymentPreflight::none(
                new CheckoutPaymentPreflightContext(null, 'manual', 'QR_MANUAL'),
            ),
        );

        $plan = $intent->prepare($context);

        $this->assertInstanceOf(CheckoutPaymentPlan::class, $plan);
        $this->assertInstanceOf(ManualQrisCheckoutPaymentSnapshot::class, $plan->rail());
        $this->assertSame(30000, $plan->amount);
        $this->assertSame($deadline->timestamp, $plan->expiresAt()->timestamp);
    }

    public function test_provider_bound_intent_forwards_the_preparation_contract(): void
    {
        $intent = CheckoutPaymentIntents::manualQris(CarbonImmutable::parse('2030-03-04 05:06:07'));
        $bound = new ProviderBoundCheckoutPaymentIntent($intent, 'manual', 2, 5);
        $context = new CheckoutPaymentPreflightContext(null, 'manual', 'QR_MANUAL');

        $this->assertTrue($bound->preflight($context)->matches($context));
    }

    public function test_resolver_can_select_a_test_adapter_by_normalized_identity(): void
    {
        $deadline = CarbonImmutable::parse('2030-04-05 06:07:08');
        $plan = new CheckoutPaymentPlan(
            orderCurrency: 'IDR',
            orderExpiresAt: $deadline,
            provider: 'manual',
            method: 'QR_MANUAL',
            paymentCurrency: 'IDR',
            amount: 1000,
            paymentExpiresAt: $deadline,
            railSnapshot: new ManualQrisCheckoutPaymentSnapshot('manual', 'QR_MANUAL', 'IDR', 1000, $deadline),
            postCreationBehavior: CheckoutPostCreationBehavior::ManualQrisInstructions,
        );
        $adapter = new InMemoryCheckoutPaymentPreparation($plan);
        $resolver = new CheckoutPaymentPreparationResolver([
            CheckoutPaymentPreparationIdentity::key('MANUAL', 'qr_manual') => $adapter,
        ]);

        $this->assertSame($adapter, $resolver->for(CheckoutPaymentIntents::manualQris()));
    }

    public function test_bsc_snapshot_requires_a_complete_receiver_and_expected_units(): void
    {
        $deadline = CarbonImmutable::parse('2030-05-06 07:08:09');
        $receiver = new BscPaymentReceiverSnapshot(
            receiverId: 7,
            address: '0x1111111111111111111111111111111111111111',
            chainId: 56,
            tokenContract: '0x55d398326f99059ff775485246999027b3197955',
            tokenDecimals: 18,
        );
        $snapshot = new BscCheckoutPaymentSnapshot(
            provider: 'manual',
            method: 'USDT_BEP20',
            paymentCurrency: 'USDT',
            amount: 2500000,
            expiresAt: $deadline,
            receiver: $receiver,
            baseAmountMicro: 2500000,
            payableAmountMicro: 2500000,
            expectedTokenUnits: '2500000000000000000',
            verificationStatus: 'awaiting_transfer',
        );

        $this->assertSame(7, $snapshot->receiverId);
        $this->assertSame('2500000000000000000', $snapshot->expectedTokenUnits);
    }

    public function test_bsc_snapshot_rejects_incomplete_expected_units_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BscCheckoutPaymentSnapshot(
            provider: 'manual',
            method: 'USDT_BEP20',
            paymentCurrency: 'USDT',
            amount: 2500000,
            expiresAt: CarbonImmutable::parse('2030-05-06 07:08:09'),
            receiver: new BscPaymentReceiverSnapshot(
                receiverId: 7,
                address: '0x1111111111111111111111111111111111111111',
                chainId: 56,
                tokenContract: '0x55d398326f99059ff775485246999027b3197955',
                tokenDecimals: 18,
            ),
            baseAmountMicro: 2500000,
            payableAmountMicro: 2500000,
            expectedTokenUnits: '',
            verificationStatus: 'awaiting_transfer',
        );
    }
}
