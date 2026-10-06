<?php

namespace Tests\Feature;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtReceiverChannel;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtDepositReceiverOwnershipChallenge;
use App\Models\UsdtReceiverAddressClaim;
use App\Models\User;
use App\Services\Bsc\BscWalletOwnershipVerifierInterface;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBscWalletOwnershipVerifier;
use Tests\TestCase;

final class UsdtDepositReceiverCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_register_command_binds_a_customer_selected_by_exact_telegram_id(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create(['telegram_id' => 987654321]);

        $this->artisan('usdt-receivers:register', [
            '--telegram-id' => '987654321',
            '--address' => self::ADDRESS,
            '--actor' => (string) $owner->getKey(),
        ])
            ->expectsOutputToContain('USDT Deposit Receiver Ownership Verification')
            ->assertSuccessful();

        $receiver = CustomerUsdtDepositReceiver::query()->sole();
        $this->assertSame((int) $customer->getKey(), (int) $receiver->customer_id);
        $this->assertSame(BscReceiverVerificationStatus::PendingVerification, $receiver->verification_status);
        $this->assertSame(UsdtReceiverChannel::CustomerDeposit, UsdtReceiverAddressClaim::query()->sole()->channel);
    }

    public function test_register_command_records_an_explicit_low_risk_only_after_manual_acknowledgement(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();

        $this->artisan('usdt-receivers:register', [
            '--customer-id' => (string) $customer->getKey(),
            '--address' => self::ADDRESS,
            '--actor' => (string) $owner->getKey(),
            '--manual-verified' => true,
            '--json' => true,
        ])->assertSuccessful();

        $receiver = CustomerUsdtDepositReceiver::query()->sole();
        $this->assertSame(BscReceiverVerificationStatus::ManualVerified, $receiver->verification_status);
        $this->assertTrue($receiver->isReadyForAssignment());
        $this->assertSame(
            (int) $receiver->getKey(),
            app(UsdtDepositReceiverRegistry::class)->snapshotFor($customer)->receiverId,
        );
    }

    public function test_register_command_never_creates_an_unknown_customer(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->artisan('usdt-receivers:register', [
            '--telegram-id' => '111222333',
            '--address' => self::ADDRESS,
            '--actor' => (string) $owner->getKey(),
        ])->assertFailed();

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(0, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_register_command_rejects_a_non_owner_actor_without_side_effects(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();

        $this->artisan('usdt-receivers:register', [
            '--customer-id' => (string) $customer->getKey(),
            '--address' => self::ADDRESS,
            '--actor' => (string) $admin->getKey(),
        ])->assertFailed();

        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(0, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_verify_command_completes_the_ownership_challenge(): void
    {
        $this->app->instance(BscWalletOwnershipVerifierInterface::class, new FakeBscWalletOwnershipVerifier);

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();

        $this->artisan('usdt-receivers:register', [
            '--customer-id' => (string) $customer->getKey(),
            '--address' => self::ADDRESS,
            '--actor' => (string) $owner->getKey(),
        ])->assertSuccessful();

        $challenge = UsdtDepositReceiverOwnershipChallenge::query()->sole();

        $this->artisan('usdt-receivers:verify', [
            '--receiver' => (string) $challenge->receiver_id,
            '--challenge' => (string) $challenge->getKey(),
            '--wallet-type' => 'eoa',
            '--signature' => FakeBscWalletOwnershipVerifier::VALID_SIGNATURE,
            '--actor' => (string) $owner->getKey(),
        ])->assertSuccessful();

        $receiver = CustomerUsdtDepositReceiver::query()->sole();
        $this->assertSame(BscReceiverVerificationStatus::Verified, $receiver->verification_status);
        $this->assertSame('eip191_eoa', $receiver->verification_method);

        $this->artisan('usdt-receivers:verify', [
            '--receiver' => (string) $challenge->receiver_id,
            '--challenge' => (string) $challenge->getKey(),
            '--wallet-type' => 'eoa',
            '--signature' => FakeBscWalletOwnershipVerifier::VALID_SIGNATURE,
            '--actor' => (string) $owner->getKey(),
        ])->assertFailed();
    }

    public function test_retire_command_requires_an_owner_and_a_reason(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $support = User::factory()->create(['role' => UserRole::Support]);
        $customer = Customer::factory()->create();
        $receiver = app(UsdtDepositReceiverRegistry::class)
            ->manualVerify(
                app(UsdtDepositReceiverRegistry::class)->register($customer, self::ADDRESS, $owner)->receiver,
                true,
                $owner,
            );

        $this->artisan('usdt-receivers:retire', [
            '--receiver' => (string) $receiver->getKey(),
            '--reason' => 'rotasi address',
            '--actor' => (string) $support->getKey(),
        ])->assertFailed();
        $this->assertNull($receiver->fresh()->retired_at);

        $this->artisan('usdt-receivers:retire', [
            '--receiver' => (string) $receiver->getKey(),
            '--reason' => 'rotasi address',
            '--actor' => (string) $owner->getKey(),
        ])->assertSuccessful();

        $this->assertNotNull($receiver->fresh()->retired_at);
        $this->assertSame('rotasi address', $receiver->fresh()->retirement_reason);
        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
    }
}
