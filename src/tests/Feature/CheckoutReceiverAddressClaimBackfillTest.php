<?php

namespace Tests\Feature;

use App\Enums\UsdtReceiverChannel;
use App\Models\BscPaymentReceiver;
use App\Models\UsdtReceiverAddressClaim;
use App\Services\UsdtReceivers\CheckoutReceiverAddressClaimBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class CheckoutReceiverAddressClaimBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    public function test_backfill_links_every_legacy_checkout_receiver_to_a_permanent_checkout_claim(): void
    {
        $legacyId = $this->insertLegacyReceiver(self::ADDRESS);

        $this->assertSame(1, (new CheckoutReceiverAddressClaimBackfill)->run());

        $claim = UsdtReceiverAddressClaim::query()->sole();
        $this->assertSame(UsdtReceiverChannel::Checkout, $claim->channel);
        $this->assertSame(56, (int) $claim->chain_id);
        $this->assertSame(self::ADDRESS, $claim->address_normalized);
        $this->assertSame(
            (int) $claim->getKey(),
            (int) BscPaymentReceiver::query()->findOrFail($legacyId)->address_claim_id,
        );

        // Re-running is idempotent: no second claim, no relink, no exception.
        $this->assertSame(0, (new CheckoutReceiverAddressClaimBackfill)->run());
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_backfill_refuses_an_address_already_owned_by_the_customer_deposit_channel(): void
    {
        $claim = UsdtReceiverAddressClaim::factory()
            ->channel(UsdtReceiverChannel::CustomerDeposit)
            ->create(['address_normalized' => self::ADDRESS]);

        $legacyId = $this->insertLegacyReceiver(self::ADDRESS);

        try {
            (new CheckoutReceiverAddressClaimBackfill)->run();
            $this->fail('The backfill must refuse a cross-channel ownership conflict.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership conflict', $exception->getMessage());
        }

        $this->assertNull(BscPaymentReceiver::query()->findOrFail($legacyId)->address_claim_id);
        $this->assertSame(
            UsdtReceiverChannel::CustomerDeposit,
            UsdtReceiverAddressClaim::query()->whereKey($claim->getKey())->sole()->channel,
        );
    }

    private function insertLegacyReceiver(string $address): int
    {
        return (int) DB::table('bsc_payment_receivers')->insertGetId([
            'label' => 'Legacy checkout wallet',
            'address' => $address,
            'address_normalized' => $address,
            'chain_id' => 56,
            'token_contract' => '0x55d398326f99059ff775485246999027b3197955',
            'token_decimals' => 18,
            'is_active' => false,
            'verification_status' => 'pending_verification',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');
    }
}
