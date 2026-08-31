<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\Admin\StorefrontFunnelReport;
use App\Storefront\StorefrontFunnelEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class StorefrontFunnelReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_sees_canonical_funnel_aggregates_and_deduped_sessions(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $category = Category::factory()->create(['name' => 'Streaming']);
        $product = Product::factory()->create(['name' => 'Produk Premium', 'category_id' => $category->id]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 125000,
            'currency' => 'IDR',
            'created_at' => Carbon::parse('2026-08-20 10:00:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'subtotal' => 125000,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'klikqris',
            'method' => 'QRIS',
            'provider_create_state' => 'created',
            'status' => PaymentStatus::Paid,
            'amount' => 125000,
            'currency' => 'IDR',
            'paid_at' => Carbon::parse('2026-08-20 10:10:00'),
            'created_at' => Carbon::parse('2026-08-20 10:01:00'),
        ]);

        $sessionHash = str_repeat('a', 64);
        $this->event('storefront.product_opened', Product::class, (string) $product->id, [
            'product_id' => $product->id,
            'session_hash' => $sessionHash,
            'path' => '/produk/'.$product->id,
            'source' => 'instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'august',
        ], '2026-08-20 10:00:00');
        $this->event('storefront.product_opened', Product::class, (string) $product->id, [
            'product_id' => $product->id,
            'session_hash' => $sessionHash,
            'path' => '/produk/'.$product->id,
            'source' => 'instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'august',
        ], '2026-08-20 10:00:30');
        $this->event('storefront.checkout_opened', Product::class, (string) $product->id, [
            'product_id' => $product->id,
            'session_hash' => $sessionHash,
            'source' => 'instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'august',
            'payment_method' => 'qris',
        ], '2026-08-20 10:01:00');
        $this->event('storefront.checkout_submitted', Order::class, (string) $order->id, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'status_endpoint_result' => 'accepted',
            'payment_method' => 'qris',
            'source' => 'instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'august',
        ], '2026-08-20 10:02:00');
        $this->event('storefront.qris_created', Payment::class, 'payment-'.$order->id, [
            'order_id' => $order->id,
            'provider' => 'klikqris',
            'payment_method' => 'QRIS',
            'provider_create_state' => 'created',
            'source' => 'instagram',
        ], '2026-08-20 10:02:01');
        $this->event('storefront.support_selected', Order::class, (string) $order->id, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'support_channel' => 'whatsapp',
            'support_category' => 'payment_pending',
            'source' => 'instagram',
        ], '2026-08-20 10:03:00');

        $filters = ['start_date' => '2026-08-20', 'end_date' => '2026-08-20'];
        $data = app(StorefrontFunnelReport::class)->forAdmin($owner, $filters);

        $this->assertSame('ready', $data['state']);
        $this->assertSame(2, $data['summary']['views']);
        $this->assertSame(1, $data['summary']['unique_sessions']);
        $this->assertSame(1, $data['summary']['checkout_opens']);
        $this->assertSame(1, $data['summary']['checkout_submits']);
        $this->assertSame(1, $data['summary']['accepted_checkouts']);
        $this->assertSame(0, $data['summary']['rejected_checkouts']);
        $this->assertSame(1, $data['summary']['qris_created']);
        $this->assertSame(1, $data['summary']['paid_orders']);
        $this->assertSame(100.0, $data['summary']['payment_conversion_rate']);
        $this->assertSame(125000, $data['summary']['average_order_value']);
        $this->assertSame(1, $data['summary']['support_selections']);
        $this->assertSame(1, $data['summary']['repeat_order_signal']['paid_customers']);
        $this->assertSame(1, $data['products'][0]['product_id']);
        $this->assertSame(1, $data['products'][0]['paid_orders']);
        $this->assertSame('instagram', $data['sources'][0]['source']);
        $this->assertSame(1, $data['qris_outcomes'][0]['count']);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($order->invoice_number, $encoded);
        $this->assertStringNotContainsString('192.0.2.1', $encoded);

        $this->actingAs($owner)
            ->get(route('admin.dashboard', $filters + ['tab' => StorefrontFunnelReport::TAB]))
            ->assertOk()
            ->assertSee('admin-section-funnel', false)
            ->assertSee('Storefront Funnel')
            ->assertSee('Produk Premium')
            ->assertSee('100%')
            ->assertSee('QR/provider outcomes');
    }

    public function test_filters_include_inactive_products_and_reject_unbounded_date_windows(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $category = Category::factory()->create(['name' => 'Legacy']);
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => false]);
        $product->delete();

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 50000,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Produk Legacy']);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $this->event('storefront.checkout_submitted', Order::class, (string) $order->id, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'status_endpoint_result' => 'accepted',
            'payment_method' => 'qris',
        ]);

        $data = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'product_id' => (string) $product->id,
            'category_id' => (string) $category->id,
            'provider' => 'manual',
            'rail' => 'qris',
        ]);
        $this->assertSame('ready', $data['state']);
        $this->assertSame(1, $data['summary']['paid_orders']);
        $this->assertTrue($data['products'][0]['is_deleted']);
        $this->assertFalse($data['products'][0]['is_active']);
        $this->assertSame($product->name, $data['products'][0]['product_name']);

        $queries = [];
        \DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $invalid = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);
        $this->assertSame('error', $invalid['state']);
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'audit_logs')));
    }

    public function test_report_separates_checkout_failures_and_payment_rails(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'Rail Product']);

        foreach ([
            ['provider' => 'manual', 'method' => 'QR_MANUAL', 'invoice' => 'INV-QR'],
            ['provider' => 'manual', 'method' => 'BINANCE_PAY_ID', 'invoice' => 'INV-BINANCE'],
        ] as $paymentAttributes) {
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'invoice_number' => $paymentAttributes['invoice'],
                'order_status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => 50000,
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'provider' => $paymentAttributes['provider'],
                'method' => $paymentAttributes['method'],
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);
            $this->event('storefront.checkout_submitted', Order::class, (string) $order->id, [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'status_endpoint_result' => 'accepted',
                'payment_method' => strtolower($paymentAttributes['method']),
            ]);
        }

        $this->event('storefront.checkout_submitted', Product::class, (string) $product->id, [
            'product_id' => $product->id,
            'status_endpoint_result' => 'rejected',
            'failure_kind' => 'App\\Exceptions\\InvalidCheckout',
            'payment_method' => 'qris',
        ]);

        $data = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $this->assertSame(3, $data['summary']['checkout_submits']);
        $this->assertSame(2, $data['summary']['accepted_checkouts']);
        $this->assertSame(1, $data['summary']['rejected_checkouts']);
        $this->assertSame(2, $data['summary']['paid_orders']);
        $this->assertSame(100.0, $data['summary']['payment_conversion_rate']);
        $this->assertSame(1, $data['summary']['repeat_order_signal']['repeat_customers']);
        $this->assertSame(1, collect($data['failure_categories'])->firstWhere('key', 'checkout_validation')['count']);
        $this->assertSame(1, collect($data['rails'])->firstWhere('rail', 'qris')['paid_orders']);
        $this->assertSame(1, collect($data['rails'])->firstWhere('rail', 'binance_pay_id')['paid_orders']);

        $binance = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'rail' => 'binance_pay_id',
        ]);
        $this->assertSame(1, $binance['summary']['paid_orders']);
    }

    public function test_support_is_forbidden_and_browser_payment_event_cannot_create_paid_fact(): void
    {
        $support = User::factory()->create(['role' => UserRole::Support]);
        $this->actingAs($support)
            ->get(route('admin.dashboard', ['tab' => StorefrontFunnelReport::TAB]))
            ->assertForbidden();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::Pending,
            'order_status' => OrderStatus::PendingPayment,
        ]);
        $this->event('storefront.payment_verified', Order::class, (string) $order->id, [
            'order_id' => $order->id,
            'payment_status' => 'paid',
        ]);

        $data = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
        $this->assertSame(0, $data['summary']['paid_orders']);
    }

    public function test_browser_attribution_contract_is_whitelisted_bounded_and_report_filterable(): void
    {
        $product = Product::factory()->create();

        $this->postJson(route('storefront.events.store'), [
            'action' => 'storefront.checkout_opened',
            'product_id' => $product->id,
            'path' => '/produk/'.$product->id,
            'source' => 'instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'august',
            'message' => 'Recovery Key: never store this',
        ])->assertOk();

        $event = AuditLog::query()->where('action', 'storefront.checkout_opened')->latest('id')->firstOrFail();
        $this->assertSame('instagram', $event->new_values['source']);
        $this->assertSame('/produk/'.$product->id, $event->new_values['path']);
        $this->assertSame('social', $event->new_values['utm_medium']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->new_values['session_hash']);
        $this->assertArrayNotHasKey('message', $event->new_values);

        $owner = User::factory()->create(['role' => UserRole::Admin]);
        $report = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'source' => 'instagram',
            'utm_source' => 'instagram',
        ]);
        $this->assertSame(1, $report['summary']['checkout_opens']);

        $response = $this->postJson(route('storefront.events.store'), [
            'action' => 'storefront.checkout_opened',
            'product_id' => $product->id,
            'source' => 'customer@example.test',
        ]);
        $this->assertContains($response->getStatusCode(), [302, 422]);
        $this->assertSame(1, AuditLog::query()->where('action', 'storefront.checkout_opened')->count());
    }

    public function test_family_reporting_uses_snapshotted_identity_after_membership_moves(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $original = ProductFamily::factory()->create(['name' => 'Original Family', 'slug' => 'original-family']);
        $moved = ProductFamily::factory()->create(['name' => 'Moved Family', 'slug' => 'moved-family']);
        $product = Product::factory()->create([
            'product_family_id' => $original->id,
            'name' => 'Family SKU',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 40000,
            'created_at' => Carbon::parse('2026-08-30 10:00:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'subtotal' => 40000,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Paid,
            'amount' => 40000,
            'currency' => 'IDR',
            'paid_at' => Carbon::parse('2026-08-30 10:01:00'),
            'created_at' => Carbon::parse('2026-08-30 10:00:30'),
        ]);
        $this->event('storefront.checkout_submitted', Order::class, (string) $order->id, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'family_id' => $original->id,
            'family_name' => $original->name,
            'status_endpoint_result' => 'accepted',
            'payment_method' => 'qris',
        ], '2026-08-30 10:01:30');

        $product->forceFill(['product_family_id' => $moved->id])->save();

        $report = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => '2026-08-30',
            'end_date' => '2026-08-30',
            'family_id' => (string) $original->id,
        ]);
        $this->assertSame(1, $report['summary']['paid_orders']);
        $this->assertSame($original->id, $report['families'][0]['family_id']);
        $this->assertSame('Original Family', $report['families'][0]['family_name']);
        $this->assertSame(1, $report['families'][0]['paid_orders']);

        $movedReport = app(StorefrontFunnelReport::class)->forAdmin($owner, [
            'start_date' => '2026-08-30',
            'end_date' => '2026-08-30',
            'family_id' => (string) $moved->id,
        ]);
        $this->assertSame(0, $movedReport['summary']['paid_orders']);
        $this->assertSame([], $movedReport['families']);
    }

    public function test_order_item_family_snapshot_survives_membership_move_for_new_funnel_events(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        try {
            $owner = User::factory()->create(['role' => UserRole::Owner]);
            $family = ProductFamily::factory()->create(['name' => 'Snapshotted Family', 'slug' => 'snapshotted-family']);
            $destination = ProductFamily::factory()->create(['name' => 'New Family', 'slug' => 'new-family']);
            $product = Product::factory()->create([
                'product_family_id' => $family->id,
                'name' => 'Snapshotted SKU',
                'stock' => 2,
                'reserved_stock' => 0,
            ]);
            $order = Order::factory()->create([
                'order_status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => 30000,
                'currency' => 'IDR',
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'family_id' => $family->id,
                'family_name' => $family->name,
                'quantity' => 1,
                'unit_price' => 30000,
                'subtotal' => 30000,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'provider' => 'manual',
                'method' => 'QR_MANUAL',
                'status' => PaymentStatus::Paid,
                'amount' => 30000,
                'currency' => 'IDR',
                'paid_at' => now(),
            ]);

            app(StorefrontFunnelEvents::class)->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'accepted',
            ]);
            $product->forceFill(['product_family_id' => $destination->id])->save();

            $report = app(StorefrontFunnelReport::class)->forAdmin($owner, [
                'start_date' => '2026-08-30',
                'end_date' => '2026-08-30',
                'family_id' => (string) $family->id,
            ]);
            $this->assertSame(1, $report['summary']['checkout_submits']);
            $this->assertSame(1, $report['families'][0]['family_id']);
            $this->assertSame('Snapshotted Family', $report['families'][0]['family_name']);

            $destinationReport = app(StorefrontFunnelReport::class)->forAdmin($owner, [
                'start_date' => '2026-08-30',
                'end_date' => '2026-08-30',
                'family_id' => (string) $destination->id,
            ]);
            $this->assertSame(0, $destinationReport['summary']['checkout_submits']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_multi_family_order_events_retain_every_bounded_family_snapshot(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        try {
            $owner = User::factory()->create(['role' => UserRole::Owner]);
            $familyA = ProductFamily::factory()->create(['name' => 'Family A', 'slug' => 'family-a']);
            $familyB = ProductFamily::factory()->create(['name' => 'Family B', 'slug' => 'family-b']);
            $productA = Product::factory()->create(['product_family_id' => $familyA->id, 'name' => 'Product A']);
            $productB = Product::factory()->create(['product_family_id' => $familyB->id, 'name' => 'Product B']);
            $order = Order::factory()->create([
                'order_status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => 30000,
                'currency' => 'IDR',
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $productA->id,
                'product_name' => $productA->name,
                'family_id' => $familyA->id,
                'family_name' => $familyA->name,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $productB->id,
                'product_name' => $productB->name,
                'family_id' => $familyB->id,
                'family_name' => $familyB->name,
                'quantity' => 1,
                'unit_price' => 20000,
                'subtotal' => 20000,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'provider' => 'manual',
                'method' => 'QR_MANUAL',
                'status' => PaymentStatus::Paid,
                'amount' => 30000,
                'currency' => 'IDR',
                'paid_at' => now(),
            ]);

            app(StorefrontFunnelEvents::class)->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'accepted',
            ]);

            $event = AuditLog::query()->where('action', 'storefront.checkout_submitted')->latest('id')->firstOrFail();
            $this->assertSame([
                ['id' => $familyA->id, 'name' => 'Family A'],
                ['id' => $familyB->id, 'name' => 'Family B'],
            ], $event->new_values['family_snapshots']);
            $this->assertArrayNotHasKey('family_id', $event->new_values);
            $this->assertArrayNotHasKey('family_name', $event->new_values);
            $report = app(StorefrontFunnelReport::class)->forAdmin($owner, [
                'start_date' => '2026-08-30',
                'end_date' => '2026-08-30',
            ]);
            $families = collect($report['families'])->keyBy('family_id');
            $this->assertSame(1, $families[$familyA->id]['checkout_submits']);
            $this->assertSame(1, $families[$familyB->id]['checkout_submits']);
            $this->assertSame(1, $families[$familyA->id]['paid_orders']);
            $this->assertSame(1, $families[$familyB->id]['paid_orders']);

        } finally {
            Carbon::setTestNow();
        }
    }

    private function event(string $action, string $subjectType, string $subjectId, array $values, ?string $createdAt = null): void
    {
        AuditLog::factory()->create([
            'user_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'new_values' => $values,
            'ip_address' => '192.0.2.1',
            'created_at' => $createdAt === null ? now() : Carbon::parse($createdAt),
        ]);
    }
}
