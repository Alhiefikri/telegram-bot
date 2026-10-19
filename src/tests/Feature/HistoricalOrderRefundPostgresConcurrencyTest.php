<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\StoreBalanceEntry;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsHistoricalRefundFixture;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class HistoricalOrderRefundPostgresConcurrencyTest extends TestCase
{
    use BuildsHistoricalRefundFixture;
    use RunsPostgresRace;

    private bool $ownsDisposableDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || env('RUN_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires disposable PostgreSQL and pcntl.');
        }
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->ownsDisposableDatabase = true;
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->ownsDisposableDatabase) {
            // Other concurrency families retain their own fixtures rather
            // than resetting each test. Leave the shared gate database empty.
            PostgresTestDatabase::migrateFresh();
        }
        parent::tearDown();
    }

    public function test_duplicate_refund_choices_credit_one_retail_entitlement(): void
    {
        [$customer, $unit, $claim] = $this->verifiedClaim();
        $ledger = app(StoreBalanceLedger::class);
        $results = $this->race([
            fn () => ['state' => app(WarrantySettlementService::class)->choose(Customer::findOrFail($customer->id), $claim->public_id, 'refund')->settlement_state],
            fn () => ['state' => app(WarrantySettlementService::class)->choose(Customer::findOrFail($customer->id), $claim->public_id, 'refund')->settlement_state],
        ]);
        $this->assertSame(['refunded', 'refunded'], array_column($results, 'state'));
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(PaymentStatus::Paid, $unit->order->payments()->sole()->status);
    }

    public function test_partial_and_whole_order_refund_race_never_returns_more_than_original_payment(): void
    {
        [$customer, $unit, $claim] = $this->verifiedClaim();
        $paymentId = $unit->order->payments()->sole()->id;
        $this->race([
            function () use ($customer, $claim): array {
                try {
                    app(WarrantySettlementService::class)->choose(Customer::findOrFail($customer->id), $claim->public_id, 'refund');

                    return ['state' => 'partial'];
                } catch (InvalidOrderTransition) {
                    return ['state' => 'already_full'];
                }
            },
            function () use ($paymentId): array {
                app(OrderLifecycle::class)->applyPaymentRefunded(Payment::findOrFail($paymentId));

                return ['state' => 'full'];
            },
        ]);
        $this->assertSame(16000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(PaymentStatus::Refunded, $unit->order->payments()->sole()->status);
        $this->assertSame(16000, (int) StoreBalanceEntry::where('kind', StoreBalanceEntryKind::RefundCredit)->sum('amount'));
    }

    private function verifiedClaim(): array
    {
        [$customer, $unit] = $this->fixture();
        $unit->order->payments()->sole()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'debit');
        $claim = $this->approvedHistoricalClaim($customer, $unit);

        return [$customer, $unit, $claim];
    }

    public function test_duplicate_missing_unit_refunds_credit_one_entitlement_in_postgres(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $order->update(['order_status' => OrderStatus::Processing]);
        $order->payments()->sole()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $purchase = $unit->purchase;
        $purchase->update(['state' => 'settled', 'observed_quantity' => 1, 'actual_cost_micros' => 300_000]);
        $purchase->fulfillment->update(['state' => 'review']);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $order->id, 'debit');
        $worker = fn () => ['id' => app(MissingFulfillmentRefundService::class)->request(Customer::findOrFail($customer->id), $unit->order_item_id)->id];
        $results = $this->race([$worker, $worker]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(1, ProductStockItem::whereNull('retired_at')->count());
        $this->assertSame(PaymentStatus::Paid, $order->payments()->sole()->status);
    }

    public function test_partial_confirmation_and_full_remainder_refund_race_never_exceeds_the_original_payment(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $payment = $order->payments()->sole();
        $this->assertSame('manual', $payment->provider);
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        $refund = OrderRefund::where('warranty_claim_id', $claim->id)->sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $review = ManualFulfillmentReview::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 16000,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => 'synthetic-race-review',
        ]);
        $paymentId = $payment->id;
        $refundId = $refund->id;
        $reviewId = $review->id;
        $ownerId = $owner->id;

        $this->race([
            function () use ($ownerId, $refundId): array {
                try {
                    app(WarrantySettlementService::class)->confirmExternalRefund(User::findOrFail($ownerId), $refundId, 8000, 'IDR', 'synthetic-partial-transfer', 'success');

                    return ['state' => 'confirmed'];
                } catch (\Throwable) {
                    return ['state' => 'blocked'];
                }
            },
            function () use ($ownerId, $reviewId): array {
                try {
                    app(ManualFulfillmentResolutionService::class)->resolve(ManualFulfillmentReview::findOrFail($reviewId), User::findOrFail($ownerId), 'refund', 'Full refund race.', 'synthetic-full-transfer', 'success');

                    return ['state' => 'refunded'];
                } catch (\Throwable) {
                    return ['state' => 'blocked'];
                }
            },
        ]);

        $payment = Payment::findOrFail($paymentId);
        $confirmed = (int) OrderRefund::where('payment_id', $paymentId)->where('state', 'confirmed')->sum('amount');
        $this->assertLessThanOrEqual(16000, $confirmed);
        if ($payment->status === PaymentStatus::Refunded) {
            $this->assertSame(16000, $confirmed);
            $this->assertSame(0, OrderRefund::where('payment_id', $paymentId)->whereIn('state', ['requested', 'uncertain'])->count());
            $this->assertSame(2, OrderRefund::where('payment_id', $paymentId)->count());
            $this->assertSame(ManualFulfillmentReview::STATE_REFUNDED, ManualFulfillmentReview::findOrFail($reviewId)->state);
        } else {
            $this->assertSame(PaymentStatus::Paid, $payment->status);
            $this->assertSame(1, OrderRefund::where('payment_id', $paymentId)->count());
            $this->assertSame(ManualFulfillmentReview::STATE_PENDING, ManualFulfillmentReview::findOrFail($reviewId)->state);
        }
        $this->assertLessThanOrEqual(1, OrderRefund::where('payment_id', $paymentId)->whereNull('warranty_claim_id')->whereNull('supplier_fulfillment_id')->count());
    }
}
