<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Admin\AdminDashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminDashboardNavigationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_navigation_is_grouped_deterministic_and_maps_all_dashboard_tabs(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $navigation = app(AdminDashboardData::class)->forActiveTab($owner, 'produk')['navigation'];

        $this->assertSame('produk', $navigation['activeTab']);
        $this->assertSame(
            ['stats', 'produk', 'penjualan', 'suppliers', 'broadcast', 'telegram-channel', 'riwayat', 'withdraw', 'funnel', 'balance-issues'],
            array_column($navigation['tabs'], 'id'),
        );
        $this->assertSame(
            ['primary', 'operations', 'finance', 'communications', 'insights-system'],
            array_column($navigation['groups'], 'id'),
        );

        $items = collect($navigation['groups'])->flatMap(fn (array $group): array => $group['items']);
        $this->assertSame(
            ['stats', 'penjualan', 'produk', 'suppliers', 'balance-reports', 'balance-adjustments', 'usdt-review', 'balance-issues', 'riwayat', 'withdraw', 'broadcast', 'telegram-channel', 'funnel', 'payment-settings'],
            $items->pluck('id')->all(),
        );
        $this->assertSame(['produk'], $items->where('active', true)->pluck('id')->all());
        $this->assertSame('package', $items->firstWhere('id', 'produk')['icon']);
        $this->assertSame(
            route('admin.dashboard', ['tab' => 'produk', 'product_form' => 'create']),
            $items->firstWhere('id', 'produk')['action']['href'],
        );
        $this->assertStringEndsWith('#admin-payment-settings', $items->firstWhere('id', 'payment-settings')['href']);
        $this->assertFalse($items->firstWhere('id', 'payment-settings')['active']);
    }

    public function test_admin_navigation_keeps_capability_visible_items_and_hides_owner_only_destinations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $navigation = app(AdminDashboardData::class)->forActiveTab($admin, 'stats')['navigation'];
        $items = collect($navigation['groups'])->flatMap(fn (array $group): array => $group['items']);

        $this->assertTrue($items->contains('id', 'balance-reports'));
        $this->assertTrue($items->contains('id', 'withdraw'));
        $this->assertTrue($items->contains('id', 'funnel'));
        $this->assertFalse($items->contains('id', 'balance-adjustments'));
        $this->assertFalse($items->contains('id', 'usdt-review'));
        $this->assertFalse($items->contains('id', 'balance-issues'));
        $this->assertSame('stats', $navigation['activeTab']);
    }

    public function test_owner_and_admin_navigation_contract_covers_every_permitted_tab_key(): void
    {
        Http::fake();
        $tabKeys = ['stats', 'produk', 'penjualan', 'suppliers', 'broadcast', 'telegram-channel', 'riwayat', 'withdraw', 'funnel'];

        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($tabKeys as $tab) {
                $navigation = app(AdminDashboardData::class)->forActiveTab($user, $tab)['navigation'];

                $this->assertSame($tab, $navigation['activeTab'], "{$role->value} should retain {$tab} as active.");
                $this->assertContains($tab, array_column($navigation['tabs'], 'id'));
            }

            if ($role === UserRole::Owner) {
                $navigation = app(AdminDashboardData::class)->forActiveTab($user, 'balance-issues')['navigation'];
                $this->assertSame('balance-issues', $navigation['activeTab']);
            }
        }
    }

    public function test_hidden_dashboard_tabs_remain_forbidden_at_the_controller_boundary(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin?tab=balance-issues')->assertForbidden();
        $this->actingAs($admin)->get('/admin?tab=unknown')->assertOk();
    }

    public function test_navigation_contract_does_not_load_inactive_reporting_data(): void
    {
        Http::fake();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $data = app(AdminDashboardData::class)->forActiveTab($owner, 'stats');

        $this->assertSame('inactive', $data['historyTab']['state']);
        $this->assertSame('inactive', $data['withdrawTab']['state']);
        Http::assertNothingSent();
    }
}
