<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\StoreBalanceFundingPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class StoreBalanceFundingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_policy_and_controls_are_visible_only_to_store_managers(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('admin.dashboard', ['tab' => 'settings', 'section' => 'saldo']))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('admin.store-balance.funding-policy.update'), [
                    'minimum_amount' => '10000',
                    'preset_amounts' => "10000\n25000",
                    'version' => 0,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('store_balance_funding_policies', [
            'singleton_key' => StoreBalanceFundingPolicy::SINGLETON_KEY,
            'minimum_amount' => 5000,
            'version' => 0,
        ]);
    }

    public function test_owner_can_update_policy_with_one_versioned_audit_record(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
            ->actingAs($owner)
            ->post(route('admin.store-balance.funding-policy.update'), [
                'minimum_amount' => '10000',
                'preset_amounts' => "10000\n25000\n50000",
                'version' => 0,
            ]);

        $response->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up Saldo diperbarui.');
        $this->assertDatabaseHas('store_balance_funding_policies', [
            'singleton_key' => StoreBalanceFundingPolicy::SINGLETON_KEY,
            'minimum_amount' => 10000,
            'version' => 1,
        ]);
        $this->assertSame([10000, 25000, 50000], StoreBalanceFundingPolicy::current()?->presetAmounts());

        $audit = AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('203.0.113.45', $audit->ip_address);
        $this->assertSame(['minimum_amount' => 5000, 'preset_amounts' => [5000, 10000, 25000, 50000, 100000, 250000]], $audit->old_values);
        $this->assertSame(['minimum_amount' => 10000, 'preset_amounts' => [10000, 25000, 50000]], $audit->new_values);

        $retry = $this->actingAs($owner)->post(route('admin.store-balance.funding-policy.update'), [
            'minimum_amount' => '10000',
            'preset_amounts' => "10000\n25000\n50000",
            'version' => 1,
        ]);

        $retry->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up Saldo sudah sesuai. Tidak ada perubahan yang disimpan.');
        $this->assertSame(1, StoreBalanceFundingPolicy::current()?->policyVersion());
        $this->assertSame(1, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    public function test_repeating_the_current_policy_is_a_noop_without_audit_or_version_change(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = StoreBalanceFundingPolicy::current();
        $this->assertNotNull($before);
        $beforeId = $before->getKey();
        $beforeUpdatedAt = $before->updated_at?->toISOString();

        $response = $this->actingAs($owner)->post(route('admin.store-balance.funding-policy.update'), [
            'minimum_amount' => '5000',
            'preset_amounts' => "5000\n10000\n25000\n50000\n100000\n250000",
            'version' => 0,
        ]);

        $response->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up Saldo sudah sesuai. Tidak ada perubahan yang disimpan.');
        $after = StoreBalanceFundingPolicy::current();
        $this->assertSame($beforeId, $after?->getKey());
        $this->assertSame($beforeUpdatedAt, $after?->updated_at?->toISOString());
        $this->assertDatabaseHas('store_balance_funding_policies', ['version' => 0, 'minimum_amount' => 5000]);
        $this->assertSame(0, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    #[DataProvider('invalidPolicyProvider')]
    public function test_invalid_policy_input_preserves_the_complete_previous_policy(array $input): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = StoreBalanceFundingPolicy::current()?->toArray();

        $this->actingAs($owner)
            ->post(route('admin.store-balance.funding-policy.update'), $input + ['version' => 0])
            ->assertRedirect()
            ->assertSessionHasErrorsIn('fundingPolicy');

        $after = StoreBalanceFundingPolicy::current()?->toArray();
        $this->assertSame($before['minimum_amount'], $after['minimum_amount']);
        $this->assertSame($before['preset_amounts'], $after['preset_amounts']);
        $this->assertSame($before['version'], $after['version']);
        $this->assertSame(0, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    /** @return array<string, array{0: array{minimum_amount: mixed, preset_amounts: mixed}}> */
    public static function invalidPolicyProvider(): array
    {
        return [
            'duplicate' => [['minimum_amount' => '5000', 'preset_amounts' => "5000\n5000"]],
            'descending' => [['minimum_amount' => '5000', 'preset_amounts' => "10000\n5000"]],
            'below minimum' => [['minimum_amount' => '10000', 'preset_amounts' => "5000\n10000"]],
            'punctuation' => [['minimum_amount' => '5,000', 'preset_amounts' => "5000\n10000"]],
            'decimal' => [['minimum_amount' => '5000.00', 'preset_amounts' => "5000\n10000"]],
            'overflow' => [['minimum_amount' => (string) PHP_INT_MAX.'0', 'preset_amounts' => (string) PHP_INT_MAX]],
            'empty list' => [['minimum_amount' => '5000', 'preset_amounts' => '']],
            'too many presets' => [['minimum_amount' => '5000', 'preset_amounts' => implode("\n", array_fill(0, StoreBalanceFundingPolicy::MAX_PRESETS + 1, '5000'))]],
        ];
    }

    public function test_finance_and_support_cannot_mutate_the_policy(): void
    {
        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->post(route('admin.store-balance.funding-policy.update'), [
                    'minimum_amount' => '10000',
                    'preset_amounts' => "10000\n25000",
                    'version' => 0,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('store_balance_funding_policies', ['version' => 0, 'minimum_amount' => 5000]);
        $this->assertSame(0, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    public function test_stale_policy_version_is_rejected_without_overwriting_or_auditing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        StoreBalanceFundingPolicy::current()?->forceFill([
            'minimum_amount' => 7500,
            'preset_amounts' => [7500, 15000],
            'version' => 4,
        ])->save();

        $this->actingAs($owner)
            ->post(route('admin.store-balance.funding-policy.update'), [
                'minimum_amount' => '10000',
                'preset_amounts' => "10000\n25000",
                'version' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHasErrorsIn('fundingPolicy', ['version']);

        $this->assertDatabaseHas('store_balance_funding_policies', [
            'minimum_amount' => 7500,
            'version' => 4,
        ]);
        $this->assertSame([7500, 15000], StoreBalanceFundingPolicy::current()?->presetAmounts());
        $this->assertSame(0, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }

    public function test_policy_and_audit_roll_back_together_when_audit_write_fails(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            app(StoreBalanceFundingPolicyManager::class)->change(
                minimumAmount: '10000',
                presetAmounts: [10000, 25000],
                expectedVersion: 0,
                actor: $owner,
                ip: '198.51.100.12',
            );
            $this->fail('Expected the audit failure to abort the policy transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('store_balance_funding_policies', [
            'minimum_amount' => 5000,
            'version' => 0,
        ]);
        $this->assertSame([5000, 10000, 25000, 50000, 100000, 250000], StoreBalanceFundingPolicy::current()?->presetAmounts());
        $this->assertSame(0, AuditLog::query()->where('action', 'store_balance.funding_policy.updated')->count());
    }
}
