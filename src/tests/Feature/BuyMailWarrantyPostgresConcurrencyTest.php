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
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\StoreBalanceEntry;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyAccountService;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyReplacementService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
final class BuyMailWarrantyPostgresConcurrencyTest extends TestCase
{
    use BuildsBuyMailWarrantyFixture;
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
        Storage::fake('local');
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

    public function test_refund_and_replacement_choice_race_commits_only_one_resolution(): void
    {
        [$customer, , $claim] = $this->verifiedClaim();
        $choose = function (string $choice) use ($customer, $claim): array {
            try {
                app(WarrantySettlementService::class)->choose(Customer::findOrFail($customer->id), $claim->public_id, $choice);

                return ['state' => $choice];
            } catch (InvalidOrderTransition) {
                return ['state' => 'blocked'];
            }
        };
        $this->race([fn () => $choose('refund'), fn () => $choose('replacement')]);
        $fresh = $claim->fresh();
        if ($fresh->settlement_choice === 'refund') {
            $this->assertSame(8000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
            $this->assertSame(1, OrderRefund::count());
        } else {
            $this->assertSame('replacement', $fresh->settlement_choice);
            $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
            $this->assertSame(0, OrderRefund::count());
        }
    }

    public function test_two_replacement_workers_buy_and_allocate_one_new_account(): void
    {
        [$customer, $unit, $claim] = $this->verifiedClaim();
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'replacement');
        $worker = function () use ($claim): array {
            Http::fake(function ($request) {
                usleep(200000);

                return Http::response(['order' => [
                    'id' => 'ORD2', 'reference' => $request['reference'], 'status' => 'completed',
                    'quantity_requested' => 1, 'quantity_delivered' => 1, 'unit_price' => 4800,
                    'amount_charged' => 4800, 'currency' => 'IDR', 'accounts' => [[
                        'id' => 'PR2', 'order_id' => 'ORD2', 'email' => 'replacement@gmail.com', 'password' => 'synthetic-password',
                        'price' => 4800, 'status' => 'active', 'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(),
                    ]],
                ]], 201);
            });
            app(WarrantyReplacementService::class)->process($claim->id);

            return ['posts' => Http::recorded(fn ($r) => $r->method() === 'POST')->count()];
        };
        $results = $this->race([$worker, $worker]);
        $this->assertSame(1, array_sum(array_column($results, 'posts')));
        $this->assertSame(1, SupplierPurchase::where('warranty_claim_id', $claim->id)->count());
        $this->assertSame(1, SupplierAccountUnit::where('replaces_unit_id', $unit->id)->count());
        $this->assertSame(95_200_000_000, $unit->supplier->fresh()->balance_micros);
        $this->assertSame(0, $unit->supplier->fresh()->reserved_balance_micros);
        $this->assertSame('replaced', $claim->fresh()->settlement_state);
    }

    public function test_two_claim_workers_upload_original_proof_once(): void
    {
        [$customer, $unit] = $this->fixture();
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
        $worker = function () use ($claim): array {
            Http::fake(function () {
                usleep(200000);

                return Http::response(['account' => [
                    'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refund_pending', 'price' => 4800,
                    'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'pending'],
                ]], 202);
            });
            app(WarrantyClaimService::class)->process($claim->id);

            return ['posts' => Http::recorded(fn ($request) => $request->method() === 'POST')->count()];
        };
        $results = $this->race([$worker, $worker]);
        $this->assertSame(1, array_sum(array_column($results, 'posts')));
        $this->assertSame('pending', $claim->fresh()->state);
        $this->assertSame('awaiting_verification', $claim->fresh()->settlement_state);
        $this->assertSame(0, OrderRefund::count());
    }

    public function test_two_operator_resubmits_produce_one_upload_and_one_audit_entry(): void
    {
        [$customer, $unit] = $this->fixture();
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
        $claim->update(['state' => 'submission_unknown', 'dispatched_at' => now()->subMinute(), 'last_error' => 'warranty_transport_unavailable', 'next_attempt_at' => now()->addMinute()]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $claimId = $claim->id;
        $ownerId = $owner->id;
        $worker = function () use ($claimId, $ownerId): array {
            $posts = 0;
            Http::fake(function ($request) use (&$posts) {
                if ($request->method() === 'POST') {
                    $posts++;
                    usleep(200000);

                    return Http::response(['account' => [
                        'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refund_pending', 'price' => 4800,
                        'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'pending'],
                    ]], 202);
                }

                return Http::response(['account' => [
                    'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'active', 'price' => 4800,
                    'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => null,
                ]]);
            });
            $service = app(WarrantyClaimService::class);
            try {
                $service->resubmit(User::findOrFail($ownerId), $claimId);
                $accepted = 1;
            } catch (InvalidOrderTransition) {
                $accepted = 0;
            }
            $service->process($claimId);

            return ['accepted' => $accepted, 'posts' => $posts];
        };
        $results = $this->race([$worker, $worker]);
        $this->assertSame(1, array_sum(array_column($results, 'accepted')));
        $this->assertSame(1, array_sum(array_column($results, 'posts')));
        $this->assertSame('pending', $claim->fresh()->state);
        $this->assertNull($claim->fresh()->claim_token);
        $this->assertSame(1, AuditLog::query()->where('action', 'warranty.claim_resubmitted')->count());
    }

    public function test_confirmation_and_claim_submission_race_cannot_both_close_and_request_warranty(): void
    {
        [$customer, $unit] = $this->fixture();
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $this->race([
            function () use ($customer, $unit, $proof): array {
                try {
                    app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');

                    return ['state' => 'claim'];
                } catch (InvalidOrderTransition) {
                    return ['state' => 'blocked'];
                }
            },
            function () use ($customer, $unit): array {
                Http::fake(fn () => Http::response(['account' => [
                    'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'completed', 'price' => 4800,
                    'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => null,
                ]]));
                try {
                    app(WarrantyAccountService::class)->confirm($customer, $unit->public_id);

                    return ['state' => 'confirmed'];
                } catch (InvalidOrderTransition) {
                    return ['state' => 'blocked'];
                }
            },
        ]);
        if (WarrantyClaim::exists()) {
            $this->assertNull($unit->fresh()->confirmation_state);
            $this->assertSame('active', $unit->fresh()->provider_state);
        } else {
            $this->assertSame('confirmed', $unit->fresh()->confirmation_state);
            $this->assertSame('completed', $unit->fresh()->provider_state);
        }
    }

    private function verifiedClaim(): array
    {
        [$customer, $unit] = $this->fixture();
        $unit->order->payments()->sole()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'debit');
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
        $claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);

        return [$customer, $unit, $claim];
    }

    public function test_duplicate_missing_unit_refunds_credit_one_entitlement_in_postgres(): void
    {
        [$customer, $unit] = $this->fixture();
        $order = $unit->order;
        $order->update(['order_status' => OrderStatus::Processing]);
        $order->payments()->sole()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $purchase = $unit->purchase;
        $purchase->update(['state' => 'settled', 'observed_quantity' => 1, 'actual_cost_micros' => 4_800_000_000]);
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
        $proof = RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $proof, 'Wrong password');
        $claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);
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
