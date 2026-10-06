<?php

namespace Tests\Feature;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtDepositReceiverSource;
use App\Enums\UsdtReceiverChannel;
use App\Enums\UserRole;
use App\Exceptions\BscReceiverChallengeException;
use App\Exceptions\UsdtDepositReceiverAlreadyActive;
use App\Exceptions\UsdtDepositReceiverVerificationFailed;
use App\Exceptions\UsdtReceiverAddressConflict;
use App\Exceptions\UsdtReceiverBindingImmutable;
use App\Exceptions\UsdtReceiverCapacityUnavailable;
use App\Models\AuditLog;
use App\Models\BscPaymentReceiver;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtDepositReceiverOwnershipChallenge;
use App\Models\UsdtReceiverAddressClaim;
use App\Models\User;
use App\Services\Bsc\BscReceiverManager;
use App\Services\Bsc\BscWalletOwnershipVerifierInterface;
use App\Services\UsdtReceivers\UsdtDepositReceiverProvisioners;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtReceivers\UsdtDepositReceiverSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\FakeBscWalletOwnershipVerifier;
use Tests\Support\FakeUsdtDepositReceiverProvisioner;
use Tests\TestCase;

final class UsdtDepositReceiverRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_owner_registers_a_pending_exclusive_receiver_with_claim_and_sanitized_audit(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();

        $registration = $this->registry()->register($customer, strtoupper(self::ADDRESS), $owner);

        $receiver = $registration->receiver->fresh();
        $this->assertSame(BscReceiverVerificationStatus::PendingVerification, $receiver->verification_status);
        $this->assertSame((int) $customer->getKey(), (int) $receiver->customer_id);
        $this->assertSame(UsdtDepositReceiverSource::Owner, $receiver->source);
        $this->assertSame(UsdtDepositReceiverRegistry::OPERATOR_CUSTODY_NAMESPACE, $receiver->custody_namespace);
        $this->assertSame(self::ADDRESS, $receiver->address_normalized);
        $this->assertSame((int) $owner->getKey(), (int) $receiver->registered_by);
        $this->assertNotNull($registration->challenge);
        $this->assertSame(
            1,
            UsdtDepositReceiverOwnershipChallenge::query()->where('receiver_id', $receiver->getKey())->count(),
        );

        $claim = UsdtReceiverAddressClaim::query()->sole();
        $this->assertSame(UsdtReceiverChannel::CustomerDeposit, $claim->channel);
        $this->assertSame(UsdtDepositReceiverRegistry::SUPPORTED_CHAIN_ID, (int) $claim->chain_id);
        $this->assertSame(self::ADDRESS, $claim->address_normalized);
        $this->assertSame((int) $claim->getKey(), (int) $receiver->address_claim_id);

        $audit = AuditLog::query()->where('action', 'usdt_deposit_receiver.registered')->sole();
        $this->assertSame((int) $owner->getKey(), (int) $audit->user_id);
        $this->assertSame((int) $receiver->getKey(), (int) $audit->subject_id);
        $values = $audit->new_values;
        $this->assertSame(hash('sha256', self::ADDRESS), $values['address_fingerprint']);
        $this->assertStringNotContainsString(self::ADDRESS, json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function test_pending_receiver_is_not_assignable_and_blocks_silent_reprovisioning(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $this->registry()->register($customer, self::ADDRESS, $owner);

        $provisioner = new FakeUsdtDepositReceiverProvisioner;
        $this->bindProvisioners($provisioner);

        try {
            $this->registry()->snapshotFor($customer);
            $this->fail('A pending receiver must not be assignable.');
        } catch (UsdtReceiverCapacityUnavailable) {
            // expected: fail closed until ownership is verified
        }

        try {
            $this->registry()->allocateFor($customer);
            $this->fail('A pending receiver must block a second provisioning attempt.');
        } catch (UsdtReceiverCapacityUnavailable) {
            // expected
        }

        $this->assertSame(0, $provisioner->provideCalls);
        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
    }

    public function test_manual_verification_requires_an_explicit_high_risk_acknowledgement(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $receiver = $this->registry()->register($customer, self::ADDRESS, $owner)->receiver;

        try {
            $this->registry()->manualVerify($receiver, false, $owner);
            $this->fail('Manual verification without acknowledgement must be rejected.');
        } catch (UsdtDepositReceiverVerificationFailed) {
            // expected
        }

        $this->assertSame(
            BscReceiverVerificationStatus::PendingVerification,
            $receiver->fresh()->verification_status,
        );

        $verified = $this->registry()->manualVerify($receiver, true, $owner);

        $this->assertSame(BscReceiverVerificationStatus::ManualVerified, $verified->verification_status);
        $this->assertSame('manual_verified', $verified->verification_method);
        $this->assertTrue($verified->isReadyForAssignment());
        $this->assertSame(
            (int) $receiver->getKey(),
            $this->registry()->snapshotFor($customer)->receiverId,
        );
    }

    public function test_ownership_verification_binds_the_challenge_message_to_the_address_and_actor(): void
    {
        $verifier = new FakeBscWalletOwnershipVerifier;
        $this->app->instance(BscWalletOwnershipVerifierInterface::class, $verifier);

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $otherOwner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $registration = $this->registry()->register($customer, self::ADDRESS, $owner);
        $receiver = $registration->receiver;
        $challengeId = (string) $registration->challenge->getKey();

        try {
            $this->registry()->verify($receiver, $challengeId, 'eoa', 'wrong-signature', $owner);
            $this->fail('An invalid signature must not verify the receiver.');
        } catch (UsdtDepositReceiverVerificationFailed) {
            // expected
        }

        try {
            $this->registry()->verify($receiver, $challengeId, 'eoa', FakeBscWalletOwnershipVerifier::VALID_SIGNATURE, $otherOwner);
            $this->fail('Another Owner must not complete a foreign challenge.');
        } catch (UsdtDepositReceiverVerificationFailed) {
            // expected
        }

        $verified = $this->registry()->verify(
            $receiver,
            $challengeId,
            'eoa',
            FakeBscWalletOwnershipVerifier::VALID_SIGNATURE,
            $owner,
        );

        $this->assertSame(BscReceiverVerificationStatus::Verified, $verified->verification_status);
        $this->assertSame('eip191_eoa', $verified->verification_method);
        $this->assertSame((int) $owner->getKey(), (int) $verified->verified_by);
        $this->assertNotNull(
            UsdtDepositReceiverOwnershipChallenge::query()->whereKey($challengeId)->sole()->used_at,
        );
        $this->assertCount(2, $verifier->verifiedMessages);
        $verifiedMessage = $verifier->verifiedMessages[1];
        $this->assertSame(self::ADDRESS, $verifiedMessage['address']);
        $this->assertStringContainsString(
            'bind_usdt_deposit_receiver',
            $verifiedMessage['message'],
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'usdt_deposit_receiver.verified',
            'subject_id' => (string) $receiver->getKey(),
        ]);

        try {
            $this->registry()->verify($receiver, $challengeId, 'eoa', FakeBscWalletOwnershipVerifier::VALID_SIGNATURE, $owner);
            $this->fail('A used challenge must not verify twice.');
        } catch (UsdtDepositReceiverVerificationFailed) {
            // expected
        }
    }

    public function test_an_address_owned_by_the_checkout_pool_cannot_become_a_customer_deposit_receiver(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        app(BscReceiverManager::class)->add('Wallet checkout', self::ADDRESS, $owner);

        try {
            $this->registry()->register($customer, self::ADDRESS, $owner);
            $this->fail('An address claimed by the checkout pool must not be bound to a Customer.');
        } catch (UsdtReceiverAddressConflict $exception) {
            $this->assertStringContainsString('checkout', $exception->getMessage());
        }

        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(UsdtReceiverChannel::Checkout, UsdtReceiverAddressClaim::query()->sole()->channel);
    }

    public function test_checkout_registration_rejects_an_address_owned_by_a_customer_deposit_receiver(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $this->registry()->register($customer, self::ADDRESS, $owner);

        try {
            app(BscReceiverManager::class)->add('Wallet checkout', self::ADDRESS, $owner);
            $this->fail('The checkout pool must not register a Customer-exclusive deposit address.');
        } catch (BscReceiverChallengeException $exception) {
            $this->assertStringContainsString('sudah terdaftar', $exception->getMessage());
        }

        $this->assertSame(0, BscPaymentReceiver::query()->count());
        $this->assertSame(UsdtReceiverChannel::CustomerDeposit, UsdtReceiverAddressClaim::query()->sole()->channel);
    }

    public function test_two_customers_cannot_share_one_receiver_address(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();
        $this->registry()->register($first, self::ADDRESS, $owner);

        try {
            $this->registry()->register($second, self::ADDRESS, $owner);
            $this->fail('A second Customer must not bind an already claimed receiver address.');
        } catch (UsdtReceiverAddressConflict) {
            // expected
        }

        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_receiver_address_is_never_reassigned_after_retire_or_pending_deletion(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        $registration = $this->registry()->register($first, self::ADDRESS, $owner);
        $receiver = $registration->receiver;
        $verified = $this->registry()->manualVerify($receiver, true, $owner);
        $this->registry()->retire($verified, $owner, 'rotasi address');

        try {
            $this->registry()->register($second, self::ADDRESS, $owner);
            $this->fail('A retired receiver address must never be reassigned.');
        } catch (UsdtReceiverAddressConflict) {
            // expected
        }

        $pendingOwner = User::factory()->create(['role' => UserRole::Owner]);
        $pendingCustomer = Customer::factory()->create();
        $pendingAddress = '0x2222222222222222222222222222222222222222';
        $pending = $this->registry()->register($pendingCustomer, $pendingAddress, $pendingOwner)->receiver;

        // A pending, never-verified binding may be removed for a typo, but the
        // permanent claim keeps the address out of circulation forever.
        $this->assertTrue($pending->delete());
        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->whereKey($pending->getKey())->count());

        try {
            $this->registry()->register($pendingCustomer, $pendingAddress, $pendingOwner);
            $this->fail('A previously claimed address must never be re-bound.');
        } catch (UsdtReceiverAddressConflict) {
            // expected
        }
    }

    public function test_customer_binding_identity_and_history_are_immutable(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $receiver = $this->registry()->register($customer, self::ADDRESS, $owner)->receiver;

        try {
            $receiver->forceFill(['customer_id' => $other->getKey()])->save();
            $this->fail('The Customer binding must be immutable.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        try {
            $receiver->forceFill(['address' => '0x3333333333333333333333333333333333333333'])->save();
            $this->fail('The receiver address must be immutable.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        $verified = $this->registry()->manualVerify($receiver->fresh(), true, $owner);

        try {
            $verified->forceFill(['verification_status' => BscReceiverVerificationStatus::PendingVerification])->save();
            $this->fail('A decided verification must be final.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        $retired = $this->registry()->retire($verified->fresh(), $owner, 'tidak dipakai lagi');

        try {
            $retired->forceFill(['retired_at' => null])->save();
            $this->fail('A retired receiver must never be un-retired.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        try {
            $retired->delete();
            $this->fail('Bound receiver history must not be deletable.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        $claim = UsdtReceiverAddressClaim::query()->sole();
        try {
            $claim->forceFill(['channel' => UsdtReceiverChannel::Checkout])->save();
            $this->fail('Address claims must be immutable.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        try {
            $claim->delete();
            $this->fail('Address claims must never be released.');
        } catch (UsdtReceiverBindingImmutable) {
            // expected
        }

        // Every rejected write left the persisted binding, claim and history untouched.
        $unchanged = $receiver->fresh();
        $this->assertSame((int) $customer->getKey(), (int) $unchanged->customer_id);
        $this->assertSame(self::ADDRESS, $unchanged->address_normalized);
        $this->assertSame(BscReceiverVerificationStatus::ManualVerified, $unchanged->verification_status);
        $this->assertNotNull($unchanged->retired_at);
        $this->assertSame('tidak dipakai lagi', $unchanged->retirement_reason);
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
    }

    public function test_retiring_keeps_history_monitored_and_allows_rotation_for_the_same_customer(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $first = $this->registry()->manualVerify(
            $this->registry()->register($customer, self::ADDRESS, $owner)->receiver,
            true,
            $owner,
        );

        $retired = $this->registry()->retire($first, $owner, 'rotasi ke address baru');

        $this->assertNotNull($retired->retired_at);
        $this->assertSame('rotasi ke address baru', $retired->retirement_reason);
        $this->assertSame((int) $owner->getKey(), (int) $retired->retired_by);
        $this->assertNull($this->registry()->activeFor($customer));

        $secondAddress = '0x4444444444444444444444444444444444444444';
        $second = $this->registry()->manualVerify(
            $this->registry()->register($customer, $secondAddress, $owner)->receiver,
            true,
            $owner,
        );

        $this->assertSame((int) $second->getKey(), $this->registry()->snapshotFor($customer)->receiverId);
        $this->assertSame((int) $second->getKey(), $this->registry()->activeFor($customer)?->getKey());

        $monitored = $this->registry()->monitoredReceivers();
        $this->assertSame([(int) $first->getKey(), (int) $second->getKey()], $monitored->pluck('id')->all());

        $this->assertSame(
            (int) $first->getKey(),
            (int) $this->registry()->findByAddress(
                UsdtDepositReceiverRegistry::SUPPORTED_CHAIN_ID,
                self::ADDRESS,
            )?->getKey(),
        );

        $audit = AuditLog::query()->where('action', 'usdt_deposit_receiver.retired')->sole();
        $this->assertSame('rotasi ke address baru', $audit->new_values['reason']);
    }

    public function test_a_second_active_receiver_for_the_same_customer_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $this->registry()->register($customer, self::ADDRESS, $owner);

        try {
            $this->registry()->register($customer, '0x5555555555555555555555555555555555555555', $owner);
            $this->fail('Only one non-retired receiver per Customer is allowed.');
        } catch (UsdtDepositReceiverAlreadyActive) {
            // expected
        }

        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_unsupported_chain_or_token_metadata_fails_closed_when_snapshotted(): void
    {
        $receiver = CustomerUsdtDepositReceiver::factory()->manuallyVerified()->create(['chain_id' => 97]);

        try {
            UsdtDepositReceiverSnapshot::fromReceiver($receiver);
            $this->fail('An unsupported chain must not produce an assignable snapshot.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $wrongDecimals = CustomerUsdtDepositReceiver::factory()
            ->provisioned('fake_custody')
            ->create(['token_decimals' => 2]);

        $this->assertSame('fake_custody', $wrongDecimals->custody_namespace);

        try {
            UsdtDepositReceiverSnapshot::fromReceiver($wrongDecimals);
            $this->fail('Token decimals that cannot represent micro-USDT must fail closed.');
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    public function test_retired_receiver_history_is_excluded_from_assignment_but_kept_monitored(): void
    {
        $customer = Customer::factory()->create();
        $receiver = CustomerUsdtDepositReceiver::factory()
            ->verified()
            ->retired('histori import')
            ->create(['customer_id' => $customer->getKey()]);

        $this->assertTrue($receiver->isRetired());
        $this->assertFalse($receiver->isReadyForAssignment());
        $this->assertNull($this->registry()->activeFor($customer));
        $this->assertSame([(int) $receiver->getKey()], $this->registry()->monitoredReceivers()->pluck('id')->all());
        $this->assertSame(
            (int) $receiver->getKey(),
            (int) $this->registry()
                ->findByAddress(UsdtDepositReceiverRegistry::SUPPORTED_CHAIN_ID, $receiver->address_normalized)
                ?->getKey(),
        );

        try {
            $this->registry()->snapshotFor($customer);
            $this->fail('A retired receiver must not be assignable.');
        } catch (UsdtReceiverCapacityUnavailable) {
            // expected
        }
    }

    public function test_allocation_without_an_approved_provisioner_fails_closed(): void
    {
        $customer = Customer::factory()->create();

        $this->assertTrue(app(UsdtDepositReceiverProvisioners::class)->isEmpty());

        try {
            $this->registry()->allocateFor($customer);
            $this->fail('Allocation without an approved provisioner must fail closed.');
        } catch (UsdtReceiverCapacityUnavailable $exception) {
            $this->assertStringContainsString('provisioning adapter', $exception->getMessage());
        }

        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(0, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_exhausted_capacity_and_claimed_addresses_never_fall_back_to_a_shared_receiver(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();

        $exhausted = FakeUsdtDepositReceiverProvisioner::exhausted();
        $this->bindProvisioners($exhausted);

        try {
            $this->registry()->allocateFor($customer);
            $this->fail('An empty provisioner response must fail closed.');
        } catch (UsdtReceiverCapacityUnavailable $exception) {
            $this->assertStringContainsString('Kapasitas', $exception->getMessage());
        }

        $this->assertSame(1, $exhausted->provideCalls);
        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());

        app(BscReceiverManager::class)->add('Wallet checkout', self::ADDRESS, $owner);
        $this->bindProvisioners(FakeUsdtDepositReceiverProvisioner::fixed(self::ADDRESS));

        try {
            $this->registry()->allocateFor($customer);
            $this->fail('A provisioned address owned by the checkout pool must be refused, not shared.');
        } catch (UsdtReceiverAddressConflict) {
            // expected
        }

        $this->assertSame(0, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(UsdtReceiverChannel::Checkout, UsdtReceiverAddressClaim::query()->sole()->channel);
    }

    public function test_allocation_reuses_the_existing_receiver_without_reprovisioning(): void
    {
        $provisioner = new FakeUsdtDepositReceiverProvisioner;
        $this->bindProvisioners($provisioner);

        $customer = Customer::factory()->create();
        $first = $this->registry()->allocateFor($customer);
        $second = $this->registry()->allocateFor($customer);

        $this->assertSame($first->receiverId, $second->receiverId);
        $this->assertSame(1, $provisioner->provideCalls);

        $receiver = CustomerUsdtDepositReceiver::query()->sole();
        $this->assertSame(UsdtDepositReceiverSource::Provisioner, $receiver->source);
        $this->assertSame('fake_custody', $receiver->custody_namespace);
        $this->assertSame('custody_provisioner:fake_custody', $receiver->verification_method);
        $this->assertSame((int) $customer->getKey(), $first->customerId);
        $this->assertStringContainsString('fake_custody', $first->custodyNamespace);

        $audit = AuditLog::query()->where('action', 'usdt_deposit_receiver.provisioned')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame('fake_custody', $audit->new_values['custody_provisioner']);
    }

    public function test_only_owner_can_manage_customer_deposit_receivers(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $receiver = $this->registry()->register($customer, self::ADDRESS, $owner)->receiver;

        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            $actor = User::factory()->create(['role' => $role]);

            try {
                $this->registry()->register($customer, '0x6666666666666666666666666666666666666666', $actor);
                $this->fail("Role {$role->value} must not register deposit receivers.");
            } catch (AuthorizationException) {
                // expected
            }

            try {
                $this->registry()->manualVerify($receiver, true, $actor);
                $this->fail("Role {$role->value} must not verify deposit receivers.");
            } catch (AuthorizationException) {
                // expected
            }

            try {
                $this->registry()->retire($receiver, $actor, 'unauthorized');
                $this->fail("Role {$role->value} must not retire deposit receivers.");
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(1, CustomerUsdtDepositReceiver::query()->count());
        $this->assertSame(
            BscReceiverVerificationStatus::PendingVerification,
            $receiver->fresh()->verification_status,
        );
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_checkout_claim_is_reused_only_within_the_checkout_channel(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(BscReceiverManager::class);

        $manager->add('Wallet utama', self::ADDRESS, $owner);
        $claimId = (int) UsdtReceiverAddressClaim::query()->sole()->getKey();

        BscPaymentReceiver::query()->sole()->delete();

        $manager->add('Wallet utama baru', self::ADDRESS, $owner);

        $this->assertSame(1, BscPaymentReceiver::query()->count());
        $this->assertSame($claimId, (int) BscPaymentReceiver::query()->sole()->address_claim_id);
        $this->assertSame(1, UsdtReceiverAddressClaim::query()->count());
    }

    public function test_monitored_receiver_inventory_is_keyset_bounded(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $customer = Customer::factory()->create();
            $address = '0x'.str_pad((string) ($index + 1), 40, 'a', STR_PAD_LEFT);
            $ids[] = (int) $this->registry()
                ->manualVerify($this->registry()->register($customer, $address, $owner)->receiver, true, $owner)
                ->getKey();
        }

        $this->registry()->retire(
            CustomerUsdtDepositReceiver::query()->findOrFail($ids[1]),
            $owner,
            'maintenance',
        );

        $firstPage = $this->registry()->monitoredReceivers(null, 2);
        $this->assertSame([$ids[0], $ids[1]], $firstPage->pluck('id')->all());

        $secondPage = $this->registry()->monitoredReceivers($ids[1], 2);
        $this->assertSame([$ids[2]], $secondPage->pluck('id')->all());

        $this->assertSame(2, $this->registry()->monitoredReceivers(null, 2)->count());
    }

    private function registry(): UsdtDepositReceiverRegistry
    {
        return app(UsdtDepositReceiverRegistry::class);
    }

    private function bindProvisioners(FakeUsdtDepositReceiverProvisioner ...$provisioners): void
    {
        $this->app->instance(
            UsdtDepositReceiverProvisioners::class,
            new UsdtDepositReceiverProvisioners($provisioners),
        );
    }
}
