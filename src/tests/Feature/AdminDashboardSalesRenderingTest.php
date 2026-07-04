<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\User;
use App\Services\Admin\AdminOrderOperations;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminDashboardSalesRenderingTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('grid grid-cols-2 gap-2', false)
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

    public function test_penjualan_tab_shows_sales_proof_status_references_errors_and_retry_action(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);

        $sentOrder = Order::factory()->create(['invoice_number' => 'INV-SENT', 'created_at' => now()]);
        OrderItem::factory()->create(['order_id' => $sentOrder->id, 'product_name' => 'Sent Product']);
        SalesProofPost::create(['order_id' => $sentOrder->id, 'status' => SalesProofPost::STATUS_SENT, 'attempts' => 1, 'telegram_message_id' => '777']);

        $failedOrder = Order::factory()->create(['invoice_number' => 'INV-FAILED', 'created_at' => now()->subMinute()]);
        OrderItem::factory()->create(['order_id' => $failedOrder->id, 'product_name' => 'Failed Product']);
        SalesProofPost::create(['order_id' => $failedOrder->id, 'status' => SalesProofPost::STATUS_FAILED, 'attempts' => 3, 'last_error' => 'channel cannot post']);

        $pendingOrder = Order::factory()->create(['invoice_number' => 'INV-PENDING', 'created_at' => now()->subMinutes(2)]);
        OrderItem::factory()->create(['order_id' => $pendingOrder->id, 'product_name' => 'Pending Product']);
        SalesProofPost::create(['order_id' => $pendingOrder->id, 'status' => SalesProofPost::STATUS_PENDING]);

        $noPostOrder = Order::factory()->create(['invoice_number' => 'INV-NOPOST', 'created_at' => now()->subMinutes(3)]);
        OrderItem::factory()->create(['order_id' => $noPostOrder->id, 'product_name' => 'No Post Product']);

        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee('Sales Proof Post')
            ->assertSee('Belum ada post')
            ->assertSee('Pending')
            ->assertSee('Sent')
            ->assertSee('Telegram message ID: 777')
            ->assertSee('Failed')
            ->assertSee('Error: channel cannot post')
            ->assertSee('Retry Sales Proof Post')
            ->assertSee('Detail order')
            ->assertSee(route('admin.orders.sales-proof.retry', $failedOrder), false);

        $this->get(route('admin.orders.show', $failedOrder))
            ->assertOk()
            ->assertSee('Detail Order')
            ->assertSee('INV-FAILED')
            ->assertSee('Sales Proof Post')
            ->assertSee('Failed')
            ->assertSee('Error: channel cannot post')
            ->assertSee('Retry Sales Proof Post')
            ->assertSee(route('admin.orders.sales-proof.retry', $failedOrder), false);
    }

    public function test_sales_proof_manual_retry_uses_publisher_path_without_changing_order_state(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 919]])]);

        $customer = Customer::factory()->create(['username' => 'RetryAdmin']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-RETRY',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $product = Product::factory()->create(['name' => 'Retry Product']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Retry Product', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        InventoryMovement::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale, 'quantity' => -1]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_FAILED, 'attempts' => 3, 'last_error' => 'temporary failure']);

        $this->post(route('admin.orders.sales-proof.retry', $order))->assertRedirect(route('admin.dashboard', ['tab' => 'penjualan']));

        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_SENT, $post->status);
        $this->assertSame(4, $post->attempts);
        $this->assertSame('919', $post->telegram_message_id);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);

        $this->post(route('admin.orders.sales-proof.retry', $order))->assertSessionHas('status', 'Sales Proof Post tidak dalam status gagal. Retry tidak dijalankan.');
        $this->assertSame(1, SalesProofPost::where('status', SalesProofPost::STATUS_SENT)->count());
    }

    public function test_sales_proof_manual_retry_refuses_failed_row_without_sale_proof(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 920]])]);

        $customer = Customer::factory()->create(['username' => 'RetryCorrupt']);
        $product = Product::factory()->create(['name' => 'Retry Corrupt Product']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-RETRY-CORRUPT',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Retry Corrupt Product', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_FAILED, 'attempts' => 3, 'last_error' => 'temporary failure']);

        $this->post(route('admin.orders.sales-proof.retry', $order))->assertRedirect(route('admin.dashboard', ['tab' => 'penjualan']));

        Http::assertNothingSent();
        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertSame(4, $post->attempts);
        $this->assertStringContainsString('sale proof', (string) $post->last_error);
        $this->assertNull($post->telegram_message_id);
    }

    public function test_legacy_pending_sales_proof_rows_do_not_render_as_pending_when_feature_is_disabled(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $order = Order::factory()->create(['invoice_number' => 'INV-LEGACY-PENDING', 'created_at' => now()]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Legacy Pending Product']);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);

        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee('INV-LEGACY-PENDING')
            ->assertSee('Disabled')
            ->assertSee('Error: Sales Proof Post is disabled.')
            ->assertDontSee('>Pending<', false);
    }

    public function test_manual_payment_approval_without_enabled_sales_proof_does_not_leave_dashboard_pending(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($user);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'QR_MANUAL', 'manual');

        app(AdminOrderOperations::class)->completeManualOrder($order, $user, '127.0.0.1');

        $this->assertDatabaseMissing('sales_proof_posts', ['order_id' => $order->id]);
        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee($order->invoice_number)
            ->assertSee('Belum ada post')
            ->assertDontSee('>Pending<', false);
    }

    public function test_enabled_sales_proof_without_channel_config_marks_dashboard_failed_instead_of_pending(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true]);
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($user);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'QR_MANUAL', 'manual');

        app(AdminOrderOperations::class)->completeManualOrder($order, $user, '127.0.0.1');

        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertStringContainsString('Store Channel chat is not configured', (string) $post->last_error);
        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee($order->invoice_number)
            ->assertSee('Failed')
            ->assertSee('Error: Store Channel chat is not configured')
            ->assertDontSee('>Pending<', false);
    }
}
