<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendUsdtTopUpReceipt;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ProductStockItem;
use App\Models\SupplierAccountUnit;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramOrderIntents;
use App\Services\UsdtBalance\CustomerUsdtActivityService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\UsdtOrderRefundService;
use App\Services\UsdtBalance\UsdtRefund;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsUsdtRefundFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class UsdtOrderRefundTest extends NonTransactionalDatabaseTestCase
{
    use BuildsUsdtRefundFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_warranty_then_full_refund_returns_only_original_micro_remainder_without_repricing(): void
    {
        [$customer, $unit, $payment, $debit, $claim] = $this->usdtRefundFixture();
        $settlements = app(WarrantySettlementService::class);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $settlements->choose($customer, $claim->public_id, 'refund');
        $refund = OrderRefund::sole();
        $this->assertSame(1_000_001, $refund->amount);
        $this->assertSame('USDT', $refund->currency);
        $this->assertSame('confirmed', $refund->state);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(4_000_000, $this->balance($customer));
        $this->assertSame(1, ProductStockItem::where('order_item_id', $unit->order_item_id)->whereNull('retired_at')->count());
        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::IdrOnly])->save();
        $unit->orderItem->product->update(['usdt_price_amount' => 99_000_000, 'price_amount' => 1]);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame([1_000_001, 1_000_000], $this->refundEntries()->orderBy('id')->pluck('amount_micro')->all());
        $this->assertSame([$debit->entryId, $debit->entryId], $this->refundEntries()->orderBy('id')->pluck('original_entry_id')->all());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, DB::table('store_balance_entries')->count());
        $events = DB::table('customer_usdt_balance_accounting_events')->whereIn('customer_usdt_balance_entry_id', $this->refundEntries()->pluck('id'))->get();
        $this->assertSame(2_000_001, (int) $events->sum('amount_micro'));
        foreach ($events as $event) {
            $this->assertSame('USDT', $event->currency);
            $this->assertSame('usdt_sale_refund', $event->debit_account);
            $this->assertSame('customer_usdt_liability', $event->credit_account);
        }
        $history = app(CustomerUsdtActivityService::class)->firstPage($customer);
        $rows = array_values(array_filter($history->items, fn ($row) => $row->kind === 'refund_credit'));
        $this->assertSame([1_000_000, 1_000_001], array_column($rows, 'amountMicro'));
        $this->assertSame('#'.$unit->order->invoice_number, $rows[0]->reference);
        $guidance = app(TelegramOrderIntents::class)->orderInstruction($customer, $unit->order->fresh(), $payment->fresh());
        $this->assertStringContainsString('2.000001 USDT', $guidance);
        $this->assertStringNotContainsString('Rp ', $guidance);
        $receipts = TelegramOutboundEffect::where('effect_type', 'usdt.refund_receipt')->orderBy('created_at')->get();
        $this->assertSame(2, $receipts->count());
        $this->assertStringContainsString('1.000001 USDT', $receipts[0]->outcome_metadata['message']);
        $this->assertStringContainsString('1.000000 USDT', $receipts[1]->outcome_metadata['message']);
        Http::assertNothingSent();
    }

    public function test_refunding_every_warranty_unit_closes_payment_without_a_third_credit(): void
    {
        [$customer, $unit, $payment, , $first] = $this->usdtRefundFixture();
        $secondUnit = SupplierAccountUnit::where('order_id', $unit->order_id)->where('unit_number', 2)->sole();
        $second = $this->approvedUsdtClaim($customer, $secondUnit);
        foreach ([$first, $second] as $claim) {
            app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        }
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame(2, $this->refundEntries()->count());
    }

    public function test_missing_units_credit_exact_usdt_preserve_delivered_accounts_and_replay(): void
    {
        [$customer, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $service = app(MissingFulfillmentRefundService::class);
        $refund = $service->request($customer, $unit->order_item_id);
        $this->assertSame($refund->id, $service->request($customer, $unit->order_item_id)->id);
        $this->assertSame(1_000_000, $refund->amount);
        $this->assertSame('USDT', $refund->currency);
        $this->assertSame('confirmed', $refund->state);
        $this->assertSame(3_999_999, $this->balance($customer));
        $this->assertNull($unit->stockItem->fresh()->retired_at);
        $this->assertSame(1, $unit->purchase->fulfillment->fresh()->refunded_quantity);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame([1_000_000, 1_000_001], $this->refundEntries()->orderBy('id')->pluck('amount_micro')->all());
    }

    public function test_unverified_and_inflated_per_unit_entitlements_never_credit(): void
    {
        [$customer, $unit, , , $claim] = $this->usdtRefundFixture();
        $claim->update(['verified_at' => null]);
        $this->denied(fn () => app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund'));
        $claim->update(['verified_at' => now()]);
        $unit->update(['retail_amount' => 1_500_000]);
        $this->denied(fn () => app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund'));
        $this->assertSame(2_999_999, $this->balance($customer));
        $this->assertSame(0, OrderRefund::count());
        $this->assertSame(0, $this->refundEntries()->count());
    }

    public function test_foreign_order_customer_currency_payment_and_debit_fail_closed(): void
    {
        [$customer, $unit, $payment, $debit] = $this->usdtRefundFixture();
        [$foreign, $otherUnit, $otherPayment, $otherDebit] = $this->usdtRefundFixture();
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $source = fn ($customerId, $orderId, $paymentId, $entryId) => new UsdtRefund($customerId,
            UsdtMicroAmount::fromUnits(2_000_001), $orderId, $orderId, $entryId, $paymentId, fullOrder: true);
        foreach ([
            $source($customer->id, $otherUnit->order_id, $otherPayment->id, $otherDebit->entryId),
            $source($customer->id, $unit->order_id, $otherPayment->id, $debit->entryId),
            $source($customer->id, $unit->order_id, $payment->id, $otherDebit->entryId),
            $source($foreign->id, $unit->order_id, $payment->id, $debit->entryId),
        ] as $i => $request) {
            $this->denied(fn () => $ledger->refundEntitlement($customer, $request, 'foreign-'.$i));
        }
        $payment->update(['currency' => 'IDR']);
        $this->denied(fn () => app(OrderLifecycle::class)->applyPaymentRefunded($payment));
        $payment->update(['currency' => 'USDT', 'amount' => 2_000_000]);
        $this->denied(fn () => app(OrderLifecycle::class)->applyPaymentRefunded($payment));
        $this->assertSame(0, $this->refundEntries()->count());
        $this->assertSame(2_999_999, $this->balance($customer));
    }

    public function test_ambiguous_supplier_acquisition_blocks_full_and_missing_refunds(): void
    {
        [$customer, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $unit->purchase->update(['state' => 'ambiguous']);
        $this->denied(fn () => app(MissingFulfillmentRefundService::class)->request($customer, $unit->order_item_id));
        $this->denied(fn () => app(OrderLifecycle::class)->applyPaymentRefunded($payment));
        $this->assertSame(0, $this->refundEntries()->count());
        $this->assertSame(0, OrderRefund::count());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_internal_refund_cannot_be_confirmed_as_external_or_consume_external_evidence(): void
    {
        [$customer, $unit, $payment, , $claim] = $this->usdtRefundFixture();
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        $refund = OrderRefund::sole();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->denied(fn () => app(WarrantySettlementService::class)->confirmExternalRefund($owner, $refund->id, $refund->amount, 'USDT', 'external-transfer', 'success'));
        $refund->update(['external_reference' => 'external-transfer', 'external_reference_hash' => hash('sha256', 'external-transfer')]);
        $this->denied(fn () => app(OrderLifecycle::class)->applyPaymentRefunded($payment));
        $this->assertSame(4_000_000, $this->balance($customer));
        $this->assertSame(1, $this->refundEntries()->count());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_partial_replay_payload_conflict_and_full_cap_preserve_original_credit(): void
    {
        [$customer, $unit, $payment, $debit, $claim] = $this->usdtRefundFixture();
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        $refund = OrderRefund::sole();
        $source = new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits(1_000_001),
            $refund->id, $unit->order_id, $debit->entryId, $payment->id);
        $ledger = app(CustomerUsdtBalanceLedger::class);
        $original = $ledger->refundEntitlement($customer, $source, 'order-refund:'.$refund->id);
        $this->assertFalse($original->applied);
        $changed = new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits(1_000_000),
            $refund->id, $unit->order_id, $debit->entryId, $payment->id);
        $this->denied(fn () => $ledger->refundEntitlement($customer, $changed, 'order-refund:'.$refund->id));
        foreach ([999_999, 1_000_001] as $amount) {
            $full = new UsdtRefund($customer->id, UsdtMicroAmount::fromUnits($amount),
                $unit->order_id, $unit->order_id, $debit->entryId, $payment->id, fullOrder: true);
            $this->denied(fn () => $ledger->refundEntitlement($customer, $full, 'bad-full-'.$amount));
        }
        $this->assertSame(4_000_000, $this->balance($customer));
        $this->assertSame(1, $this->refundEntries()->count());
    }

    public function test_operator_internal_refund_needs_no_external_transfer_and_replays(): void
    {
        [$customer, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $review = ManualFulfillmentReview::create([
            'order_id' => $unit->order_id, 'payment_id' => $payment->id, 'amount' => $payment->amount,
            'currency' => 'USDT', 'reason' => 'supplier_fulfillment', 'state' => 'pending',
        ]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(ManualFulfillmentResolutionService::class);
        $service->resolve($review, $owner, 'refund', 'Verified fulfillment failure.');
        $service->resolve($review, $owner, 'refund', 'Lost response replay.');
        $this->assertSame('refunded', $review->fresh()->state);
        $this->assertNull($review->fresh()->refund_provider_reference);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame(1, $this->refundEntries()->count());
    }

    public function test_refund_receipt_is_rediscovered_after_lost_queue_wakeup_without_another_credit(): void
    {
        [$customer, , $payment] = $this->usdtRefundFixture();
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        Queue::fake();
        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();
        Queue::assertPushed(SendUsdtTopUpReceipt::class, 1);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame(1, $this->refundEntries()->count());
    }

    public function test_claim_from_another_order_cannot_authorize_the_original_order_refund(): void
    {
        [$customer, $unit, , , $claim] = $this->usdtRefundFixture();
        $other = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USDT']);
        $claim->update(['order_id' => $other->id]);
        $this->denied(fn () => app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund'));
        $this->assertSame(2_999_999, $this->balance($customer));
        $this->assertSame(0, $this->refundEntries()->count());
        $this->assertSame(0, OrderRefund::count());
        $this->assertNull($unit->stockItem->fresh()->retired_at);
    }

    public function test_missing_entitlement_cannot_use_an_item_from_another_order(): void
    {
        [$customer, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $other = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USDT']);
        $unit->orderItem->update(['order_id' => $other->id]);
        $fulfillment = $unit->purchase->fulfillment;
        $fulfillment->update(['state' => 'refund_pending']);
        $refund = OrderRefund::create([
            'order_id' => $unit->order_id, 'payment_id' => $payment->id, 'customer_id' => $customer->id,
            'supplier_fulfillment_id' => $fulfillment->id, 'missing_quantity' => 1,
            'amount' => 1_000_000, 'currency' => 'USDT', 'state' => 'requested',
        ]);
        $this->denied(fn () => app(UsdtOrderRefundService::class)->partial($customer, $refund));
        $this->assertSame(2_999_999, $this->balance($customer));
        $this->assertSame(0, $this->refundEntries()->count());
        $this->assertSame('requested', $refund->fresh()->state);
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Unauthorized USDT refund succeeded.');
        } catch (InvalidOrderTransition|InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function refundEntries()
    {
        return CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::RefundCredit);
    }

    private function balance(Customer $customer): int
    {
        return app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro();
    }
}
