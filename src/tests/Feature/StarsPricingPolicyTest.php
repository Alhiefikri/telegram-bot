<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TelegramStars\StarsPricingPolicy;
use App\Services\TelegramStars\StarsPricingPolicyManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class StarsPricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = [
        'idr_per_usd' => '18300',
        'reward_micro_usd' => '13000',
        'reserve_bps' => '1500',
        'issuance_enabled' => '0',
        'version' => '0',
    ];

    public function test_owner_can_load_the_unconfigured_policy_and_preview_unsaved_inputs(): void
    {
        $this->withoutVite()->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $show = route('admin.stars-pricing.show');

        $this->get($show)->assertOk()->assertSee('Izin penerbitan: Nonaktif')->assertSee('Simpan kebijakan');
        $before = app(StarsPricingPolicyManager::class)->current()->values();
        self::assertSame([
            'idr_per_usd' => null,
            'reward_micro_usd' => 13_000,
            'reserve_bps' => null,
            'issuance_enabled' => false,
            'version' => 0,
        ], $before);

        foreach ([18_300 => 50, 18_500 => 49] as $rate => $xtr) {
            $this->post(route('admin.stars-pricing.preview'), [
                'total_idr' => '10000',
                'idr_per_usd' => (string) $rate,
            ] + self::POLICY)->assertRedirect($show)
                ->assertSessionHas('stars_pricing_preview', ['total_idr' => 10_000, 'total_xtr' => $xtr]);

            $this->get($show)->assertOk()->assertSee("Preview: Rp10000 → {$xtr} XTR");
        }

        self::assertSame($before, app(StarsPricingPolicyManager::class)->current()->values());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_save_is_versioned_audited_and_rejects_a_stale_submission(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner);
        $update = route('admin.stars-pricing.update');
        $show = route('admin.stars-pricing.show');

        $this->post($update, self::POLICY + ['provider_token' => 'never-in-audit', 'total_idr' => '10000'])
            ->assertRedirect($show)->assertSessionHasNoErrors();

        $this->post($update, ['idr_per_usd' => '18500'] + self::POLICY)
            ->assertRedirect($show)->assertSessionHasErrors('version');
        $this->post($update, ['version' => '1'] + self::POLICY)
            ->assertRedirect($show)->assertSessionHas('status', 'Tidak ada perubahan kebijakan.');

        $expected = ['idr_per_usd' => 18_300, 'reward_micro_usd' => 13_000, 'reserve_bps' => 1500, 'issuance_enabled' => false, 'version' => 1];
        self::assertSame($expected, app(StarsPricingPolicyManager::class)->current()->values());
        $audit = AuditLog::query()->where('action', 'telegram_stars.pricing_policy.updated')->sole();
        self::assertSame($owner->id, $audit->user_id);
        $old = ['idr_per_usd' => null, 'reward_micro_usd' => 13_000, 'reserve_bps' => null, 'issuance_enabled' => false, 'version' => 0];
        $this->assertAuditValues($expected, $audit->new_values);
        $this->assertAuditValues($old, $audit->old_values);

        $this->post($update, ['version' => '1', 'issuance_enabled' => '1', 'reward_micro_usd' => '12000'] + self::POLICY)
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stars_pricing_policies', ['issuance_enabled' => true, 'reward_micro_usd' => 12_000, 'version' => 2]);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guests_and_all_non_owner_roles_cannot_access_the_policy(): void
    {
        $routes = ['show' => 'GET', 'preview' => 'POST', 'update' => 'POST'];
        foreach ($routes as $name => $method) {
            $this->call($method, route("admin.stars-pricing.{$name}"))->assertRedirect(route('admin.login'));
        }

        foreach ([UserRole::Admin, UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($routes as $name => $method) {
                $this->call($method, route("admin.stars-pricing.{$name}"), self::POLICY + ['total_idr' => '10000'])->assertForbidden();
            }
        }
        $this->assertDatabaseHas('stars_pricing_policies', ['version' => 0]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_owner_cannot_bypass_http_using_the_policy_manager(): void
    {
        $this->expectException(AuthorizationException::class);

        app(StarsPricingPolicyManager::class)->change(
            User::factory()->create(['role' => UserRole::Admin]),
            new StarsPricingPolicy(18_300, 13_000, 1500, true, 0),
            null,
        );
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_does_not_write(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->postJson(route('admin.stars-pricing.update'), [$field => $value] + self::POLICY)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('stars_pricing_policies', ['version' => 0, 'issuance_enabled' => false]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            ['idr_per_usd', 0],
            ['idr_per_usd', '18.300'],
            ['idr_per_usd', '1e4'],
            ['idr_per_usd', (string) PHP_INT_MAX.'0'],
            ['reward_micro_usd', false],
            ['reward_micro_usd', 1.5],
            ['reserve_bps', -1],
            ['reserve_bps', 10_000],
            ['reserve_bps', []],
            ['version', true],
            ['issuance_enabled', 2],
        ];
    }

    public function test_preview_rejects_empty_or_out_of_range_amounts(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        foreach (['', '0', '1.00', (string) PHP_INT_MAX] as $amount) {
            $this->post(route('admin.stars-pricing.preview'), self::POLICY + ['total_idr' => $amount])
                ->assertRedirect(route('admin.stars-pricing.show'))
                ->assertSessionHasErrors('total_idr')
                ->assertSessionMissing('stars_pricing_preview');
        }
        $this->assertDatabaseHas('stars_pricing_policies', ['version' => 0]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_policy_write_rolls_back_when_audit_fails(): void
    {
        $this->mock(AuditLogger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('logWithResolvedContext')->once()->andThrow(new RuntimeException('audit unavailable'));
        });

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->post(route('admin.stars-pricing.update'), self::POLICY)
            ->assertServerError();

        $this->assertDatabaseHas('stars_pricing_policies', ['version' => 0, 'idr_per_usd' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_maximum_version_remains_readable_but_cannot_overflow(): void
    {
        DB::table('stars_pricing_policies')->update(['version' => PHP_INT_MAX]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->post(route('admin.stars-pricing.update'), ['version' => (string) PHP_INT_MAX] + self::POLICY)
            ->assertRedirect(route('admin.stars-pricing.show'))->assertSessionHasErrors('version');

        self::assertSame(PHP_INT_MAX, app(StarsPricingPolicyManager::class)->current()->version);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function assertAuditValues(array $expected, array $actual): void
    {
        // PostgreSQL JSONB can reorder object keys.
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }
}
