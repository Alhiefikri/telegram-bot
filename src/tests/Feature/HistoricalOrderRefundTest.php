<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\OrderRefund;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreBalanceEntry;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsHistoricalRefundFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class HistoricalOrderRefundTest extends NonTransactionalDatabaseTestCase
{
    use BuildsHistoricalRefundFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_replayed_missing_refund_rechecks_order_ownership_under_the_order_lock(): void
    {
        [$customer, $unit] = $this->fixture();
        $fulfillment = $unit->purchase->fulfillment;
        $refund = OrderRefund::create([
            'order_id' => $unit->order_id, 'payment_id' => $unit->order->payments()->sole()->id,
            'customer_id' => $customer->id, 'supplier_fulfillment_id' => $fulfillment->id,
            'missing_quantity' => 1, 'amount' => 8000, 'currency' => 'IDR', 'state' => 'requested',
        ]);
        $newOwner = Customer::factory()->create();
        $changedOwner = false;
        $active = true;
        DB::listen(function (QueryExecuted $query) use ($unit, $newOwner, &$changedOwner, &$active): void {
            if ($active && ! $changedOwner
                && preg_match('/\bfrom\s+["`]?orders["`]?(?:\s|$)/i', $query->sql) === 1) {
                // The first ownership read has returned its original row, but
                // ownership changes before the mutation obtains the Order lock.
                $changedOwner = true;
                $unit->order->update(['customer_id' => $newOwner->id]);
            }
        });
        try {
            app(MissingFulfillmentRefundService::class)->request($customer, $unit->order_item_id);
            $this->fail('A former owner received the existing missing-unit refund.');
        } catch (InvalidOrderTransition) {
            $this->assertTrue($changedOwner);
            $this->assertSame(1, OrderRefund::count());
            $this->assertSame('requested', $refund->fresh()->state);
        } finally {
            $active = false;
        }
        Http::assertNothingSent();
    }

    public static function unapprovedSettlementChoices(): iterable
    {
        foreach (['received', 'pending', 'submission_unknown', 'review', 'rejected', 'reversed', 'cancelled', 'expired'] as $state) {
            yield $state => [$state, 'refund'];
        }
    }

    #[DataProvider('unapprovedSettlementChoices')]
    public function test_unapproved_claim_cannot_choose_a_settlement(string $state, string $choice): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        // Keep the other eligibility checks satisfied to isolate the approval guard.
        $claim->update(['state' => $state, 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);
        Queue::fake();
        $claimBefore = $claim->fresh()->getAttributes();
        $stockBefore = $unit->stockItem->fresh()->getAttributes();
        $purchaseCount = SupplierPurchase::count();
        $entryCount = StoreBalanceEntry::count();
        $auditCount = AuditLog::count();

        try {
            app(WarrantySettlementService::class)->choose($customer, $claim->public_id, $choice);
            $this->fail('An unapproved claim was accepted for settlement.');
        } catch (InvalidOrderTransition $exception) {
            $this->assertSame('This claim is not eligible for settlement.', $exception->getMessage());
        }

        $this->assertSame($claimBefore, $claim->fresh()->getAttributes());
        $this->assertSame($stockBefore, $unit->stockItem->fresh()->getAttributes());
        $this->assertSame(0, OrderRefund::count());
        $this->assertSame($purchaseCount, SupplierPurchase::count());
        $this->assertSame($entryCount, StoreBalanceEntry::count());
        $this->assertSame($auditCount, AuditLog::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_buyer_refund_credits_retail_once_and_full_order_refund_only_credits_the_remainder(): void
    {
        [$customer, $unit] = $this->fixture();
        $payment = $unit->order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'initial-funds', 'initial-funds');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'order-debit');
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        $settlements = app(WarrantySettlementService::class);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $settlements->choose($customer, $claim->public_id, 'refund');
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($unit->stockItem->fresh()->retired_at);
        $this->assertSame('refunded', $claim->fresh()->settlement_state);
        $credit = StoreBalanceEntry::where('source_type', 'order_refund')->sole();
        $activity = app(StoreBalanceActivityService::class)->detail($customer, 'entry', $credit->id);
        $this->assertSame('#'.$unit->order->invoice_number, $activity->item->reference);
        $this->assertSame(16000, $activity->relatedItem->amount);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(16000, $ledger->snapshot($customer)->balance());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_external_refund_stays_pending_until_the_exact_retail_transfer_is_confirmed(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        $settlements = app(WarrantySettlementService::class);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $this->assertSame('refund_pending', $claim->fresh()->settlement_state);
        $refund = OrderRefund::where('warranty_claim_id', $claim->id)->sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-bank-reference', 'success');
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-bank-reference', 'success');
        $this->assertSame('refunded', $claim->fresh()->settlement_state);
        $this->assertSame('confirmed', $refund->fresh()->state);
        $this->assertSame(PaymentStatus::Paid, $unit->order->payments()->sole()->status);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertNotNull($unit->stockItem->fresh()->retired_at);
    }

    public function test_external_full_refund_after_confirmed_partial_registers_the_unclaimed_remainder(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $payment = $order->payments()->sole();
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        $settlements = app(WarrantySettlementService::class);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $partial = OrderRefund::where('warranty_claim_id', $claim->id)->sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $settlements->confirmExternalRefund($owner, $partial->id, 8000, 'IDR', 'synthetic-partial-transfer', 'success');
        $this->assertSame('confirmed', $partial->fresh()->state);

        $review = ManualFulfillmentReview::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 16000,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => 'synthetic-review-reference',
        ]);

        $resolved = app(ManualFulfillmentResolutionService::class)->resolve($review, $owner, 'refund', 'Full refund of the unclaimed remainder.', 'synthetic-full-transfer', 'success');
        $replay = app(ManualFulfillmentResolutionService::class)->resolve($review->fresh(), $owner, 'refund', 'Replay.', 'synthetic-full-transfer', 'success');

        $this->assertSame(ManualFulfillmentReview::STATE_REFUNDED, $resolved->state);
        $this->assertSame(ManualFulfillmentReview::STATE_REFUNDED, $replay->state);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(2, OrderRefund::count());
        $remainder = OrderRefund::whereNull('warranty_claim_id')->whereNull('supplier_fulfillment_id')->sole();
        $this->assertSame(8000, $remainder->amount);
        $this->assertSame('IDR', $remainder->currency);
        $this->assertSame('confirmed', $remainder->state);
        $this->assertNotNull($remainder->confirmed_at);
        $this->assertSame($owner->id, (int) $remainder->actor_user_id);
        $this->assertSame(16000, (int) OrderRefund::where('payment_id', $payment->id)->where('state', 'confirmed')->sum('amount'));
        $this->assertSame(0, OrderRefund::whereIn('state', ['requested', 'uncertain'])->count());
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.remainder_refund_confirmed', 'user_id' => $owner->id]);
    }

    public function test_a_pending_registered_refund_blocks_the_full_external_refund(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $payment = $order->payments()->sole();
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        $pending = OrderRefund::where('warranty_claim_id', $claim->id)->sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $review = ManualFulfillmentReview::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 16000,
            'currency' => 'IDR',
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
            'provider_reference' => 'synthetic-review-reference',
        ]);

        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($payment);
            $this->fail('A pending registered refund allowed the full Order refund.');
        } catch (InvalidOrderTransition $exception) {
            $this->assertSame('Confirm or recover every registered external refund before refunding the full Order.', $exception->getMessage());
        }
        try {
            app(ManualFulfillmentResolutionService::class)->resolve($review, $owner, 'refund', 'Full refund attempt.', 'synthetic-full-transfer', 'success');
            $this->fail('The review resolution bypassed a pending registered refund.');
        } catch (InvalidOrderTransition $exception) {
            $this->assertSame('Confirm or recover every registered external refund before refunding the full Order.', $exception->getMessage());
        }

        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $review->fresh()->state);
        $this->assertSame('requested', $pending->fresh()->state);
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_historical_unknown_replacement_blocks_competing_refunds(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        $claim->update(['settlement_choice' => 'replacement', 'settlement_state' => 'replacement_unknown']);
        $attempt = $unit->purchase->replicate();
        $attempt->forceFill([
            'reference' => $claim->public_id, 'warranty_claim_id' => $claim->id,
            'supplier_order_id' => null,
            'state' => 'ambiguous', 'quantity' => 1, 'observed_quantity' => 0,
            'expected_cost_micros' => 300_000, 'actual_cost_micros' => null,
            'committed_at' => null, 'warranty_hold_active' => true, 'warranty_reserved_cost_micros' => 300_000,
        ])->save();
        foreach ([
            fn () => app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund'),
            fn () => app(OrderLifecycle::class)->applyPaymentRefunded($unit->order->payments()->sole()),
        ] as $action) {
            try {
                $action();
                $this->fail('Uncertain historical acquisition allowed competing buyer compensation.');
            } catch (InvalidOrderTransition) {
                $this->assertSame(0, OrderRefund::count());
            }
        }
        $this->assertSame('replacement_unknown', $claim->fresh()->settlement_state);
        $this->assertTrue((bool) $attempt->fresh()->warranty_hold_active);
        Http::assertNothingSent();
    }

    public function test_historical_supplier_reversal_never_recredits_or_reopens_refunded_credentials(): void
    {
        [$customer, $unit] = $this->fixture();
        $payment = $unit->order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'debit');
        $claim = $this->approvedHistoricalClaim($customer, $unit);
        $settlements = app(WarrantySettlementService::class);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $retiredAt = $unit->stockItem->fresh()->retired_at;
        $entryCount = StoreBalanceEntry::count();
        foreach (['reversed', 'approved'] as $state) {
            $claim->update(['state' => $state, 'provider_decision' => $state]);
            $settlements->choose($customer, $claim->public_id, 'refund');
            $this->assertSame('refunded', $claim->fresh()->settlement_state);
            $this->assertSame(8000, $ledger->snapshot($customer)->balance());
            $this->assertSame($entryCount, StoreBalanceEntry::count());
            $this->assertSame(1, OrderRefund::count());
            $this->assertTrue($retiredAt->equalTo($unit->stockItem->fresh()->retired_at));
        }
        Http::assertNothingSent();
    }

    private function missingFixture(bool $empty = false): array
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $order->update(['order_status' => OrderStatus::Processing]);
        $purchase = $unit->purchase;
        $purchase->update(['state' => 'settled', 'observed_quantity' => $empty ? 0 : 1, 'actual_cost_micros' => $empty ? 0 : 300_000]);
        $purchase->fulfillment->update(['state' => 'review']);
        if ($empty) {
            $stock = $unit->stockItem;
            $unit->delete();
            $stock->delete();
        }

        return [$customer, $order, $purchase->fulfillment];
    }

    public function test_a_proven_missing_unit_refunds_only_its_retail_price_and_preserves_delivered_accounts(): void
    {
        [$customer, $order] = $this->missingFixture();
        $payment = $order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $order->id, 'debit');
        $refunds = app(MissingFulfillmentRefundService::class);
        $refunds->request($customer, $order->orderItems()->sole()->id);
        $refunds->request($customer, $order->orderItems()->sole()->id);
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(8000, OrderRefund::sole()->amount);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, ProductStockItem::whereNull('retired_at')->count());
        Http::assertNothingSent();
    }

    public function test_missing_external_refund_requires_confirmed_transfer_before_order_completion(): void
    {
        [$customer, $order, $fulfillment] = $this->missingFixture();
        $refund = app(MissingFulfillmentRefundService::class)->request($customer, $order->orderItems()->sole()->id);
        $this->assertSame('requested', $refund->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $settlements = app(WarrantySettlementService::class);
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'uncertain');
        $this->assertSame('uncertain', $refund->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'success');
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'success');
        $this->assertSame('confirmed', $refund->fresh()->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertSame(1, ProductStockItem::whereNull('retired_at')->count());
        $this->assertSame(1, $fulfillment->fresh()->refunded_quantity);
        Http::assertNothingSent();
    }

    public function test_when_no_account_was_acquired_the_full_missing_refund_does_not_publish_a_successful_sale(): void
    {
        [$customer, $order] = $this->missingFixture(empty: true);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $order->id, 'debit');
        $payment = $order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        app(MissingFulfillmentRefundService::class)->request($customer, $order->orderItems()->sole()->id);
        $this->assertSame(16000, $ledger->snapshot($customer)->balance());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(0, SalesProofPost::count());
        Http::assertNothingSent();
    }
}
