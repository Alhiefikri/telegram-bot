<?php

namespace Tests\Feature;

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
            <x-admin-dashboard.modal-shell id="confirm-modal" title="Konfirmasi" class="js-modal">Isi modal</x-admin-dashboard.modal-shell>
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
        $this->assertStringContainsString('min-h-12', $rendered);
        $this->assertStringContainsString('min-h-11', $rendered);
        $this->assertStringContainsString('h-9', $rendered);
        $this->assertStringContainsString('admin-dashboard-action', $rendered);
        $this->assertStringContainsString('bg-[#7BD10A]', $rendered);
        $this->assertStringContainsString('bg-[#16B4CA]', $rendered);
        $this->assertStringContainsString('bg-[#f2da3d]', $rendered);
        $this->assertStringContainsString('bg-[#ef4444]', $rendered);
        $this->assertStringContainsString('bg-[#8758de]', $rendered);
        $this->assertStringContainsString('background: #e8ffd4; color: #166534;', $rendered);
        $this->assertStringContainsString('background: #7BD10A', $rendered);
        $this->assertStringContainsString('rounded-[1.25rem]', $rendered);
        $this->assertStringContainsString('border-[3px]', $rendered);
        $this->assertStringContainsString('border-dashed', $rendered);
        $this->assertStringNotContainsString('fi-', $rendered);
    }
}
