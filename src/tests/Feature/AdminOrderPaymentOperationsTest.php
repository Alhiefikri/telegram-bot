<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AdminOrderPaymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_order_and_payment_operation_lists(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $customer = Customer::factory()->create(['username' => 'buyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OPS', 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'merchant_ref' => 'MR-OPS', 'status' => PaymentStatus::Paid]);

        $this->get(route('admin.dashboard', ['tab' => 'penjualan']))
            ->assertOk()
            ->assertSee('INV-OPS')
            ->assertSee('IDR');
    }




    public function test_order_and_payment_policy_matrix_by_role(): void
    {
        $orderPolicy = new OrderPolicy;
        $paymentPolicy = new PaymentPolicy;
        $order = Order::factory()->create();
        $payment = Payment::factory()->create(['order_id' => $order->id]);

        $expectations = [
            'owner' => ['role' => UserRole::Owner, 'view' => true, 'completeManual' => true, 'resendReceipt' => true, 'retrySalesProof' => true, 'reconcile' => true],
            'admin' => ['role' => UserRole::Admin, 'view' => true, 'completeManual' => true, 'resendReceipt' => true, 'retrySalesProof' => true, 'reconcile' => true],
            'finance' => ['role' => UserRole::Finance, 'view' => true, 'completeManual' => true, 'resendReceipt' => true, 'retrySalesProof' => false, 'reconcile' => true],
            'support' => ['role' => UserRole::Support, 'view' => true, 'completeManual' => false, 'resendReceipt' => true, 'retrySalesProof' => false, 'reconcile' => false],
            'null-role' => ['role' => null, 'view' => false, 'completeManual' => false, 'resendReceipt' => false, 'retrySalesProof' => false, 'reconcile' => false],
        ];

        foreach ($expectations as $case => $expected) {
            $user = User::factory()->create(['role' => $expected['role']]);

            $this->assertSame($expected['view'], $orderPolicy->viewAny($user), "{$case} order viewAny");
            $this->assertSame($expected['view'], $orderPolicy->view($user, $order), "{$case} order view");
            $this->assertFalse($orderPolicy->create($user), "{$case} order create");
            $this->assertFalse($orderPolicy->update($user, $order), "{$case} order update");
            $this->assertFalse($orderPolicy->delete($user, $order), "{$case} order delete");
            $this->assertSame($expected['completeManual'], $orderPolicy->completeManual($user, $order), "{$case} order completeManual");
            $this->assertSame($expected['resendReceipt'], $orderPolicy->resendReceipt($user, $order), "{$case} order resendReceipt");
            $this->assertSame($expected['retrySalesProof'], $orderPolicy->retrySalesProof($user, $order), "{$case} order retrySalesProof");

            $this->assertSame($expected['view'], $paymentPolicy->viewAny($user), "{$case} payment viewAny");
            $this->assertSame($expected['view'], $paymentPolicy->view($user, $payment), "{$case} payment view");
            $this->assertFalse($paymentPolicy->create($user), "{$case} payment create");
            $this->assertFalse($paymentPolicy->update($user, $payment), "{$case} payment update");
            $this->assertFalse($paymentPolicy->delete($user, $payment), "{$case} payment delete");
            $this->assertSame($expected['reconcile'], $paymentPolicy->reconcile($user, $payment), "{$case} payment reconcile");
        }
    }

    public function test_operation_policy_visibility_follows_role_permissions(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 123]);
        $manualOrder = Order::factory()->create(['customer_id' => $customer->id]);
        $paidOrder = Order::factory()->create(['customer_id' => $customer->id, 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        $payment = Payment::factory()->create(['provider' => 'klikqris', 'status' => PaymentStatus::Pending, 'merchant_ref' => 'KQR-ACTION']);

        $orderPolicy = new OrderPolicy;
        $paymentPolicy = new PaymentPolicy;
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $support = User::factory()->create(['role' => UserRole::Support]);

        $this->assertTrue($orderPolicy->completeManual($finance, $manualOrder));
        $this->assertTrue($orderPolicy->resendReceipt($finance, $paidOrder));
        $this->assertTrue($paymentPolicy->reconcile($finance, $payment));

        $this->assertFalse($orderPolicy->completeManual($support, $manualOrder));
        $this->assertTrue($orderPolicy->resendReceipt($support, $paidOrder));
        $this->assertFalse($paymentPolicy->reconcile($support, $payment));
    }

    public function test_raw_payment_payload_visibility_by_role(): void
    {
        PaymentEvent::factory()->create(['raw_payload' => ['status' => 'PAID']]);

        foreach ([UserRole::Owner, UserRole::Admin, UserRole::Finance] as $role) {
            $this->assertTrue($role->canViewRawPaymentPayloads());
        }

        $this->assertFalse(UserRole::Support->canViewRawPaymentPayloads());
    }

    public function test_resend_receipt_dispatches_notification_job_and_audits(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Support]);
        $order = Order::factory()->create(['customer_id' => Customer::factory()->create(['telegram_id' => 123])->id, 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);

        app(AdminOrderOperations::class)->resendReceipt($order, $user, '127.0.0.1');

        Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn ($job) => $job->orderId === $order->id && $job->force);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'order.receipt_resent', 'subject_id' => (string) $order->id]);
    }

    public function test_resend_receipt_sends_again_when_receipt_was_already_sent(): void
    {
        config()->set('services.telegram.token', 'test-token');
        config()->set('queue.default', 'sync');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $order = Order::factory()->create([
            'customer_id' => Customer::factory()->create(['telegram_id' => 12345])->id,
            'invoice_number' => 'INV-RESENT',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'telegram_receipt_sent_at' => now()->subMinute(),
        ]);

        app(AdminOrderOperations::class)->resendReceipt($order);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'INV-RESENT'));
    }

    public function test_resend_receipt_guard_rejects_unpaid_or_missing_telegram_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AdminOrderOperations::class)->resendReceipt(Order::factory()->create(['payment_status' => PaymentStatus::Pending]));
    }

    public function test_complete_manual_order_marks_paid_completed_updates_stock_dispatches_receipt_and_audits(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Finance]);
        $customer = Customer::factory()->create(['telegram_id' => 123]);
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 2);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());

        $completed = app(AdminOrderOperations::class)->completeManualOrder($order, $user, '127.0.0.1');

        $this->assertSame(OrderStatus::Completed, $completed->order_status);
        $this->assertSame(PaymentStatus::Paid, $completed->payment_status);
        $this->assertSame(PaymentStatus::Paid, $completed->payments->first()->status);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn ($job) => $job->orderId === $order->id);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'order.manual_completed', 'subject_id' => (string) $order->id]);
    }

    public function test_admin_dashboard_can_approve_a_pending_binance_payment_for_manual_delivery(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_714_000,
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['amount' => '5.7', 'currency' => 'USDT', 'transactionId' => 'P_MISMATCH'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.complete-manual', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'order.manual_completed', 'subject_id' => (string) $order->id]);
    }

    public function test_admin_dashboard_can_reject_a_pending_binance_payment_and_release_stock(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_714_000,
            'status' => PaymentStatus::Pending,
            'provider_payload' => ['amount' => '5.7', 'currency' => 'USDT', 'transactionId' => 'P_MISMATCH'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.reject-manual', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'order.manual_rejected', 'subject_id' => (string) $order->id]);
    }

    public function test_admin_dashboard_shows_binance_mismatch_evidence_and_resolution_actions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_714_000,
            'status' => PaymentStatus::Pending,
            'provider_payload' => [
                'amount' => '5.7',
                'currency' => 'USDT',
                'orderId' => '445064386362580992',
                'transactionId' => 'P_386362580992',
                'transactionTime' => now()->getTimestampMs(),
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Tagihan')
            ->assertSee('5.714 USDT')
            ->assertSee('5.7 USDT')
            ->assertSee('P_38••••0992')
            ->assertDontSee('P_386362580992')
            ->assertSee('Setujui pembayaran & kirim stok')
            ->assertSee('Tolak pembayaran & lepas stok');
    }

    public function test_admin_cannot_resolve_a_binance_order_without_mismatch_evidence(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'currency' => 'USDT',
            'amount' => 5_714_000,
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.complete-manual', $order))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors(['order' => 'Pembayaran Binance ini belum memiliki bukti mismatch untuk direview.']);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }


    public function test_audit_logs_are_related_to_operations_records(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::factory()->create();
        AuditLog::factory()->create(['subject_type' => Order::class, 'subject_id' => (string) $order->id, 'action' => 'order.receipt_resent']);
        AuditLog::factory()->create(['subject_type' => Payment::class, 'subject_id' => (string) $payment->id, 'action' => 'payment.manually_reconciled']);

        $this->assertCount(1, $order->auditLogs);
        $this->assertCount(1, $payment->auditLogs);
    }

    public function test_manual_payment_setting_create_persists_record(): void
    {
        ManualPaymentSetting::query()->create([
            'admin_contact_url' => 'https://t.me/e2e_repro',
            'admin_username' => 'e2e_repro',
            'instructions' => 'Payment setting create repro',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('manual_payment_settings', [
            'admin_contact_url' => 'https://t.me/e2e_repro',
        ]);
        $this->assertSame(1, ManualPaymentSetting::query()->count());
    }

    #[Group('postgres-release')]
    public function test_ops_health_passes_when_database_and_queue_schema_exist(): void
    {
        $this->artisan('ops:health', ['--db' => true, '--queue-schema' => true])
            ->assertSuccessful();
    }

    public function test_ops_health_fails_when_queue_schema_is_missing(): void
    {
        Schema::dropIfExists('jobs');

        $this->artisan('ops:health', ['--queue-schema' => true])
            ->assertFailed()
            ->expectsOutputToContain('Schema: missing jobs');
    }
}
