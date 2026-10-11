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

    public function test_admin_navigation_keeps_capability_visible_items_and_hides_owner_only_destinations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $navigation = app(AdminDashboardData::class)->forActiveTab($admin, 'stats')['navigation'];
        $items = collect($navigation['groups'])->flatMap(fn (array $group): array => $group['items']);

        $this->assertTrue($items->contains('id', 'balance-reports'));
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
            'settings' => 'admin-section-settings',
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
            if ($tab === 'stats') {
                $this->assertSame(1, $xpath->query('//button[@aria-label="Ciutkan navigasi admin" and @aria-controls="admin-desktop-sidebar"]')->length);
                $this->assertSame(1, $xpath->query('//*[@id="admin-desktop-sidebar"]')->length);
                $this->assertSame(1, $xpath->query('//*[contains(@class, "admin-dashboard-frame")]')->length);
            }
        }
    }

    public function test_hidden_dashboard_tabs_remain_forbidden_at_the_controller_boundary(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin?tab=balance-issues')->assertForbidden();
        $this->actingAs($admin)->get('/admin?tab=unknown')->assertOk();
    }

    public function test_linked_finance_pages_mark_their_canonical_sidebar_item_active(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $data = app(AdminDashboardData::class);

        foreach (['balance-reports', 'balance-adjustments', 'usdt-review'] as $navigationId) {
            $navigation = $data->navigationFor('stats', $owner, $navigationId);
            $items = collect($navigation['groups'])->flatMap(fn (array $group): array => $group['items']);

            $this->assertSame([$navigationId], $items->where('active', true)->pluck('id')->all());
            $this->assertSame('stats', $navigation['activeTab']);
        }
    }
}
