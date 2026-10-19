<?php

namespace Tests\Feature;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\UserRole;
use App\Exceptions\CustomerUsdtFundingPolicyConflict;
use App\Models\AuditLog;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtFundingTerms;
use App\Models\User;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UsdtFundingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_policy_is_disabled_uncapped_and_versioned_with_approved_terms(): void
    {
        $state = app(CustomerUsdtFundingPolicyManager::class)->currentOrNull();

        $this->assertNotNull($state);
        $this->assertFalse($state->fundingEnabled);
        $this->assertSame(600, $state->confirmationTtlSeconds);
        $this->assertSame(0, $state->applicationFeeMicro);
        $this->assertSame(1, $state->termsVersion);
        $this->assertSame(0, $state->version);
        $this->assertStringContainsString('tidak dapat ditarik', app(CustomerUsdtFundingPolicyManager::class)->termsBody(1));
        $this->assertStringContainsString('fee aplikasi', app(CustomerUsdtFundingPolicyManager::class)->termsBody(1));

        // H0-v1: no business cap/minimum column exists at all, and the only
        // nominal column is the presentation-only preset list.
        $columns = Schema::getColumnListing('customer_usdt_funding_policies');
        foreach (['minimum_amount', 'maximum_amount', 'preset_amounts', 'exposure_cap', 'daily_quota', 'allowlist'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
        $this->assertContains('preset_amounts_micro', $columns);

        $this->assertSame(CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO, $state->presetAmountsMicro);
        $this->assertSame('1, 5, 10, 25, 50, 100', $state->presetAmountsInput());
        $this->assertSame(
            CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO,
            CustomerUsdtFundingPolicy::current()?->preset_amounts_micro,
        );

        $this->assertDatabaseHas('customer_usdt_funding_policies', [
            'singleton_key' => CustomerUsdtFundingPolicy::SINGLETON_KEY,
            'funding_enabled' => false,
            'version' => 0,
        ]);
    }

    public function test_stored_presets_that_are_not_a_usable_list_fall_back_to_the_defaults(): void
    {
        $policy = CustomerUsdtFundingPolicy::current();
        $this->assertNotNull($policy);

        $unusable = [
            [],
            ['1', '5'],
            [0],
            [-1],
            [1.5],
            [CustomerUsdtFundingPolicy::MAX_PRESET_AMOUNT_MICRO + 1],
            'bukan daftar preset',
            array_fill(0, CustomerUsdtFundingPolicy::MAX_PRESETS + 1, 1_000_000),
        ];

        foreach ($unusable as $stored) {
            $policy->forceFill(['preset_amounts_micro' => $stored])->save();

            $this->assertSame(
                CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO,
                $policy->refresh()->presetAmountsMicro(),
            );
        }
    }

    public function test_preset_only_edit_persists_bumps_version_and_is_audited_without_new_terms(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(CustomerUsdtFundingPolicyManager::class);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.46'])
            ->actingAs($owner)
            ->post(route('admin.store-balance.usdt-policy.update'), [
                'funding_enabled' => '0',
                'confirmation_ttl_seconds' => '600',
                'application_fee_micro' => '0',
                'terms_body' => $manager->termsBody(1),
                'preset_amounts' => "1, 2.5\n10\n0.000001",
                'version' => 0,
            ]);

        $response->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up USDT diperbarui.');

        $state = $manager->current();
        $this->assertSame([1_000_000, 2_500_000, 10_000_000, 1], $state->presetAmountsMicro);
        $this->assertSame('1, 2.5, 10, 0.000001', $state->presetAmountsInput());
        $this->assertSame(1, $state->version);
        $this->assertSame(1, $state->termsVersion);
        $this->assertSame(1, (int) CustomerUsdtFundingTerms::query()->max('version'));

        $audit = AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame('203.0.113.46', $audit->ip_address);
        $this->assertSame(CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO, $audit->old_values['preset_amounts_micro']);
        $this->assertSame([1_000_000, 2_500_000, 10_000_000, 1], $audit->new_values['preset_amounts_micro']);
        $this->assertSame(0, (int) $audit->old_values['version']);
        $this->assertSame(1, (int) $audit->new_values['version']);
    }

    public function test_duplicate_presets_are_deduplicated_in_list_order(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->post(route('admin.store-balance.usdt-policy.update'), [
            'funding_enabled' => '0',
            'confirmation_ttl_seconds' => '600',
            'application_fee_micro' => '0',
            'terms_body' => app(CustomerUsdtFundingPolicyManager::class)->termsBody(1),
            'preset_amounts' => "5\n1\n5\n10\n1",
            'version' => 0,
        ])->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up USDT diperbarui.');

        $this->assertSame(
            [5_000_000, 1_000_000, 10_000_000],
            CustomerUsdtFundingPolicy::current()?->presetAmountsMicro(),
        );
    }

    public function test_identical_presets_are_a_no_op_and_omitted_presets_keep_the_stored_list(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(CustomerUsdtFundingPolicyManager::class);
        $before = $manager->current();

        $identical = $manager->change(
            $before->fundingEnabled,
            $before->confirmationTtlSeconds,
            $before->applicationFeeMicro,
            $manager->termsBody($before->termsVersion),
            $before->version,
            $owner,
            null,
            CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO,
        );

        $this->assertFalse($identical->changed);
        $this->assertSame(0, $manager->current()->version);
        $this->assertSame(0, AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count());

        // A preset-only edit, then an edit that omits presets entirely.
        $manager->change(
            true,
            900,
            0,
            $manager->termsBody(1),
            0,
            $owner,
            null,
            [3_000_000, 7_000_000],
        );

        $omitted = $manager->change(
            true,
            1200,
            0,
            $manager->termsBody(1),
            1,
            $owner,
            null,
        );

        $this->assertTrue($omitted->changed);
        $this->assertSame([3_000_000, 7_000_000], $omitted->newState->presetAmountsMicro);
        $this->assertSame([3_000_000, 7_000_000], $manager->current()->presetAmountsMicro);
    }

    public function test_owner_update_bumps_version_appends_terms_and_never_mutates_money(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $termsBefore = (string) CustomerUsdtFundingTerms::query()->where('version', 1)->value('body');

        $moneyBefore = $this->moneyRowCounts();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
            ->actingAs($owner)
            ->post(route('admin.store-balance.usdt-policy.update'), [
                'funding_enabled' => '1',
                'confirmation_ttl_seconds' => '900',
                'application_fee_micro' => '1.5',
                'terms_body' => 'Syarat top-up USDT versi dua untuk pengujian.',
                'preset_amounts' => "1\n5\n10\n25\n50\n100",
                'version' => 0,
            ]);

        $response->assertRedirect()->assertSessionHas('status', 'Kebijakan top-up USDT diperbarui.');

        $state = app(CustomerUsdtFundingPolicyManager::class)->currentOrNull();
        $this->assertNotNull($state);
        $this->assertTrue($state->fundingEnabled);
        $this->assertSame(900, $state->confirmationTtlSeconds);
        $this->assertSame(1_500_000, $state->applicationFeeMicro);
        $this->assertSame(2, $state->termsVersion);
        $this->assertSame(1, $state->version);
        $this->assertSame(CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO, $state->presetAmountsMicro);

        $this->assertSame('Syarat top-up USDT versi dua untuk pengujian.', app(CustomerUsdtFundingPolicyManager::class)->termsBody(2));
        $this->assertSame($termsBefore, (string) CustomerUsdtFundingTerms::query()->where('version', 1)->value('body'));

        $audit = AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(0, (int) $audit->old_values['version']);
        $this->assertSame(1, (int) $audit->new_values['version']);
        $this->assertSame(1_500_000, (int) $audit->new_values['application_fee_micro']);

        $this->assertSame($moneyBefore, $this->moneyRowCounts());
    }

    public function test_stale_update_conflicts_and_changes_nothing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $policies = app(CustomerUsdtFundingPolicyManager::class);

        $policies->change(true, 600, 0, 'Syarat A untuk pengujian kebijakan.', 0, $owner, null);
        $before = $policies->current();

        try {
            $policies->change(true, 1200, 0, 'Syarat B untuk pengujian kebijakan.', 0, $owner, null);
            $this->fail('A stale policy version was expected to conflict.');
        } catch (CustomerUsdtFundingPolicyConflict) {
            // expected
        }

        $after = $policies->current();
        $this->assertSame($before->version, $after->version);
        $this->assertSame($before->confirmationTtlSeconds, $after->confirmationTtlSeconds);
        $this->assertSame(2, (int) CustomerUsdtFundingTerms::query()->max('version'));
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count());

        // HTTP path surfaces the conflict as a version error and keeps the row.
        $response = $this->actingAs($owner)->post(route('admin.store-balance.usdt-policy.update'), [
            'funding_enabled' => '0',
            'confirmation_ttl_seconds' => '600',
            'application_fee_micro' => '0',
            'terms_body' => 'Syarat A untuk pengujian kebijakan.',
            'preset_amounts' => "1\n5\n10\n25\n50\n100",
            'version' => 0,
        ]);

        $response->assertRedirect()->assertSessionHasErrors(['version'], null, 'usdtFundingPolicy');
        $this->assertSame($before->version, app(CustomerUsdtFundingPolicyManager::class)->current()->version);
    }

    public function test_no_op_update_writes_no_audit_and_does_not_touch_the_row(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = CustomerUsdtFundingPolicy::current();
        $this->assertNotNull($before);
        $beforeUpdatedAt = $before->updated_at?->toIso8601String();

        $response = $this->actingAs($owner)->post(route('admin.store-balance.usdt-policy.update'), [
            'funding_enabled' => '0',
            'confirmation_ttl_seconds' => '600',
            'application_fee_micro' => '0',
            'terms_body' => app(CustomerUsdtFundingPolicyManager::class)->termsBody(1),
            'preset_amounts' => "1\n5\n10\n25\n50\n100",
            'version' => 0,
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status', 'Kebijakan top-up USDT sudah sesuai. Tidak ada perubahan yang disimpan.');

        $after = CustomerUsdtFundingPolicy::current();
        $this->assertSame(0, (int) $after?->policyVersion());
        $this->assertSame($beforeUpdatedAt, $after?->updated_at?->toIso8601String());
        $this->assertSame(0, AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count());
        $this->assertSame(1, (int) CustomerUsdtFundingTerms::query()->max('version'));
    }

    #[DataProvider('invalidPolicyInputProvider')]
    public function test_invalid_policy_input_is_rejected_with_field_errors(array $input, string $expectedError): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.usdt-policy.update'), $input + [
            'funding_enabled' => '0',
            'confirmation_ttl_seconds' => '600',
            'application_fee_micro' => '0',
            'terms_body' => 'Syarat valid untuk pengujian kebijakan.',
            'preset_amounts' => "1\n5\n10\n25\n50\n100",
            'version' => 0,
        ]);

        $response->assertRedirect()->assertSessionHasErrors([$expectedError], null, 'usdtFundingPolicy');
        $this->assertSame(0, (int) CustomerUsdtFundingPolicy::current()?->policyVersion());
        $this->assertSame(
            CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO,
            CustomerUsdtFundingPolicy::current()?->presetAmountsMicro(),
        );
        $this->assertSame(0, AuditLog::query()->where('action', 'customer_usdt.funding_policy.updated')->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPolicyInputProvider(): array
    {
        return [
            'ttl below minimum' => [['confirmation_ttl_seconds' => '59'], 'confirmation_ttl_seconds'],
            'ttl above maximum' => [['confirmation_ttl_seconds' => '3601'], 'confirmation_ttl_seconds'],
            'ttl not numeric' => [['confirmation_ttl_seconds' => 'sepuluh menit'], 'confirmation_ttl_seconds'],
            'negative fee' => [['application_fee_micro' => '-1'], 'application_fee_micro'],
            'excess fractional fee' => [['application_fee_micro' => '1.1234567'], 'application_fee_micro'],
            'float fee' => [['application_fee_micro' => '1e3'], 'application_fee_micro'],
            'empty terms' => [['terms_body' => '   '], 'terms_body'],
            'oversized terms' => [['terms_body' => str_repeat('x', 20_001)], 'terms_body'],
            'control characters in terms' => [['terms_body' => "Syarat\x00terlarang"], 'terms_body'],
            'empty presets' => [['preset_amounts' => ''], 'preset_amounts'],
            'presets without list keys' => [['preset_amounts' => ['pertama' => '1', 'kedua' => '5']], 'preset_amounts'],
            'zero preset' => [['preset_amounts' => '0'], 'preset_amounts'],
            'negative preset' => [['preset_amounts' => '-1'], 'preset_amounts'],
            'non numeric preset' => [['preset_amounts' => 'satu usdt'], 'preset_amounts'],
            'float preset' => [['preset_amounts' => '1e3'], 'preset_amounts'],
            'excess precision preset' => [['preset_amounts' => '0.0000005'], 'preset_amounts'],
            'overflow preset' => [['preset_amounts' => '1000000.000001'], 'preset_amounts'],
            'too many presets' => [[
                'preset_amounts' => implode("\n", array_fill(0, CustomerUsdtFundingPolicy::MAX_PRESETS + 1, '5')),
            ], 'preset_amounts'],
        ];
    }

    public function test_non_owner_roles_cannot_change_policy_or_pause_funding(): void
    {
        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->post(route('admin.store-balance.usdt-policy.update'), [
                'funding_enabled' => '1',
                'confirmation_ttl_seconds' => '600',
                'application_fee_micro' => '0',
                'terms_body' => 'Syarat valid untuk pengujian kebijakan.',
                'version' => 0,
            ])->assertForbidden();

            $this->actingAs($user)->post(route('admin.store-balance.usdt-pauses.store', 'funding'), [
                'reason' => 'Upaya role tidak berwenang.',
            ])->assertForbidden();
        }

        $this->assertSame(0, (int) CustomerUsdtFundingPolicy::current()?->policyVersion());
        $this->assertSame(0, DB::table('customer_usdt_funding_pauses')->count());
    }

    public function test_owner_pause_and_resume_are_idempotent_and_audited(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $manager = app(CustomerUsdtFundingPolicyManager::class);

        $response = $this->actingAs($owner)->post(route('admin.store-balance.usdt-pauses.store', 'funding'), [
            'reason' => 'Insiden penyedia sedang diperiksa.',
        ]);
        $response->assertRedirect()->assertSessionHas('status', 'Top-up USDT dijeda. Dana yang sudah masuk tetap direkonsiliasi.');

        $this->assertTrue($manager->isPaused(CustomerUsdtFundingPauseScope::Funding));
        $this->assertDatabaseHas('customer_usdt_funding_pauses', [
            'scope' => 'funding',
            'reason' => 'Insiden penyedia sedang diperiksa.',
            'paused_by_user_id' => $owner->id,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_usdt.funding_pause.created')->count());

        $again = $this->actingAs($owner)->post(route('admin.store-balance.usdt-pauses.store', 'funding'), [
            'reason' => 'Alasan kedua tidak menggantikan yang pertama.',
        ]);
        $again->assertRedirect()->assertSessionHas('status', 'Top-up USDT sudah dalam keadaan dijeda.');
        $this->assertSame('Insiden penyedia sedang diperiksa.', (string) DB::table('customer_usdt_funding_pauses')->where('scope', 'funding')->value('reason'));
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_usdt.funding_pause.created')->count());

        $resume = $this->actingAs($owner)->delete(route('admin.store-balance.usdt-pauses.destroy', 'funding'));
        $resume->assertRedirect()->assertSessionHas('status', 'Top-up USDT dilanjutkan.');
        $this->assertFalse($manager->isPaused(CustomerUsdtFundingPauseScope::Funding));
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_usdt.funding_pause.deleted')->count());

        $resumeAgain = $this->actingAs($owner)->delete(route('admin.store-balance.usdt-pauses.destroy', 'funding'));
        $resumeAgain->assertRedirect()->assertSessionHas('status', 'Top-up USDT tidak sedang dijeda.');
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_usdt.funding_pause.deleted')->count());
    }

    public function test_pause_requires_a_bounded_single_line_reason_and_known_scope(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.store-balance.usdt-pauses.store', 'funding'), ['reason' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors(['reason'], null, 'usdtFundingPolicy');

        $this->actingAs($owner)
            ->post(route('admin.store-balance.usdt-pauses.store', 'funding'), ['reason' => "dua\nbaris"])
            ->assertRedirect()
            ->assertSessionHasErrors(['reason'], null, 'usdtFundingPolicy');

        $this->actingAs($owner)
            ->post(route('admin.store-balance.usdt-pauses.store', 'wallet_backdoor'), ['reason' => 'Scope tidak dikenal.'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('customer_usdt_funding_pauses')->count());
    }

    /** @return array<string, int> */
    private function moneyRowCounts(): array
    {
        return [
            'usdt_entries' => (int) DB::table('customer_usdt_balance_entries')->count(),
            'usdt_accounts' => (int) DB::table('customer_usdt_balance_accounts')->count(),
            'usdt_reservations' => (int) DB::table('customer_usdt_funding_reservations')->count(),
            'idr_entries' => (int) DB::table('store_balance_entries')->count(),
            'idr_accounts' => (int) DB::table('customer_balance_accounts')->count(),
        ];
    }
}
