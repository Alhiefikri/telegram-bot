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

    public function test_direct_tabs_render_only_the_active_content_and_authorized_sidebar_destination(): void
    {
        Http::fake();
        $anchors = [
            'stats' => 'admin-section-stats',
            'produk' => 'admin-section-products',
            'penjualan' => 'admin-section-sales',
            'suppliers' => 'supplier-title',
            'broadcast' => 'admin-section-broadcast',
            'telegram-channel' => 'admin-section-store-channel',
            'riwayat' => 'admin-section-history',
            'withdraw' => 'admin-section-withdraw',
            'funnel' => 'admin-section-funnel',
            'balance-issues' => 'admin-section-balance-issues',
        ];

        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            foreach ($anchors as $tab => $anchor) {
                if ($role === UserRole::Admin && $tab === 'balance-issues') {
                    continue;
                }
                $response = $this->get(route('admin.dashboard', ['tab' => $tab]))->assertOk();
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                foreach ($xpath->query('//nav[@aria-label="Navigasi admin" or @aria-label="Navigasi dashboard"]') as $menu) {
                    $current = $xpath->query('.//a[@aria-current="page"]', $menu);
                    $this->assertSame(1, $current->length);
                    $this->assertSame(route('admin.dashboard', ['tab' => $tab]), $current->item(0)->getAttribute('href'));
                    $ownerLinks = $xpath->query('.//a[contains(@href, "/store-balance/adjustments") or contains(@href, "/store-balance/usdt-review") or contains(@href, "tab=balance-issues")]', $menu);
                    $this->assertSame($role === UserRole::Owner ? 3 : 0, $ownerLinks->length);
                }
                $this->assertSame(1, $xpath->query('//*[@id="admin-dashboard-content"]//*[@id="'.$anchor.'"]')->length);
                foreach ($anchors as $otherTab => $otherAnchor) {
                    if ($otherTab !== $tab) {
                        $this->assertSame(0, $xpath->query('//*[@id="'.$otherAnchor.'"]')->length);
                    }
                }
            }
        }
    }

    public function test_sidebar_fallback_links_preserve_filters_without_reopening_product_creation(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $response = $this->get('/admin?tab=produk&sales_filter=waiting&products_page=2&product_form=create')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $link = $xpath->query('//aside//a[contains(@href, "tab=penjualan")]')->item(0);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame(['tab' => 'penjualan', 'sales_filter' => 'waiting', 'products_page' => '2'], $query);
        $this->assertTrue($response->viewData('dashboardState')['productModalOpen']);
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
