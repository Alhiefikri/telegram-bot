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
use Illuminate\Support\Carbon;
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
        $this->assertSame(route('storefront.home'), $data['statsTab']['storeLink']['url']);
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
        $this->assertSame(205000, array_sum(array_column($data['statsTab']['overview']['period']['points'], 'amount')));

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
        $this->assertSame(70000, array_sum(array_column($data['statsTab']['overview']['period']['points'], 'amount')));
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
        $this->assertSame(35000, array_sum(array_column($data['statsTab']['overview']['period']['points'], 'amount')));
        $this->assertSame('IDR 35.000', $data['salesTab']['orders'][0]['earning']);
    }

    public function test_daily_period_uses_canonical_paid_dates_and_separate_currency_windows(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $oldOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create([
            'order_id' => $oldOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 1000,
            'currency' => 'IDR',
            'paid_at' => '2026-10-14 12:00:00',
        ]);
        Payment::factory()->create([
            'order_id' => $oldOrder->id,
            'status' => PaymentStatus::Paid,
            'amount' => 1000,
            'currency' => 'IDR',
            'paid_at' => '2026-10-20 12:00:00',
        ]);

        foreach ([
            ['2026-10-15 00:00:00', 'IDR', 10000, 1000, OrderStatus::Completed],
            ['2026-10-20 12:00:00', 'IDR', 18000, 0, OrderStatus::Processing],
            ['2026-10-21 10:00:00', 'USDT', 2125000, 125000, OrderStatus::Completed],
            ['2026-10-21 11:00:00', 'IDR', 75000, 0, OrderStatus::Cancelled],
            ['2026-10-21 13:00:00', 'IDR', 25000, 0, OrderStatus::Completed],
        ] as [$paidAt, $currency, $amount, $fee, $status]) {
            $order = Order::factory()->create(['order_status' => $status, 'payment_status' => PaymentStatus::Paid]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'status' => PaymentStatus::Paid,
                'amount' => $amount,
                'provider_fee' => $fee,
                'currency' => $currency,
                'paid_at' => $paidAt,
            ]);
        }

        $dashboard = app(AdminDashboardData::class);
        $idr = $dashboard->forActiveTab($owner, 'stats')['statsTab']['overview'];
        $period = $idr['period'];
        $this->assertSame('harian', $period['mode']);
        $this->assertSame(7, $period['days']);
        $this->assertSame('day', $period['granularity']);
        $this->assertSame('7 hari terakhir', $period['window_label']);
        $this->assertSame('15 Oct 2026 – 21 Oct 2026', $period['range_label']);
        $this->assertCount(7, $period['points']);
        $this->assertSame([
            '2026-10-15' => 9000,
            '2026-10-16' => 0,
            '2026-10-17' => 0,
            '2026-10-18' => 0,
            '2026-10-19' => 0,
            '2026-10-20' => 18000,
            '2026-10-21' => 0,
        ], array_column($period['points'], 'amount', 'key'));
        $points = array_column($period['points'], null, 'key');
        $this->assertSame(50.0, $points['2026-10-15']['height']);
        $this->assertSame(100.0, $points['2026-10-20']['height']);
        $this->assertTrue($points['2026-10-20']['is_peak']);
        $this->assertFalse($points['2026-10-15']['is_peak']);
        $this->assertTrue($points['2026-10-21']['is_current']);
        $this->assertSame('Rp27.000', $period['total']);
        $this->assertSame(2, $period['orders']);
        $this->assertSame('20 Oct 2026', $period['peak']['label']);
        $this->assertSame('Rp18.000', $period['peak']['formatted']);
        $this->assertSame(66.7, $points['2026-10-20']['share']);
        $this->assertSame(1, $idr['today_orders']);
        $this->assertSame('Rp0', $idr['today_amount']);

        $usdt = $dashboard->forActiveTab($owner, 'stats', reportingFilters: ['currency' => 'USDT'])['statsTab']['overview'];
        $this->assertSame(2000000, $usdt['period']['total_amount']);
        $this->assertSame(1, $usdt['today_orders']);

        $month = $dashboard->forActiveTab($owner, 'stats', reportingFilters: ['days' => '30'])['statsTab']['overview'];
        $this->assertSame(30, $month['period']['days']);
        $this->assertSame(28000, $month['period']['total_amount']);
        $this->assertSame(1000, array_column($month['period']['points'], 'amount', 'key')['2026-10-14']);
    }

    public function test_weekly_period_buckets_canonical_receipts_into_monday_weeks(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        foreach ([
            ['2026-08-05 09:00:00', 5000],
            ['2026-09-30 09:00:00', 4000],
            ['2026-10-13 09:00:00', 1000],
            ['2026-10-19 09:00:00', 2000],
            ['2026-10-21 09:00:00', 3000],
        ] as [$paidAt, $amount]) {
            $order = Order::factory()->create([
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'status' => PaymentStatus::Paid,
                'amount' => $amount,
                'currency' => 'IDR',
                'paid_at' => $paidAt,
            ]);
        }

        $period = app(AdminDashboardData::class)
            ->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'mingguan'])['statsTab']['overview']['period'];

        $this->assertSame('mingguan', $period['mode']);
        $this->assertSame('week', $period['granularity']);
        $this->assertSame('8 minggu terakhir', $period['window_label']);
        $this->assertSame('31 Aug 2026 – 21 Oct 2026', $period['range_label']);
        $this->assertSame([
            '2026-08-31' => 0,
            '2026-09-07' => 0,
            '2026-09-14' => 0,
            '2026-09-21' => 0,
            '2026-09-28' => 4000,
            '2026-10-05' => 0,
            '2026-10-12' => 1000,
            '2026-10-19' => 5000,
        ], array_column($period['points'], 'amount', 'key'));
        $this->assertSame(10000, $period['total_amount']);
        $this->assertSame(4, $period['orders']);
        $this->assertSame('Minggu berjalan masih berlangsung', $period['partial_label']);
        $this->assertTrue(array_column($period['points'], 'is_current', 'key')['2026-10-19']);
        $this->assertFalse(array_column($period['points'], 'is_current', 'key')['2026-10-12']);
        $this->assertSame('12 Oct – 18 Oct 2026', array_column($period['points'], 'range_label', 'key')['2026-10-12']);
    }

    public function test_monthly_period_aggregates_months_and_compares_current_previous_and_selected_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        foreach ([
            ['2026-02-03 09:00:00', 1000],
            ['2026-08-05 09:00:00', 2000],
            ['2026-08-19 09:00:00', 3000],
            ['2026-09-15 09:00:00', 4000],
            ['2026-10-02 09:00:00', 5000],
            ['2026-10-21 09:00:00', 6000],
        ] as [$paidAt, $amount]) {
            $order = Order::factory()->create([
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'status' => PaymentStatus::Paid,
                'amount' => $amount,
                'currency' => 'IDR',
                'paid_at' => $paidAt,
            ]);
        }

        $overview = app(AdminDashboardData::class)
            ->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'bulanan'])['statsTab']['overview'];
        $period = $overview['period'];

        $this->assertSame('bulanan', $period['mode']);
        $this->assertSame('month', $period['granularity']);
        $this->assertSame('12 bulan terakhir', $period['window_label']);
        $this->assertCount(12, $period['points']);
        $this->assertSame([], array_diff(array_keys(array_column($period['points'], 'amount', 'key')), [
            '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04',
            '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10',
        ]));
        $this->assertSame([
            '2025-11' => 0, '2025-12' => 0, '2026-01' => 0, '2026-02' => 1000,
            '2026-03' => 0, '2026-04' => 0, '2026-05' => 0, '2026-06' => 0,
            '2026-07' => 0, '2026-08' => 5000, '2026-09' => 4000, '2026-10' => 11000,
        ], array_column($period['points'], 'amount', 'key'));
        $this->assertSame(21000, $period['total_amount']);
        $this->assertSame('Aug 2026', array_column($period['points'], 'label', 'key')['2026-08']);

        $months = $overview['months'];
        $this->assertSame('2026-10', $months['current']['key']);
        $this->assertSame('October 2026', $months['current']['label']);
        $this->assertSame(11000, $months['current']['amount']);
        $this->assertSame(2, $months['current']['orders']);
        $this->assertTrue($months['current']['is_current']);
        $this->assertSame('+175,0%', $months['current']['delta']['label']);
        $this->assertSame('up', $months['current']['delta']['tone']);
        $this->assertSame('vs September 2026', $months['current']['delta']['compare_label']);
        $this->assertSame(4000, $months['previous']['amount']);
        $this->assertNull($months['previous']['delta']);
        $this->assertNull($months['selected']);
        $this->assertSame(['min' => '2023-11', 'max' => '2026-10'], $months['bounds']);

        $selected = app(AdminDashboardData::class)
            ->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'bulan', 'month' => '2026-08'])['statsTab']['overview'];
        $this->assertSame('bulan', $selected['period']['mode']);
        $this->assertSame('2026-08', $selected['period']['month']);
        $this->assertSame('August 2026', $selected['period']['window_label']);
        $this->assertCount(31, $selected['period']['points']);
        $this->assertSame(5000, $selected['period']['total_amount']);
        $this->assertSame(null, $selected['period']['partial_label']);
        $this->assertSame('2026-08', $selected['months']['selected']['key']);
        $this->assertSame(5000, $selected['months']['selected']['amount']);
        $this->assertSame('belum ada pembanding', $selected['months']['selected']['delta']['label']);
        $this->assertNull($selected['months']['selected']['delta']['percent']);

        $currentMonth = app(AdminDashboardData::class)
            ->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'bulan', 'month' => '2026-10'])['statsTab']['overview'];
        $this->assertSame(21, count($currentMonth['period']['points']));
        $this->assertSame(11000, $currentMonth['period']['total_amount']);
        $this->assertNull($currentMonth['months']['selected']);
        $this->assertSame(11000, $currentMonth['months']['current']['amount']);
    }

    public function test_overview_period_input_is_bounded_to_supported_values(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $data = app(AdminDashboardData::class);
        $defaults = $data->forActiveTab($owner, 'stats')['statsTab']['overview']['period'];

        foreach ([
            ['period' => 'harian-tambahan', 'days' => '999', 'month' => '1999-01'],
            ['period' => '', 'days' => '0'],
            ['period' => null, 'month' => '2030-13'],
        ] as $filters) {
            $period = $data->forActiveTab($owner, 'stats', reportingFilters: $filters)['statsTab']['overview']['period'];

            $this->assertSame($defaults['mode'], $period['mode']);
            $this->assertSame($defaults['days'], $period['days']);
            $this->assertSame($defaults['range_label'], $period['range_label']);
            $this->assertCount(7, $period['points']);
        }

        $futureMonth = $data->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'bulan', 'month' => '2030-01'])['statsTab']['overview'];
        $pastMonth = $data->forActiveTab($owner, 'stats', reportingFilters: ['period' => 'bulan', 'month' => '2019-05'])['statsTab']['overview'];

        $this->assertSame('2026-10', $futureMonth['period']['month']);
        $this->assertSame('2026-10', $pastMonth['period']['month']);
    }

    public function test_stats_tab_renders_the_requested_period_from_the_query_string(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'amount' => 4000,
            'currency' => 'IDR',
            'paid_at' => '2026-09-15 09:00:00',
        ]);

        $this->get('/admin?tab=stats&period=bulan&month=2026-09&currency=IDR')
            ->assertOk()
            ->assertSee('Bulan tertentu')
            ->assertSee('September 2026')
            ->assertSee('Bulan dituju · September 2026')
            ->assertSee('value="2026-09"', false)
            ->assertSee('data-period-option="bulan"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Rp4.000')
            ->assertDontSee('7 hari terakhir');

        $this->get('/admin?tab=stats&period=mingguan')
            ->assertOk()
            ->assertSee('8 minggu terakhir')
            ->assertDontSee('Bulan dituju');

        $this->get('/admin?tab=stats&period=bulan&month=2020-01')
            ->assertOk()
            ->assertSee('Bulan tertentu')
            ->assertSee('October 2026');
    }

    public function test_period_aggregates_stay_within_three_payment_scans(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'amount' => 5000,
            'currency' => 'IDR',
            'paid_at' => '2026-10-20 09:00:00',
        ]);

        $aggregates = [];
        DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'payments as canonical_payments') && str_contains($sql, 'group by')) {
                $aggregates[] = $sql;
            }
        });

        foreach (['harian', 'mingguan', 'bulanan', 'bulan'] as $period) {
            $before = count($aggregates);
            app(AdminDashboardData::class)->forActiveTab($owner, 'stats', reportingFilters: ['period' => $period]);
            $scans = count($aggregates) - $before;

            $this->assertLessThanOrEqual(4, $scans, $period.' ran '.$scans.' receipt aggregates');
        }
    }
}
