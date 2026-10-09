<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminDashboardData;
use App\Services\Admin\AdminDashboardSalesData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_request_skips_inactive_tab_data_queries(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        Product::factory()->create();
        Order::factory()->create();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->get(route('admin.dashboard', ['tab' => 'stats']))->assertOk();

        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'product_stock_items')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'order_items')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'telegram_broadcasts')));
    }

    public function test_product_request_skips_inactive_stats_tab_queries(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        Product::factory()->create();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->get(route('admin.dashboard', ['tab' => 'produk']))->assertOk();

        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'manual_payment_settings')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sales_proof_posts')));
    }

    public function test_sakurupiah_sales_rows_eager_load_relations_without_n_plus_one(): void
    {
        foreach (range(1, 3) as $index) {
            $customer = Customer::factory()->create();
            $order = Order::factory()->for($customer)->create([
                'invoice_number' => 'INV-SAK-ADMIN-'.$index,
                'order_status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => 20_000,
            ]);
            OrderItem::factory()->for($order)->create([
                'product_name' => 'Sakurupiah product '.$index,
                'quantity' => 1,
                'unit_price' => 20_000,
                'subtotal' => 20_000,
            ]);
            Payment::factory()->for($order)->create([
                'provider' => 'sakurupiah',
                'method' => 'QRIS',
                'amount' => 20_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $data = app(AdminDashboardSalesData::class)->forSales([], false, 'all');

        $this->assertCount(3, $data['orders']);
        foreach (['customers', 'order_items', 'payments', 'sales_proof_posts', 'telegram_outbound_effects'] as $table) {
            $relationQueries = collect($queries)
                ->filter(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"') && str_contains($sql, ' in ('))
                ->values();

            $this->assertCount(1, $relationQueries, implode("\n", $queries));
            $this->assertTrue(
                $relationQueries->every(fn (string $sql): bool => str_contains($sql, ' in (')),
                implode("\n", $relationQueries->all()),
            );
        }
    }

    public function test_primary_admin_entry_uses_manual_dashboard_and_no_filament_routes(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $dashboardRoute = Route::getRoutes()->getByName('admin.dashboard');

        $this->assertNotNull($dashboardRoute);
        $this->assertSame(AdminDashboardController::class, $dashboardRoute->getControllerClass());
        $this->assertSame('admin', $dashboardRoute->uri());
        $this->assertNull(Route::getRoutes()->getByName('filament.admin.resources.products.index'));
        $this->assertNull(Route::getRoutes()->getByName('filament.admin.resources.orders.index'));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Navigasi dashboard')
            ->assertDontSee('fi-wi-widget', false)
            ->assertDontSee('filament.widgets', false)
            ->assertDontSee('fi-sidebar', false);
    }

    public function test_product_pagination_keeps_older_sold_out_and_paused_products_manageable(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $oldest = Product::factory()->create([
            'name' => 'Produk Lama Stok Kosong',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => false,
            'created_at' => now()->subDays(30),
        ]);

        Product::factory()->count(20)->sequence(
            fn ($sequence) => [
                'name' => 'Produk Baru '.($sequence->index + 1),
                'created_at' => now()->subMinutes(20 - $sequence->index),
            ],
        )->create();

        $this->get('/admin?tab=produk&sales_filter=waiting&source=telegram')
            ->assertOk()
            ->assertSee('Produk Baru 20')
            ->assertDontSee($oldest->name)
            ->assertSee('Halaman 1 dari 2')
            ->assertSee('products_page=2', false)
            ->assertSee('tab=produk', false)
            ->assertSee('sales_filter=waiting', false)
            ->assertSee('source=telegram', false);

        $this->get('/admin?tab=produk&products_page=2')
            ->assertOk()
            ->assertSee($oldest->name)
            ->assertSee('○ Tidak tampil')
            ->assertSee(route('admin.products.update', $oldest), false)
            ->assertSee('Halaman 2 dari 2')
            ->assertSee('products_page=1', false);
    }

    public function test_cutover_removes_filament_runtime_surface(): void
    {
        $composer = file_get_contents(base_path('composer.json'));
        $providers = file_get_contents(base_path('bootstrap/providers.php'));
        $viteConfig = file_get_contents(base_path('vite.config.js'));
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $appCss = file_get_contents(resource_path('css/app.css'));

        $this->assertFileDoesNotExist(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertDirectoryDoesNotExist(app_path('Filament'));
        $this->assertDirectoryDoesNotExist(resource_path('views/filament'));
        $this->assertStringNotContainsString('filament/filament', $composer);
        $this->assertStringNotContainsString('AdminPanelProvider', $providers);
        $this->assertStringNotContainsString('resources/css/filament/admin/theme.css', $viteConfig);
        $this->assertArrayHasKey('@tailwindcss/vite', $package['devDependencies']);
        $this->assertStringStartsWith('^4.', $package['devDependencies']['tailwindcss']);
        $this->assertStringContainsString('@import "tailwindcss"', $appCss);
        $this->assertFileDoesNotExist(base_path('tailwind.config.cjs'));
        $this->assertFileDoesNotExist(base_path('postcss.config.cjs'));
    }

    public function test_cutover_doc_records_blade_only_admin(): void
    {
        $cutoverDoc = base_path('../docs/admin-dashboard-cutover.md');

        $this->assertFileExists($cutoverDoc);

        $contents = file_get_contents($cutoverDoc);

        $this->assertStringContainsString('Blade-only', $contents);
        $this->assertStringContainsString('/admin', $contents);
        $this->assertStringNotContainsString('Filament stays installed', $contents);
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
            'description' => 'Produk baru siap kirim.',
            'delivery_note' => "Fitur utama:\n- Bonus setup\n- Garansi login\n\nPaket/spesifikasi:\n- Durasi 30 hari",
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
        $this->assertSame(1, $data['metrics']['completed_order_count']);
        $this->assertSame(205000, $data['metrics']['gmv_amount']);
        $this->assertSame(205000, $data['metrics']['read_only_balance_amount']);
        $this->assertSame(205000, $data['statsTab']['balance']['amount']);
        $this->assertSame('paid_payments', $data['statsTab']['balance']['source']);
        $this->assertSame(route('storefront.home'), $data['statsTab']['storeLink']['url']);
        $this->assertSame('manual', $data['statsTab']['paymentControl']['mode']);
        $this->assertSame('MANUAL', $data['statsTab']['paymentControl']['status_badge']['label']);
        $this->assertTrue($data['statsTab']['paymentControl']['can_manage']);
        $this->assertSame(['gmv', 'saldo', 'orders', 'sales_proof_posts', 'active_products'], array_column($data['statsTab']['cards'], 'key'));
        $this->assertSame(['blue', 'emerald', 'amber', 'emerald', 'emerald'], array_column($data['statsTab']['cards'], 'tone'));
        $this->assertArrayNotHasKey('card_class', $data['statsTab']['cards'][0]);
        $this->assertSame(['saldo', 'gmv', 'products', 'active_products', 'completed_orders'], array_column($data['statsTab']['summary'], 'key'));
        $this->assertSame('Pesanan', $data['statsTab']['summary'][4]['label']);
        $this->assertSame(1, $data['statsTab']['summary'][4]['value']);
        $this->assertSame('Order selesai', $data['statsTab']['summary'][4]['hint']);
        $this->assertSame(1, $data['statsTab']['statusCounts']['processing']['count']);
        $this->assertSame(1, $data['statsTab']['statusCounts']['completed']['count']);
        $this->assertSame(['pending_payment', 'processing', 'cancelled', 'expired'], array_keys($data['statsTab']['actionableStatusCounts']));
        $this->assertSame(['Semua', 'Selesai', 'Masalah', 'Menunggu', 'Diproses'], array_column($data['salesTab']['filters'], 'label'));
        $this->assertSame(3, $data['salesTab']['filters'][0]['count']);
        $this->assertSame(1, $data['salesTab']['filters'][1]['count']);
        $this->assertSame(1, $data['salesTab']['filters'][2]['count']);
        $this->assertSame(1, $data['salesTab']['filters'][4]['count']);
        $this->assertTrue($data['productsTab']['canManage']);
        $this->assertCount(3, $data['productsTab']['products']);
        $this->assertSame('Produk Baru', $data['productsTab']['products'][0]['name']);
        $this->assertSame(3, $data['productsTab']['products'][0]['available_stock']);
        $this->assertSame('Aktif', $data['productsTab']['products'][0]['status_label']);
        $this->assertSame('Produk Baru', $data['productsTab']['products'][0]['edit_payload']['name']);
        $this->assertSame('Produk baru siap kirim.', $data['productsTab']['products'][0]['edit_payload']['description']);
        $this->assertSame("Fitur utama:\n- Bonus setup\n- Garansi login\n\nPaket/spesifikasi:\n- Durasi 30 hari", $data['productsTab']['products'][0]['edit_payload']['deliveryNote']);
        $this->assertSame('INV-PROCESS', $data['salesTab']['orders'][0]['invoice_number']);
        $this->assertSame('Netflix Premium', $data['salesTab']['orders'][0]['product_name']);
        $this->assertSame(['all', 'processing'], $data['salesTab']['orders'][0]['filter_groups']);
        $this->assertSame('IDR 125.000', $data['salesTab']['orders'][0]['price']);
        $this->assertSame('IDR 125.000', $data['salesTab']['orders'][0]['earning']);
        $this->assertSame('emerald', $data['salesTab']['orders'][0]['payment_status_tone']);
        $this->assertArrayNotHasKey('payment_status_badge', $data['salesTab']['orders'][0]);
        $this->assertCount(7, $data['statsTab']['earnings']);
        $this->assertSame(205000, array_sum(array_column($data['statsTab']['earnings'], 'amount')));

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('escrow', $encoded);
    }

    public function test_dashboard_balance_and_earnings_do_not_mix_usdt_micro_units_into_idr(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);

        $idrOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 75000,
        ]);
        Payment::factory()->create([
            'order_id' => $idrOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 75000,
            'provider_fee' => 5000,
            'currency' => 'IDR',
            'paid_at' => now(),
        ]);

        $usdtOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 50000,
        ]);
        Payment::factory()->create([
            'order_id' => $usdtOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 5_000_000,
            'provider_fee' => 250_000,
            'currency' => 'USDT',
            'paid_at' => now(),
        ]);

        $data = app(AdminDashboardData::class)->forAdmin($admin);

        $this->assertSame(70000, $data['metrics']['read_only_balance_amount']);
        $this->assertSame(70000, $data['statsTab']['balance']['amount']);
        $this->assertSame('IDR 70.000', $data['statsTab']['balance']['amount_formatted']);
        $this->assertSame(70000, array_sum(array_column($data['statsTab']['earnings'], 'amount')));
        $this->assertStringNotContainsString('IDR 5.000.000', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_dashboard_earnings_use_provider_total_for_buyer_paid_qris_fees(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 35000,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'klikqris',
            'method' => 'QRIS',
            'provider_payment_method' => 'qris',
            'amount' => 35000,
            'provider_fee' => 227,
            'provider_total_payment' => 35227,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $data = app(AdminDashboardData::class)->forAdmin($admin);

        $this->assertSame(35000, $data['metrics']['read_only_balance_amount']);
        $this->assertSame(35000, array_sum(array_column($data['statsTab']['earnings'], 'amount')));
        $this->assertSame('IDR 35.000', $data['salesTab']['orders'][0]['earning']);
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
            ->assertSee('Bagikan')
            ->assertSee('Salin')
            ->assertSeeInOrder(['GMV', 'IDR 125.000'])
            ->assertSeeInOrder(['Saldo', 'IDR 125.000'])
            ->assertSeeInOrder(['Order', '2'])
            ->assertSeeInOrder(['Produk aktif', '2'])
            ->assertSee('Menunggu bayar')
            ->assertSee('Diproses')
            ->assertSee('Dibatalkan')
            ->assertSee('Expired')
            ->assertDontSee('Escrow');

        $content = $response->getContent();
        $statusSection = substr($content, strpos($content, 'aria-label="Status order"') ?: 0, 2000);
        $this->assertStringNotContainsString('Draft', $statusSection);
        $this->assertStringNotContainsString('Selesai', $statusSection);
    }
}
