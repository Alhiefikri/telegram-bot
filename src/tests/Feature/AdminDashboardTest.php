<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Admin\AdminDashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_user_without_admin_panel_access_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => null]));

        $this->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_dashboard_shell_renders_manual_blade_tabs_without_sidebar_or_escrow(): void
    {
        $this->actingAs(User::factory()->create([
            'name' => 'Lhie Admin',
            'role' => UserRole::Owner,
        ]));

        Product::factory()->count(3)->create(['is_active' => true]);
        Product::factory()->create(['is_active' => false]);
        Order::factory()->create(['order_status' => OrderStatus::Processing, 'total_amount' => 125000]);

        $response = $this->get('/admin')
            ->assertOk()
            ->assertSee('LHIE STORE')
            ->assertSee('Lhie Admin')
            ->assertSee('Saldo')
            ->assertSee('GMV')
            ->assertSee('Produk')
            ->assertSee('Perlu diproses')
            ->assertSee('Belanja Produk Lain')
            ->assertSee('Bagikan Toko')
            ->assertSeeInOrder(['Stats', 'Produk', 'Penjualan', 'Tambah', 'Withdraw', 'Riwayat', 'Affiliate'])
            ->assertSee('overflow-x-auto', false)
            ->assertSee('x-data', false)
            ->assertDontSee('Escrow')
            ->assertDontSee('fi-sidebar', false);

        $this->assertStringNotContainsString('<aside', $response->getContent());
    }

    public function test_primary_admin_entry_uses_manual_dashboard_while_filament_resources_remain_reachable(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $dashboardRoute = Route::getRoutes()->getByName('admin.dashboard');

        $this->assertNotNull($dashboardRoute);
        $this->assertSame(AdminDashboardController::class, $dashboardRoute->getControllerClass());
        $this->assertSame('admin', $dashboardRoute->uri());
        $this->assertSame('/admin/products', parse_url(route('filament.admin.resources.products.index'), PHP_URL_PATH));
        $this->assertSame('/admin/orders', parse_url(route('filament.admin.resources.orders.index'), PHP_URL_PATH));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Navigasi dashboard')
            ->assertDontSee('fi-wi-widget', false)
            ->assertDontSee('filament.widgets', false);

        $this->get(route('filament.admin.resources.products.index'))
            ->assertOk();
    }

    public function test_owner_can_toggle_payment_mode_from_manual_dashboard(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $setting = ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Mode pembayaran')
            ->assertSee('Pakasir aktif')
            ->assertSee('Manual /payment')
            ->assertDontSee('Pakasir dimatikan sementara');

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'manual'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']));

        $this->assertDatabaseHas('manual_payment_settings', [
            'id' => $setting->id,
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_mode.updated',
            'subject_type' => ManualPaymentSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Manual aktif')
            ->assertSee('Checkout meminta bukti /payment');
    }

    public function test_payment_mode_toggle_creates_active_setting_when_none_exists(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
        ]));

        $this->assertSame(0, ManualPaymentSetting::query()->count());

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'manual'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'stats']));

        $this->assertDatabaseHas('manual_payment_settings', [
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_non_manager_role_cannot_toggle_payment_mode(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Finance,
        ]));

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Hanya owner/admin yang bisa mengubah mode pembayaran.');

        $this->post(route('admin.payment-mode.update'), ['payment_mode' => 'pakasir'])
            ->assertForbidden();

        $this->assertDatabaseMissing('manual_payment_settings', [
            'payment_mode' => 'pakasir',
        ]);
    }

    public function test_cutover_removes_filament_dashboard_widgets_and_theme_registration(): void
    {
        $panelProvider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $viteConfig = file_get_contents(base_path('vite.config.js'));

        $this->assertFileDoesNotExist(app_path('Filament/Pages/Dashboard.php'));
        $this->assertSame([], glob(app_path('Filament/Widgets/*.php')) ?: []);
        $this->assertStringContainsString('->pages([])', $panelProvider);
        $this->assertStringNotContainsString('discoverWidgets', $panelProvider);
        $this->assertStringNotContainsString('->widgets(', $panelProvider);
        $this->assertStringNotContainsString('viteTheme', $panelProvider);
        $this->assertStringNotContainsString('resources/css/filament/admin/theme.css', $viteConfig);
    }

    public function test_cutover_rollback_path_is_documented(): void
    {
        $rollbackDoc = base_path('../docs/admin-dashboard-cutover.md');

        $this->assertFileExists($rollbackDoc);

        $contents = file_get_contents($rollbackDoc);

        $this->assertStringContainsString('Rollback', $contents);
        $this->assertStringContainsString('/admin', $contents);
        $this->assertStringContainsString('App\\Filament\\Pages\\Dashboard', $contents);
        $this->assertStringContainsString('resources/css/filament/admin/theme.css', $contents);
    }

    public function test_admin_dashboard_data_provider_returns_operational_view_model_without_escrow(): void
    {
        $admin = User::factory()->create([
            'name' => 'Lhie Admin',
            'role' => UserRole::Owner,
        ]);

        Product::factory()->create([
            'name' => 'Produk Lama',
            'price_amount' => 35000,
            'stock' => 9,
            'reserved_stock' => 2,
            'is_active' => true,
            'created_at' => now()->subDays(3),
        ]);
        Product::factory()->create([
            'name' => 'Produk Nonaktif',
            'is_active' => false,
            'created_at' => now()->subDays(2),
        ]);
        Product::factory()->create([
            'name' => 'Produk Baru',
            'price_amount' => 50000,
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
            'created_at' => now()->subDay(),
        ]);

        $processingOrder = Order::factory()->create([
            'invoice_number' => 'INV-PROCESS',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 125000,
            'created_at' => now()->subHours(3),
        ]);
        OrderItem::factory()->create([
            'order_id' => $processingOrder->id,
            'product_id' => null,
            'product_name' => 'Netflix Premium',
            'subtotal' => 125000,
        ]);
        $completedOrder = Order::factory()->create([
            'invoice_number' => 'INV-DONE',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 80000,
            'created_at' => now()->subDay(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'product_id' => null,
            'product_name' => 'Canva Pro',
            'subtotal' => 80000,
        ]);
        Order::factory()->create([
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
            'total_amount' => 30000,
            'created_at' => now()->subDays(2),
        ]);

        Payment::factory()->create([
            'order_id' => $processingOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 125000,
            'paid_at' => now()->subHours(2),
        ]);
        Payment::factory()->create([
            'order_id' => $completedOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 80000,
            'paid_at' => now()->subDay(),
        ]);
        Payment::factory()->create([
            'order_id' => $completedOrder->id,
            'status' => PaymentStatus::Pending,
            'amount' => 99000,
            'paid_at' => null,
        ]);

        $data = app(AdminDashboardData::class)->forAdmin($admin);

        $this->assertSame('Lhie Admin', $data['admin']['name']);
        $this->assertSame(3, $data['metrics']['product_count']);
        $this->assertSame(2, $data['metrics']['active_product_count']);
        $this->assertSame(3, $data['metrics']['order_count']);
        $this->assertSame(1, $data['metrics']['orders_to_process']);
        $this->assertSame(205000, $data['metrics']['gmv_amount']);
        $this->assertSame(205000, $data['metrics']['read_only_balance_amount']);
        $this->assertSame(205000, $data['balance']['amount']);
        $this->assertSame('paid_payments', $data['balance']['source']);
        $this->assertSame(route('storefront.home'), $data['storeLink']['url']);
        $this->assertSame('manual', $data['paymentControl']['mode']);
        $this->assertFalse($data['paymentControl']['is_pakasir_enabled']);
        $this->assertTrue($data['paymentControl']['can_manage']);
        $this->assertSame(['gmv', 'saldo', 'orders', 'active_products'], array_column($data['statsCards'], 'key'));
        $this->assertSame(1, $data['statusCounts']['processing']['count']);
        $this->assertSame(1, $data['statusCounts']['completed']['count']);
        $this->assertSame(['pending_payment', 'processing', 'cancelled', 'expired'], array_keys($data['actionableStatusCounts']));
        $this->assertSame(['Semua', 'Selesai', 'Masalah', 'Menunggu', 'Diproses'], array_column($data['salesFilters'], 'label'));
        $this->assertSame(3, $data['salesFilters'][0]['count']);
        $this->assertSame(1, $data['salesFilters'][1]['count']);
        $this->assertSame(1, $data['salesFilters'][2]['count']);
        $this->assertSame(1, $data['salesFilters'][4]['count']);
        $this->assertCount(3, $data['products']);
        $this->assertSame('Produk Baru', $data['products'][0]['name']);
        $this->assertSame(3, $data['products'][0]['available_stock']);
        $this->assertSame('INV-PROCESS', $data['orders'][0]['invoice_number']);
        $this->assertSame('Netflix Premium', $data['orders'][0]['product_name']);
        $this->assertSame(['all', 'processing'], $data['orders'][0]['filter_groups']);
        $this->assertSame('IDR 125.000', $data['orders'][0]['price']);
        $this->assertSame('IDR 125.000', $data['orders'][0]['earning']);
        $this->assertSame('emerald', $data['orders'][0]['payment_status_tone']);
        $this->assertCount(7, $data['earnings']);
        $this->assertSame(205000, array_sum(array_column($data['earnings'], 'amount')));

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('escrow', $encoded);
    }

    public function test_stats_tab_renders_store_link_actions_cards_statuses_and_css_earnings(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        Product::factory()->count(2)->create(['is_active' => true]);
        Product::factory()->create(['is_active' => false]);
        Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 45000,
        ]);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 125000,
        ]);
        Payment::factory()->create([
            'order_id' => $paidOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 125000,
            'paid_at' => now(),
        ]);

        $response = $this->get('/admin?tab=stats')
            ->assertOk()
            ->assertSee('Link toko')
            ->assertSee(route('storefront.home'), false)
            ->assertSee('Share')
            ->assertSee('Copy')
            ->assertSee('copyStoreLink', false)
            ->assertSee('shareStore', false)
            ->assertSee('navigator.share', false)
            ->assertSee('navigator.clipboard', false)
            ->assertSee('window.prompt', false)
            ->assertSeeInOrder(['GMV', 'IDR 125.000'])
            ->assertSeeInOrder(['Saldo', 'IDR 125.000'])
            ->assertSeeInOrder(['Order', '2'])
            ->assertSeeInOrder(['Produk aktif', '2'])
            ->assertSee('Menunggu bayar')
            ->assertSee('Diproses')
            ->assertSee('Dibatalkan')
            ->assertSee('Expired')
            ->assertSee('style="width:', false)
            ->assertDontSee('chart.js', false)
            ->assertDontSee('apexcharts', false)
            ->assertDontSee('Escrow');

        $content = $response->getContent();
        $statusSection = substr($content, strpos($content, 'aria-label="Status order"') ?: 0, 2000);
        $this->assertStringNotContainsString('Draft', $statusSection);
        $this->assertStringNotContainsString('Selesai', $statusSection);
    }

    public function test_produk_tab_renders_compact_product_cards_actions_and_stock_modal(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $category = Category::factory()->create(['name' => 'Akun Premium']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Netflix 1 Bulan',
            'price_amount' => 45000,
            'stock' => 9,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Katalog siap dikelola')
            ->assertSee('Netflix 1 Bulan')
            ->assertSee('Akun Premium')
            ->assertSeeInOrder(['Stok', '9'])
            ->assertSeeInOrder(['Tersedia', '7'])
            ->assertSee('IDR 45.000')
            ->assertSee('Aktif')
            ->assertSeeInOrder(['Edit', 'Stok', 'Kelola', 'Share', 'Pause', 'Hapus'])
            ->assertSee('Tambah Stok')
            ->assertSee('0 item terdeteksi')
            ->assertSee('Tempel stok baru, satu item per baris', false)
            ->assertSee('grid gap-3 sm:grid-cols-2 xl:grid-cols-3', false)
            ->assertSee('w-full min-w-0', false)
            ->assertSee('bg-rose-50', false)
            ->assertDontSee('@js(', false)
            ->assertDontSee('fi-form', false)
            ->assertDontSee('fi-modal', false);
    }

    public function test_penjualan_tab_renders_compact_order_cards_with_operational_filters(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $customer = Customer::factory()->create([
            'first_name' => 'Budi',
            'username' => 'budilanjut',
        ]);
        $completedOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DONE',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 75000,
            'created_at' => Carbon::parse('2026-06-29 10:15:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'product_id' => null,
            'product_name' => 'ChatGPT Plus 1 Bulan',
            'quantity' => 1,
            'unit_price' => 75000,
            'subtotal' => 75000,
        ]);
        Payment::factory()->create([
            'order_id' => $completedOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 70000,
            'paid_at' => Carbon::parse('2026-06-29 10:20:00'),
        ]);

        $cancelledOrder = Order::factory()->create([
            'invoice_number' => 'INV-ISSUE',
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
            'total_amount' => 45000,
            'created_at' => Carbon::parse('2026-06-28 09:00:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $cancelledOrder->id,
            'product_id' => null,
            'product_name' => 'Canva Pro',
            'subtotal' => 45000,
        ]);

        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee('Order masuk dan diproses')
            ->assertSeeInOrder(['Semua', 'Selesai', 'Masalah', 'Menunggu', 'Diproses'])
            ->assertSee('salesFilter', false)
            ->assertSee("salesFilter = 'completed'", false)
            ->assertSee('data-order-filter-groups="all completed"', false)
            ->assertSee('data-order-filter-groups="all issue"', false)
            ->assertSee('grid gap-3 lg:grid-cols-2', false)
            ->assertSee('INV-DONE')
            ->assertSee('ChatGPT Plus 1 Bulan')
            ->assertSee('IDR 75.000')
            ->assertSee('IDR 70.000')
            ->assertSee('Budi (@budilanjut)')
            ->assertSee('29 Jun 2026')
            ->assertSee('Selesai')
            ->assertSee('Masalah')
            ->assertDontSee('Escrow')
            ->assertDontSee('<table', false);
    }

    public function test_tambah_tab_renders_compact_product_create_form(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        Category::factory()->create(['name' => 'Akun Premium']);

        $this->get('/admin?tab=tambah')
            ->assertOk()
            ->assertSee('Tambah produk baru')
            ->assertSee('Nama produk')
            ->assertSee('Harga')
            ->assertSee('Kategori')
            ->assertSee('Deskripsi singkat')
            ->assertSee('Fitur utama')
            ->assertSee('Paket/spesifikasi')
            ->assertSee('Akun Premium')
            ->assertSee('Submit Produk')
            ->assertSee('/admin/products', false)
            ->assertSee('x-data', false)
            ->assertSee('min-w-0', false)
            ->assertDontSee('fi-form', false);
    }

    public function test_admin_can_create_product_from_tambah_tab(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Tools Premium']);

        $this->actingAs($admin)
            ->from('/admin?tab=tambah')
            ->post(route('admin.products.store'), [
                'name' => 'Canva Pro 1 Bulan',
                'price_amount' => '75000',
                'category_id' => (string) $category->id,
                'description' => 'Akses Canva Pro siap pakai.',
                'features' => "Akun ready\nGaransi login",
                'specifications' => "Durasi 30 hari\nSupport reset",
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $this->assertDatabaseHas('products', [
            'category_id' => $category->id,
            'name' => 'Canva Pro 1 Bulan',
            'price_amount' => 75000,
            'currency' => 'IDR',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $product = Product::where('name', 'Canva Pro 1 Bulan')->firstOrFail();
        $this->assertStringContainsString('Akses Canva Pro siap pakai.', $product->description);
        $this->assertStringContainsString("Fitur utama:\n- Akun ready\n- Garansi login", $product->description);
        $this->assertStringContainsString("Paket/spesifikasi:\n- Durasi 30 hari\n- Support reset", $product->description);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.created',
            'subject_id' => (string) $product->id,
        ]);
    }

    public function test_tambah_product_submission_validates_required_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=tambah')
            ->post(route('admin.products.store'), [
                'name' => '',
                'price_amount' => '',
                'description' => '',
            ])
            ->assertRedirect('/admin?tab=tambah')
            ->assertSessionHasErrors(['name', 'price_amount', 'description'], null, 'productCreate');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_admin_can_add_product_stock_from_compact_dashboard_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Canva Pro',
            'stock' => 2,
            'reserved_stock' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "akun-1@example.test\n\nakun-2@example.test\nakun-3@example.test",
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(3, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame('akun-1@example.test', ProductStockItem::where('product_id', $product->id)->orderBy('id')->firstOrFail()->secret);
        $this->assertStringNotContainsString('akun-1@example.test', (string) InventoryMovement::where('product_id', $product->id)->latest('id')->firstOrFail()->notes);
        $this->assertStringNotContainsString('akun-1@example.test', (string) json_encode(AuditLog::where('subject_id', (string) $product->id)->latest('id')->firstOrFail()->new_values));
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::Adjustment->value,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.stock_adjusted',
            'subject_id' => (string) $product->id,
        ]);
    }

    public function test_stock_modal_submission_requires_at_least_one_non_empty_row(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create(['stock' => 2]);

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "\n  \n",
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['stock_items'], null, 'stockAdjustment');

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_admin_dashboard_components_render_with_merged_attributes_and_mobile_touch_targets(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin-dashboard.input name="product_name" value="Kaos" class="js-product-name" />
            <x-admin-dashboard.textarea name="notes" class="js-notes">Catatan</x-admin-dashboard.textarea>
            <x-admin-dashboard.select name="status" class="js-status">
                <option value="active">Aktif</option>
            </x-admin-dashboard.select>
            <x-admin-dashboard.button variant="primary" class="js-primary">Simpan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="secondary" class="js-secondary">Bagikan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="neutral" class="js-neutral">Batal</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="warning" class="js-warning">Tahan</x-admin-dashboard.button>
            <x-admin-dashboard.button variant="destructive" class="js-destructive">Hapus</x-admin-dashboard.button>
            <x-admin-dashboard.card class="js-card">Konten</x-admin-dashboard.card>
            <x-admin-dashboard.badge tone="emerald" class="js-badge">Aktif</x-admin-dashboard.badge>
            <x-admin-dashboard.tab tab-id="stats" active-tab="stats" class="js-tab">Stats</x-admin-dashboard.tab>
            <x-admin-dashboard.action-row href="/admin/products" class="js-action-row">Kelola produk</x-admin-dashboard.action-row>
            <x-admin-dashboard.modal-shell id="confirm-modal" title="Konfirmasi" class="js-modal">Isi modal</x-admin-dashboard.modal-shell>
            <x-admin-dashboard.section-heading eyebrow="Stats" title="Ringkasan" class="js-heading">Copy</x-admin-dashboard.section-heading>
        BLADE);

        $this->assertStringContainsString('js-product-name', $rendered);
        $this->assertStringContainsString('js-notes', $rendered);
        $this->assertStringContainsString('js-status', $rendered);
        $this->assertStringContainsString('js-primary', $rendered);
        $this->assertStringContainsString('js-secondary', $rendered);
        $this->assertStringContainsString('js-neutral', $rendered);
        $this->assertStringContainsString('js-warning', $rendered);
        $this->assertStringContainsString('js-destructive', $rendered);
        $this->assertStringContainsString('js-card', $rendered);
        $this->assertStringContainsString('js-badge', $rendered);
        $this->assertStringContainsString('js-tab', $rendered);
        $this->assertStringContainsString('js-action-row', $rendered);
        $this->assertStringContainsString('js-modal', $rendered);
        $this->assertStringContainsString('js-heading', $rendered);
        $this->assertStringContainsString('aria-labelledby="confirm-modal-title"', $rendered);
        $this->assertStringContainsString('min-h-11', $rendered);
        $this->assertStringContainsString('bg-brand-electric', $rendered);
        $this->assertStringContainsString('bg-brand-electricLight', $rendered);
        $this->assertStringContainsString('bg-white', $rendered);
        $this->assertStringContainsString('bg-amber-50', $rendered);
        $this->assertStringContainsString('bg-rose-50', $rendered);
        $this->assertStringNotContainsString('fi-', $rendered);
    }
}
