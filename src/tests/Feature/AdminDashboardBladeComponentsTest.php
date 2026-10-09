<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;
class AdminDashboardBladeComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_components_render_with_merged_attributes_and_mobile_touch_targets(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin-dashboard.input name="product_name" value="Kaos" class="js-product-name" />
            <x-admin-dashboard.textarea name="notes" class="js-notes">Catatan</x-admin-dashboard.textarea>
            <x-admin-dashboard.select name="status" class="js-status">
                <option value="active">Aktif</option>
            </x-admin-dashboard.select>
            <x-admin-dashboard.field-label for="product_name" class="js-field-label">Nama produk</x-admin-dashboard.field-label>
            <x-admin-dashboard.field-error class="js-field-error">Nama wajib diisi.</x-admin-dashboard.field-error>
            <x-admin-dashboard.button variant="primary" class="js-primary">Simpan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="secondary" class="js-secondary">Bagikan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="neutral" class="js-neutral">Batal</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="warning" class="js-warning">Tahan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="destructive" class="js-destructive">Hapus</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="purple" size="sm" class="js-purple">Test</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="ink" size="icon" aria-label="Salin" class="js-icon" />
            <x-admin-dashboard.card class="js-card">Konten</x-admin-dashboard.card>
            <x-admin-dashboard.card tone="yellow" border="dashed" class="js-dashed-card">Kosong</x-admin-dashboard.card>
            <x-admin-dashboard.badge tone="emerald" class="js-badge">Aktif</x-admin-dashboard.badge>
            <x-admin-dashboard.badge background="#e8ffd4" color="#166534" dot dot-color="#7BD10A" class="js-dynamic-badge">Ready</x-admin-dashboard.badge>
            <x-admin-dashboard.notice tone="danger" class="js-notice">Periksa kembali field.</x-admin-dashboard.notice>
            <x-admin-dashboard.stat-card label="GMV" value="IDR 10.000" hint="Paid" tone="blue" size="compact" class="js-stat-card" />
            <x-admin-dashboard.mini-stat label="Stok" value="7" tone="cyan" class="js-mini-stat" />
            <x-admin-dashboard.tab tab-id="stats" active-tab="stats" class="js-tab">Stats</x-admin-dashboard.tab>
            <x-admin-dashboard.action-row href="/admin/products" class="js-action-row">Kelola produk</x-admin-dashboard.action-row>
            <x-admin-dashboard.modal-shell id="confirm-modal" show="modalOpen" title="Konfirmasi" class="js-modal">Isi modal</x-admin-dashboard.modal-shell>
            <x-admin-dashboard.modal-backdrop show="modalOpen" close="closeModal()" class="js-backdrop">
                <span>Isi backdrop</span>
            </x-admin-dashboard.modal-backdrop>
            <x-admin-dashboard.section-heading eyebrow="Stats" title="Ringkasan" class="js-heading">Copy</x-admin-dashboard.section-heading>
        BLADE);

        $this->assertStringContainsString('js-product-name', $rendered);
        $this->assertStringContainsString('js-notes', $rendered);
        $this->assertStringContainsString('js-status', $rendered);
        $this->assertStringContainsString('js-field-label', $rendered);
        $this->assertStringContainsString('js-field-error', $rendered);
        $this->assertStringContainsString('js-primary', $rendered);
        $this->assertStringContainsString('js-secondary', $rendered);
        $this->assertStringContainsString('js-neutral', $rendered);
        $this->assertStringContainsString('js-warning', $rendered);
        $this->assertStringContainsString('js-destructive', $rendered);
        $this->assertStringContainsString('js-purple', $rendered);
        $this->assertStringContainsString('js-icon', $rendered);
        $this->assertStringContainsString('js-card', $rendered);
        $this->assertStringContainsString('js-dashed-card', $rendered);
        $this->assertStringContainsString('js-badge', $rendered);
        $this->assertStringContainsString('js-dynamic-badge', $rendered);
        $this->assertStringContainsString('js-notice', $rendered);
        $this->assertStringContainsString('js-stat-card', $rendered);
        $this->assertStringContainsString('js-mini-stat', $rendered);
        $this->assertStringContainsString('js-tab', $rendered);
        $this->assertStringContainsString('js-action-row', $rendered);
        $this->assertStringContainsString('js-modal', $rendered);
        $this->assertStringContainsString('js-backdrop', $rendered);
        $this->assertStringContainsString('js-heading', $rendered);
        $this->assertStringContainsString('aria-labelledby="confirm-modal-title"', $rendered);
        $this->assertStringContainsString('x-show="modalOpen"', $rendered);
        $this->assertStringContainsString('x-on:click="closeModal()"', $rendered);
        $this->assertStringContainsString('min-h-11', $rendered);
        $this->assertStringContainsString('admin-dashboard-action', $rendered);
        $this->assertStringContainsString('bg-admin-accent', $rendered);
        $this->assertStringContainsString('background: #e8ffd4; color: #166534;', $rendered);
        $this->assertStringContainsString('background: #7BD10A', $rendered);
        $this->assertStringContainsString('rounded-[var(--admin-radius)]', $rendered);
        $this->assertStringContainsString('border-admin-line', $rendered);
        $this->assertStringContainsString('border-dashed', $rendered);
        $this->assertStringNotContainsString('fi-', $rendered);
    }

    public function test_shell_navigation_and_topbar_render_accessible_landmarks_and_escaped_content(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin-dashboard.shell class="custom-shell">
                <x-slot:sidebar>
                    <x-admin-dashboard.navigation :groups="$groups" class="custom-navigation" />
                </x-slot:sidebar>
                <x-slot:topbar>
                    <x-admin-dashboard.topbar title="Produk" context="Operasional" class="custom-topbar">
                        <x-slot:actions><x-admin-dashboard.button href="/admin/create">Tambah</x-admin-dashboard.button></x-slot:actions>
                    </x-admin-dashboard.topbar>
                </x-slot:topbar>
                <x-slot:mobileDrawer>
                    <x-admin-dashboard.mobile-drawer id="mobile-navigation">
                        <x-slot:close><button type="button" class="min-h-11 min-w-11" aria-label="Tutup navigasi">×</button></x-slot:close>
                        <x-admin-dashboard.navigation :groups="$groups" />
                    </x-admin-dashboard.mobile-drawer>
                </x-slot:mobileDrawer>
                <x-slot:content><p>Konten utama</p></x-slot:content>
            </x-admin-dashboard.shell>
        BLADE, ['groups' => [['id' => 'operations', 'label' => 'Operasional', 'items' => [
            ['id' => 'produk', 'label' => '<Produk>', 'href' => '/admin?tab=produk&filter=active', 'icon' => 'package', 'active' => true, 'badge' => '7'],
            ['id' => 'penjualan', 'label' => 'Pesanan', 'href' => '/admin?tab=penjualan', 'icon' => 'receipt', 'active' => false],
        ]]]]);

        $this->assertStringContainsString('custom-shell', $rendered);
        $this->assertStringContainsString('custom-navigation', $rendered);
        $this->assertStringContainsString('custom-topbar', $rendered);
        $this->assertStringContainsString('aria-label="Navigasi admin"', $rendered);
        $this->assertStringContainsString('aria-current="page"', $rendered);
        $this->assertStringContainsString('href="/admin?tab=produk&amp;filter=active"', $rendered);
        $this->assertStringContainsString('&lt;Produk&gt;', $rendered);
        $this->assertStringContainsString('min-h-11', $rendered);
        $this->assertStringContainsString('id="mobile-navigation"', $rendered);
        $this->assertStringContainsString('role="dialog"', $rendered);
        $this->assertStringContainsString('aria-label="Tutup navigasi"', $rendered);
        $this->assertStringContainsString('w-[min(320px,calc(100vw-32px))]', $rendered);
        $this->assertStringContainsString('id="admin-dashboard-content"', $rendered);
        $this->assertStringContainsString('Konten utama', $rendered);
        $this->assertStringContainsString('>Pesanan</span>', $rendered);
    }
    public function test_linked_operational_shell_uses_canonical_navigation_and_dashboard_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $rendered = Blade::render(
            '<x-admin-dashboard.linked-shell active-tab="suppliers" title="Katalog privat"><p>Konten supplier</p></x-admin-dashboard.linked-shell>',
        );

        $this->assertStringContainsString('x-data="dashboardNavigation()"', $rendered);
        $this->assertStringContainsString('tab=suppliers', $rendered);
        $this->assertStringContainsString('aria-label="Navigasi admin"', $rendered);
        $this->assertStringContainsString('aria-label="Navigasi dashboard"', $rendered);
        $this->assertStringContainsString('Katalog privat', $rendered);
        $this->assertStringContainsString('Konten supplier', $rendered);
        $this->assertStringNotContainsString('api_key', $rendered);
    }
}
