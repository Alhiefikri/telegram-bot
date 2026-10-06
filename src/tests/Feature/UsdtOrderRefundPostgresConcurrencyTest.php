<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\ManualFulfillmentReview;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\SupplierAccountUnit;
use App\Models\TelegramOutboundEffect;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\BuildsUsdtRefundFixture;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtOrderRefundPostgresConcurrencyTest extends TestCase
{
    use BuildsUsdtRefundFixture;
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('USDT refunds');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_duplicate_warranty_choices_post_one_verified_entitlement(): void
    {
        [$customer, , $payment, , $claim] = $this->usdtRefundFixture();
        $results = $this->runConcurrentWorkers(3, fn () => app(WarrantySettlementService::class)
            ->choose(Customer::findOrFail($customer->id), $claim->public_id, 'refund')->settlement_state);
        $this->assertWorkersSucceeded($results);
        $this->assertSame(['refunded', 'refunded', 'refunded'], array_column($results, 'value'));
        $this->assertSame(4_000_000, $this->balance($customer));
        $this->assertSame(1_000_001, (int) $this->refundEntries()->sum('amount_micro'));
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(1, TelegramOutboundEffect::where('effect_type', 'usdt.refund_receipt')->count());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_full_partial_and_actual_checkout_serialize_without_over_refund_or_deadlock(): void
    {
        [$customer, , $payment, $debit, $claim] = $this->usdtRefundFixture();
        $product = Product::factory()->create(['price_amount' => 15000, 'usdt_price_amount' => 2_000_000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'race-credential']);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $results = $this->runConcurrentWorkers(3, function (int $index) use ($customer, $payment, $claim, $cart): string {
            if ($index === 0) {
                try {
                    return app(WarrantySettlementService::class)->choose(Customer::findOrFail($customer->id), $claim->public_id, 'refund')->settlement_state;
                } catch (InvalidOrderTransition|InvalidArgumentException) {
                    return 'full_already_applied';
                }
            }
            if ($index === 1) {
                app(OrderLifecycle::class)->applyPaymentRefunded(Payment::findOrFail($payment->id));

                return 'full';
            }
            $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cart->id), CheckoutPaymentIntents::usdtBalance(), 'refund-race-checkout');
            Http::assertNothingSent();

            return (string) $order->id;
        });
        $this->assertWorkersSucceeded($results);
        $this->assertSame(3_000_000, $this->balance($customer));
        $this->assertSame(2_000_001, (int) $this->refundEntries()->where('original_entry_id', $debit->entryId)->sum('amount_micro'));
        $this->assertSame(2, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, DB::table('store_balance_entries')->count());
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(3_000_000, $this->balance($customer));
    }

    public function test_distinct_warranty_units_refund_at_most_original_micro_debit(): void
    {
        [$customer, $unit, $payment, $debit, $claim] = $this->usdtRefundFixture();
        $second = $this->approvedUsdtClaim($customer, SupplierAccountUnit::where('order_id', $unit->order_id)->where('unit_number', 2)->sole());
        $claims = [$claim, $second];
        $results = $this->runConcurrentWorkers(2, fn ($index) => app(WarrantySettlementService::class)
            ->choose(Customer::findOrFail($customer->id), $claims[$index]->public_id, 'refund')->settlement_state);
        $this->assertWorkersSucceeded($results);
        $this->assertSame(5_000_000, $this->balance($customer));
        $this->assertSame(2_000_001, (int) $this->refundEntries()->where('original_entry_id', $debit->entryId)->sum('amount_micro'));
        $this->assertSame(2, $this->refundEntries()->count());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_duplicate_missing_refunds_preserve_the_delivered_unit(): void
    {
        [$customer, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $results = $this->runConcurrentWorkers(3, fn () => app(MissingFulfillmentRefundService::class)
            ->request(Customer::findOrFail($customer->id), $unit->order_item_id)->id);
        $this->assertWorkersSucceeded($results);
        $ids = array_column($results, 'value');
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertSame(3_999_999, $this->balance($customer));
        $this->assertSame(1_000_000, (int) $this->refundEntries()->sum('amount_micro'));
        $this->assertNull($unit->stockItem->fresh()->retired_at);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_refund_receipt_failure_rolls_back_credit_accounting_and_lifecycle(): void
    {
        [$customer, , $payment] = $this->usdtRefundFixture();
        DB::unprepared("CREATE FUNCTION reject_usdt_refund_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.effect_type = 'usdt.refund_receipt' THEN RAISE EXCEPTION 'refund_receipt_failed'; END IF; RETURN NEW; END $$");
        DB::unprepared('CREATE TRIGGER reject_usdt_refund_receipt BEFORE INSERT ON telegram_outbound_effects FOR EACH ROW EXECUTE FUNCTION reject_usdt_refund_receipt()');
        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($payment);
            $this->fail('Receipt failure left a committed financial effect.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('refund_receipt_failed', $error->getMessage());
            $this->assertSame(2_999_999, $this->balance($customer));
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(0, $this->refundEntries()->count());
            $this->assertSame(2, DB::table('customer_usdt_balance_accounting_events')->count());
        } finally {
            DB::unprepared('DROP TRIGGER reject_usdt_refund_receipt ON telegram_outbound_effects');
            DB::unprepared('DROP FUNCTION reject_usdt_refund_receipt()');
        }
        app(OrderLifecycle::class)->applyPaymentRefunded($payment);
        $this->assertSame(5_000_000, $this->balance($customer));
    }

    public function test_currency_rollback_preserves_retained_usdt_review_history(): void
    {
        [, $unit, $payment] = $this->usdtRefundFixture(missing: true);
        $review = ManualFulfillmentReview::create([
            'order_id' => $unit->order_id, 'payment_id' => $payment->id, 'amount' => $payment->amount,
            'currency' => 'USDT', 'reason' => 'supplier_fulfillment', 'state' => 'pending',
        ]);
        $migration = require database_path('migrations/2026_10_06_180000_expand_fulfillment_review_currency_for_usdt_refunds.php');
        try {
            $migration->down();
            $this->fail('Rollback truncated a retained USDT review.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot shrink manual_fulfillment_reviews.currency', $error->getMessage());
            $this->assertSame('USDT', $review->fresh()->currency);
            $this->assertSame(2_000_001, $review->fresh()->amount);
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
