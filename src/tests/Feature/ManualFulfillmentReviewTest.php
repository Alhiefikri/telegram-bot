<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualFulfillmentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_payment_stays_paid_and_creates_one_pending_review_without_fulfillment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Expired,
            'amount' => 10_000,
            'provider_total_payment' => 10_016,
            'provider_reference' => 'PROVIDER-LATE-225',
        ]);

        $first = app(OrderLifecycle::class)->applyPaymentPaid($payment);
        $second = app(OrderLifecycle::class)->applyPaymentPaid($payment->fresh());

        $this->assertTrue($first->newlyApplied);
        $this->assertFalse($second->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 1);
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 10_016,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => 'PROVIDER-LATE-225',
        ]);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_admin_queue_and_detail_are_readable_by_authorized_read_only_roles(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'provider_reference' => 'LATE-DETAIL-225',
        ]);
        $review = ManualFulfillmentReview::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 25_000,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => 'LATE-DETAIL-225',
        ]);

        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $this->actingAs($finance)
            ->get(route('admin.manual-fulfillment-reviews.index'))
            ->assertOk()
            ->assertSee($review->order->invoice_number)
            ->assertSee('Manual Fulfillment Review');
        $this->actingAs($finance)
            ->get(route('admin.manual-fulfillment-reviews.show', $review))
            ->assertOk()
            ->assertSee('LATE-DETAIL-225')
            ->assertDontSee('refund');

        $support = User::factory()->create(['role' => UserRole::Support]);
        $this->actingAs($support)
            ->get(route('admin.manual-fulfillment-reviews.index'))
            ->assertOk();

        $this->actingAs(User::factory()->create(['role' => null]))
            ->get(route('admin.manual-fulfillment-reviews.index'))
            ->assertForbidden();
    }
}
