<?php

namespace Tests\Feature;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UserRole;
use App\Exceptions\BscReceiverChallengeException;
use App\Exceptions\BscReceiverDeletionBlocked;
use App\Http\Requests\Admin\BscReceiverStoreRequest;
use App\Jobs\SendBscReceiverMutationNotification;
use App\Models\AuditLog;
use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\User;
use App\Services\Admin\BscReceiverPendingMutationStore;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscReceiverManager;
use App\Services\Bsc\BscRpcClientInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BscReceiverConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'bsc_payment_enabled' => false,
        ]);
    }

    public function test_non_owner_cannot_mutate_a_receiver_even_after_password_confirmation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Admin wallet',
                'address' => '0x1111111111111111111111111111111111111111',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('bsc_payment_receivers', 0);
    }

    public function test_invalid_receiver_address_is_rejected_before_password_confirmation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet invalid',
                'address' => 'not-an-evm-address',
            ])
            ->assertRedirect('/admin?tab=stats#admin-bsc-settings')
            ->assertSessionHasErrorsIn('bsc', 'address');

        $this->assertDatabaseCount('bsc_payment_receivers', 0);
        $this->assertFalse(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));
    }

    public function test_address_rule_accepts_the_reported_checksum_address_without_preprocessing(): void
    {
        $request = new BscReceiverStoreRequest;
        $validator = Validator::make([
            'label' => 'Wallet valid',
            'address' => '0xaac58212Ab1E3C9c8d4dbB3FD3cdAF9d4cff9012',
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }

    public function test_owner_can_add_receiver_after_password_confirmation(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $address = '0xaac58212Ab1E3C9c8d4dbB3FD3cdAF9d4cff9012';

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet utama',
                'address' => $address,
            ])
            ->assertRedirect(route('password.confirm'));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $this->assertDatabaseHas('bsc_payment_receivers', [
            'label' => 'Wallet utama',
            'address_normalized' => strtolower($address),
            'is_active' => true,
            'verification_status' => BscReceiverVerificationStatus::ManualVerified->value,
            'verification_method' => 'manual_verified',
            'verified_by' => $owner->id,
        ]);
        $this->assertDatabaseCount('bsc_receiver_ownership_challenges', 0);
        $this->assertFalse(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));
        Queue::assertPushed(SendBscReceiverMutationNotification::class);
        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseCount('bsc_payment_receivers', 1);
        Queue::assertPushed(SendBscReceiverMutationNotification::class, 1);
    }

    public function test_owner_can_add_receiver_before_bsc_token_configuration_is_ready(): void
    {
        Queue::fake();
        $originalContract = config('services.bsc.usdt_contract');
        config()->set('services.bsc.usdt_contract', '');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $address = '0xaac58212Ab1E3C9c8d4dbB3FD3cdAF9d4cff9012';

        try {
            $this->actingAs($owner)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->post(route('admin.bsc.receivers.store'), [
                    'label' => 'Wallet sebelum readiness',
                    'address' => $address,
                ])
                ->assertRedirect('/admin?tab=stats#admin-bsc-settings')
                ->assertSessionHasNoErrors();
        } finally {
            config()->set('services.bsc.usdt_contract', $originalContract);
        }

        $this->assertDatabaseHas('bsc_payment_receivers', [
            'label' => 'Wallet sebelum readiness',
            'address_normalized' => strtolower($address),
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
            'token_decimals' => 18,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_add_receiver_with_unicode_boundary_whitespace(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $address = "\u{00A0}0xF43F918727E621BA6ABC31C2BCA292DC46D8E038\u{00A0}";

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet copy paste',
                'address' => $address,
            ])
            ->assertRedirect('/admin?tab=stats#admin-bsc-settings')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bsc_payment_receivers', [
            'label' => 'Wallet copy paste',
            'address_normalized' => '0xf43f918727e621ba6abc31c2bca292dc46d8e038',
        ]);
    }

    public function test_owner_can_save_multiple_bsc_receivers_without_an_ownership_challenge(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $addresses = [
            '0xf43f918727e621ba6abc31c2bca292dc46d8e038',
            '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ];

        foreach ($addresses as $index => $address) {
            $this->actingAs($owner)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->post(route('admin.bsc.receivers.store'), [
                    'label' => 'Wallet '.($index + 1),
                    'address' => $address,
                ])
                ->assertRedirect('/admin?tab=stats#admin-bsc-settings')
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('bsc_payment_receivers', 2);
        $this->assertDatabaseCount('bsc_receiver_ownership_challenges', 0);
        $this->assertDatabaseCount('audit_logs', 2);
        foreach ($addresses as $address) {
            $this->assertDatabaseHas('bsc_payment_receivers', [
                'address_normalized' => $address,
                'is_active' => true,
                'verification_status' => BscReceiverVerificationStatus::ManualVerified->value,
                'verification_method' => 'manual_verified',
                'verified_by' => $owner->id,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('admin.dashboard', ['tab' => 'stats']))
            ->assertOk()
            ->assertSee('Wallet 1')
            ->assertSee('Wallet 2')
            ->assertSee('Simpan address')
            ->assertDontSee('ownership challenge')
            ->assertDontSee('Buat challenge baru')
            ->assertDontSee('Verifikasi ownership signature')
            ->assertDontSee('Manual verified high risk');
    }

    public function test_wrong_password_keeps_pending_receiver_mutation_for_retry(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $address = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet retry',
                'address' => $address,
            ])
            ->assertRedirect(route('password.confirm'));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('bsc_payment_receivers', 0);
        $this->assertTrue(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $this->assertDatabaseHas('bsc_payment_receivers', [
            'label' => 'Wallet retry',
            'address_normalized' => $address,
        ]);
    }

    public function test_expired_pending_receiver_mutation_is_discarded(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet expired',
                'address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ])
            ->assertRedirect(route('password.confirm'));

        Carbon::setTestNow(now()->addSeconds(BscReceiverPendingMutationStore::PENDING_ACTION_TTL_SECONDS + 1));
        try {
            $this->post(route('admin.password.confirm.store'), [
                'password' => 'password',
            ])->assertRedirect(route('admin.dashboard'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseCount('bsc_payment_receivers', 0);
        $this->assertFalse(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));
    }

    public function test_pending_receiver_mutation_cannot_be_consumed_by_another_owner(): void
    {
        $originalOwner = User::factory()->create(['role' => UserRole::Owner]);
        $otherOwner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($originalOwner)
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet isolated',
                'address' => '0xdddddddddddddddddddddddddddddddddddddddd',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($otherOwner)
            ->post(route('admin.password.confirm.store'), [
                'password' => 'password',
            ])->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseCount('bsc_payment_receivers', 0);
        $this->assertFalse(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));
    }

    public function test_malformed_pending_receiver_mutation_is_ignored(): void
    {
        $owner = User::factory()->make();
        $owner->forceFill(['id' => 1]);
        $this->withSession([
            BscReceiverPendingMutationStore::SESSION_KEY => Crypt::encryptString(json_encode([
                'action' => 'store',
                'actor_id' => [],
                'payload' => [],
                'nonce' => 'nonce',
                'expires_at' => now()->addMinute()->timestamp,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $request = Request::create('/admin/confirm-password', 'POST');
        $request->setLaravelSession(app('session')->driver());

        $this->assertNull(app(BscReceiverPendingMutationStore::class)->consume($request, $owner));
        $this->assertFalse(session()->has(BscReceiverPendingMutationStore::SESSION_KEY));
    }

    public function test_owner_can_activate_a_legacy_pending_receiver_after_password_confirmation(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $receiver = BscPaymentReceiver::factory()->create([
            'label' => 'Legacy pending wallet',
            'address' => '0x5555555555555555555555555555555555555555',
            'is_active' => false,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.manual-verify', $receiver))
            ->assertRedirect(route('password.confirm'));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $this->assertSame(
            BscReceiverVerificationStatus::ManualVerified,
            $receiver->fresh()->verification_status,
        );
        $this->assertTrue($receiver->fresh()->is_active);
    }

    public function test_owner_can_activate_receiver_after_password_confirmation(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $receiver = BscPaymentReceiver::factory()->verified()->create([
            'token_contract' => self::CONTRACT,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.activate', $receiver))
            ->assertRedirect(route('password.confirm'));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $this->assertTrue($receiver->fresh()->is_active);
    }

    public function test_owner_can_deactivate_receiver_after_password_confirmation(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $receiver = BscPaymentReceiver::factory()->verified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.bsc.receivers.deactivate', $receiver))
            ->assertRedirect(route('password.confirm'));

        $this->post(route('admin.password.confirm.store'), [
            'password' => 'password',
        ])->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $this->assertFalse($receiver->fresh()->is_active);
    }

    public function test_owner_add_creates_an_active_manual_receiver_without_a_challenge(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('admin.bsc.receivers.store'), [
                'label' => 'Wallet utama',
                'address' => '0xaac58212Ab1E3C9c8d4dbB3FD3cdAF9d4cff9012',
            ])
            ->assertRedirect('/admin?tab=stats#admin-bsc-settings');

        $receiver = BscPaymentReceiver::query()->firstOrFail();

        $this->assertSame('0xaac58212ab1e3c9c8d4dbb3fd3cdaf9d4cff9012', $receiver->address_normalized);
        $this->assertSame(BscReceiverVerificationStatus::ManualVerified, $receiver->verification_status);
        $this->assertTrue($receiver->is_active);
        $this->assertSame('manual_verified', $receiver->verification_method);
        $this->assertSame($owner->id, $receiver->verified_by);
        $this->assertDatabaseCount('bsc_receiver_ownership_challenges', 0);
        Queue::assertPushed(SendBscReceiverMutationNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bsc_receiver.added', 'subject_id' => (string) $receiver->id]);
    }

    public function test_normalized_receiver_address_is_unique_across_all_lifecycle_states(): void
    {
        BscPaymentReceiver::factory()->create([
            'address' => '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd',
            'is_active' => false,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
        ]);

        $this->expectException(QueryException::class);

        BscPaymentReceiver::factory()->create([
            'address' => '0xABCDEFABCDEFABCDEFABCDEFABCDEFABCDEFABCD',
            'is_active' => false,
            'verification_status' => BscReceiverVerificationStatus::PendingVerification,
        ]);
    }

    public function test_duplicate_receiver_addition_returns_a_conflict_without_a_second_receiver(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(BscReceiverManager::class);

        $manager->add('Wallet utama', '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd', $owner);

        try {
            $manager->add('Wallet duplikat', '0xABCDEFABCDEFABCDEFABCDEFABCDEFABCDEFABCD', $owner);
            $this->fail('Expected duplicate BSC receiver registration to be rejected.');
        } catch (BscReceiverChallengeException $exception) {
            $this->assertStringContainsString('sudah terdaftar', $exception->getMessage());
        }

        $this->assertSame(1, BscPaymentReceiver::query()->count());
        $this->assertSame(0, BscReceiverOwnershipChallenge::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'bsc_receiver.added')->count());
        Queue::assertPushed(SendBscReceiverMutationNotification::class, 1);
    }

    public function test_database_address_conflict_is_translated_without_partial_side_effects(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The local transaction-conflict harness requires SQLite triggers.');
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_bsc_receiver_unique_conflict
            BEFORE INSERT ON bsc_payment_receivers
            WHEN NEW.address_normalized = '0x9999999999999999999999999999999999999999'
            BEGIN
                INSERT INTO bsc_payment_receivers (
                    label,
                    address,
                    address_normalized,
                    chain_id,
                    token_contract,
                    token_decimals,
                    is_active,
                    verification_status,
                    created_at,
                    updated_at
                ) VALUES (
                    'Conflicting receiver',
                    '0x9999999999999999999999999999999999999999',
                    '0x9999999999999999999999999999999999999999',
                    56,
                    '0x55d398326f99059ff775485246999027b3197955',
                    18,
                    0,
                    'pending_verification',
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                );
            END
        SQL);

        try {
            $owner = User::factory()->create(['role' => UserRole::Owner]);

            try {
                app(BscReceiverManager::class)->add(
                    'Wallet utama',
                    '0x9999999999999999999999999999999999999999',
                    $owner,
                );
                $this->fail('Expected the database address conflict to be translated.');
            } catch (BscReceiverChallengeException $exception) {
                $this->assertStringContainsString('sudah terdaftar', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS test_bsc_receiver_unique_conflict');
        }

        $this->assertSame(0, BscPaymentReceiver::query()->count());
        $this->assertSame(0, BscReceiverOwnershipChallenge::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'bsc_receiver.added')->count());
    }

    public function test_bsc_runtime_settings_are_stored_without_changing_the_binance_pay_rail(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $setting = ManualPaymentSetting::current();

        $this->actingAs($owner)
            ->post(route('admin.payment-mode.update'), [
                'update_scope' => 'bsc_settings',
                'payment_mode' => 'manual',
                'bsc_payment_enabled' => '1',
                'bsc_payment_ttl_minutes' => 30,
                'bsc_min_confirmations' => 3,
                'bsc_require_finalized_block' => '1',
                'bsc_confirmation_grace_minutes' => 3,
                'bsc_unique_amount_increment_micro' => 100,
                'bsc_hash_attempt_limit' => 5,
            ])
            ->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $setting = $setting->fresh();
        $this->assertTrue($setting->bsc_payment_enabled);
        $this->assertSame(30, $setting->bscPaymentTtlMinutes());
        $this->assertSame(3, $setting->bscMinConfirmations());
        $this->assertTrue($setting->bsc_require_finalized_block);
        $this->assertSame('manual', $setting->payment_mode);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bsc_settings.updated']);
    }

    public function test_readiness_requires_enabled_setting_rpc_identity_and_active_verified_receiver(): void
    {
        $rpc = new class implements BscRpcClientInterface
        {
            public function chainId(): int
            {
                return 56;
            }

            public function contractCode(string $contract): string
            {
                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                return 18;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }
        };
        app()->instance(BscRpcClientInterface::class, $rpc);

        $setting = ManualPaymentSetting::current();
        $setting->forceFill(['bsc_payment_enabled' => true])->save();
        $notReady = app(BscReadiness::class)->check($setting);
        $this->assertFalse($notReady->isReady());
        $this->assertContains('ACTIVE_VERIFIED_BSC_RECEIVER', $notReady->missing);

        BscPaymentReceiver::factory()->verified()->active()->create([
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
        ]);
        $ready = app(BscReadiness::class)->check($setting->fresh());
        $this->assertTrue($ready->isReady());
        $this->assertSame(56, $ready->rpcChainId);
        $this->assertSame(18, $ready->tokenDecimals);
    }

    public function test_committed_setting_and_receiver_mutations_invalidate_storefront_response_version(): void
    {
        Cache::put('storefront.response.version', 100, now()->addHour());
        ManualPaymentSetting::current()->forceFill(['bsc_payment_enabled' => true])->save();
        $afterSetting = (int) Cache::get('storefront.response.version');

        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
        $afterReceiver = (int) Cache::get('storefront.response.version');

        $this->assertGreaterThan(100, $afterSetting);
        $this->assertGreaterThan($afterSetting, $afterReceiver);
    }

    public function test_direct_manager_mutations_are_owner_only(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->expectException(AuthorizationException::class);

        app(BscReceiverManager::class)->add(
            'Not allowed',
            '0x6666666666666666666666666666666666666666',
            $admin,
        );
    }

    public function test_a_receiver_referenced_by_a_payment_detail_cannot_be_deleted(): void
    {
        $receiver = BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
        $payment = Payment::factory()->create([
            'provider' => 'manual',
            'method' => 'USDT_BEP20',
            'amount' => 1_000_000,
            'currency' => 'USDT',
        ]);
        PaymentBscDetail::create([
            'payment_id' => $payment->id,
            'receiver_id' => $receiver->id,
            'receiver_address' => $receiver->address_normalized,
            'chain_id' => 56,
            'token_contract' => self::CONTRACT,
            'token_decimals' => 18,
            'base_amount_micro' => 1_000_000,
            'payable_amount_micro' => 1_000_000,
            'expected_token_units' => '1000000000000000000',
            'verification_status' => PaymentBscDetail::STATUS_AWAITING_TRANSFER,
        ]);

        $this->expectException(BscReceiverDeletionBlocked::class);
        $receiver->delete();
    }

    private function assertAuditCount(string $action): int
    {
        return (int) AuditLog::query()->where('action', $action)->count();
    }
}
