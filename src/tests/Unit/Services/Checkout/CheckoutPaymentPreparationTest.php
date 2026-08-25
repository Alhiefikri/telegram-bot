<?php

namespace Tests\Unit\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Services\Bsc\BscPaymentReceiverSnapshot;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPaymentPlan;
use App\Services\Checkout\CheckoutPaymentPreflight;
use App\Services\Checkout\CheckoutPaymentPreflightContext;
use App\Services\Checkout\CheckoutPaymentPreparationIdentity;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\Checkout\InMemoryCheckoutPaymentPreparation;
use App\Services\Checkout\PaymentPlan\BscCheckoutPaymentSnapshot;
use App\Services\Checkout\PaymentPlan\ManualQrisCheckoutPaymentSnapshot;
use App\Services\Checkout\ProviderBoundCheckoutPaymentIntent;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
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

    public function test_intents_no_longer_expose_legacy_payment_assembly_methods(): void
    {
        $intents = [
            CheckoutPaymentIntents::manualQris(),
            CheckoutPaymentIntents::klikQris(),
            CheckoutPaymentIntents::binancePayId(),
            CheckoutPaymentIntents::usdtBep20(),
        ];

        foreach ($intents as $intent) {
            foreach (['paymentAmount', 'paymentCurrency', 'orderCurrency', 'expiresAt', 'assertReady'] as $method) {
                $this->assertFalse(is_callable([$intent, $method]), $intent::class.' exposes '.$method.'.');
            }
        }
    }

    public function test_resolver_rejects_unregistered_intent_without_a_legacy_bridge(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CheckoutPaymentPreparationResolver)->for(CheckoutPaymentIntents::manualQris());
    }

    public function test_provider_bound_intent_preserves_selection_and_provider_setting_snapshot(): void
    {
        $bound = new ProviderBoundCheckoutPaymentIntent(
            CheckoutPaymentIntents::manualQris(),
            'manual',
            2,
            5,
        );

        $this->assertSame('manual', $bound->provider());
        $this->assertSame('QR_MANUAL', $bound->method());
        $this->assertSame(CheckoutPostCreationBehavior::ManualQrisInstructions, $bound->postCreationBehavior());
        $this->assertSame('manual', $bound->configuredProvider);
        $this->assertSame(2, $bound->providerVersion);
        $this->assertSame(5, $bound->settingId);
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
