<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\Tripay\TripayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class Phase7AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_order_and_payment_operation_lists(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $customer = Customer::factory()->create(['username' => 'buyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OPS', 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'merchant_ref' => 'MR-OPS']);

        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order]);
        Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$payment]);
    }

    public function test_resend_receipt_dispatches_notification_job_and_audits(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Support]);
        $order = Order::factory()->create(['customer_id' => Customer::factory()->create(['telegram_id' => 123])->id, 'order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);

        app(AdminOrderOperations::class)->resendReceipt($order, $user, '127.0.0.1');

        Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn ($job) => $job->orderId === $order->id);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'order.receipt_resent', 'subject_id' => (string) $order->id]);
    }

    public function test_resend_receipt_guard_rejects_unpaid_or_missing_telegram_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AdminOrderOperations::class)->resendReceipt(Order::factory()->create(['payment_status' => PaymentStatus::Pending]));
    }

    public function test_manual_reconciliation_fetches_tripay_detail_processes_and_audits(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Finance]);
        $payment = Payment::factory()->create(['provider' => 'tripay', 'status' => PaymentStatus::Pending, 'merchant_ref' => 'MR-777', 'provider_reference' => 'T777', 'amount' => 10000]);
        $this->mock(TripayClient::class, function ($mock): void {
            $mock->shouldReceive('detail')->once()->with('T777')->andReturn(['success' => true, 'data' => ['reference' => 'T777', 'merchant_ref' => 'MR-777', 'total_amount' => 10000, 'payment_method_code' => 'QRIS', 'status' => 'PAID']]);
        });

        $event = app(AdminPaymentOperations::class)->reconcile($payment, $user, '127.0.0.1');

        $this->assertTrue($event->fresh()->processed_at !== null);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'payment.manually_reconciled', 'subject_id' => (string) $payment->id]);
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
}
