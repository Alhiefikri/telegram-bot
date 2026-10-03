<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessWarrantyClaim;
use App\Models\Customer;
use App\Models\OrderRefund;
use App\Models\StoreBalanceEntry;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierPurchase;
use App\Models\SupplierWebhookEvent;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\Customer\CustomerOrderClaimService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\BuyMailWebhookService;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyAccountService;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyReplacementService;
use App\Services\Warranty\WarrantySettlementService;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class WarrantyClaimTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBuyMailWarrantyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    public function test_claim_is_private_submitted_once_and_pending_until_supplier_verifies_it(): void
    {
        [$customer, $unit] = $this->fixture();
        $service = app(WarrantyClaimService::class);
        $claim = $service->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        $again = $service->submit($customer, $unit->public_id, $this->proof(), 'Retry click');
        $this->assertSame($claim->id, $again->id);
        $this->assertSame('received', $claim->state);
        Queue::assertPushedOn('supplier-refunds', ProcessWarrantyClaim::class);
        $this->assertStringNotContainsString($this->proof()->bytes, Storage::disk('local')->get($claim->proof_path));
        $this->assertArrayNotHasKey('proof_path', $claim->toArray());
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;
            }

            return Http::response(['account' => [
                'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refund_pending', 'price' => 4800,
                'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'pending'],
            ]], $request->method() === 'POST' ? 202 : 200);
        });
        $service->process($claim->id);
        $service->process($claim->id);
        $this->assertSame(1, $posts);
        $this->assertSame('pending', $claim->fresh()->state);
        $this->assertSame('awaiting_verification', $claim->fresh()->settlement_state);
        $this->assertNull($unit->stockItem->fresh()->retired_at);
    }

    private function syncReplacementSource(SupplierAccountUnit $unit): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'id' => '123', 'currency' => 'IDR', 'balance' => 100000, 'price_per_account' => 4800,
            'stock_available' => 10, 'refunds_enabled' => true, 'refund_window_minutes' => 120,
            'limits' => ['max_quantity_per_order' => 50],
        ]));
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($unit->supplier->fresh()));
    }

    private function proof(): RefundProof
    {
        return RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='));
    }

    public function test_a_documented_rate_rejection_does_not_turn_an_unsubmitted_claim_into_an_unknown_upload(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60']));
        app(WarrantyClaimService::class)->process($claim->id);
        $this->assertSame('received', $claim->fresh()->state);
        $this->assertNull($claim->fresh()->dispatched_at);
        $this->assertSame('rate_limited', $claim->fresh()->last_error);
    }

    public function test_verified_guest_claim_remains_usable_after_the_buyer_links_the_order_to_their_account(): void
    {
        [$guest, $unit] = $this->fixture();
        $guest->update(['telegram_id' => null, 'email' => null]);
        $key = app(StorefrontOrderAccess::class)->issue($unit->order);
        $claim = app(WarrantyClaimService::class)->submit($guest, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        app(WarrantyClaimService::class)->process($claim->id);
        $buyer = Customer::factory()->create(['email' => 'linked-buyer@example.test', 'telegram_id' => 790003]);
        $linked = app(CustomerOrderClaimService::class)->claim($buyer, $unit->order->invoice_number, $key);
        $this->assertNotNull($linked);
        app(WarrantySettlementService::class)->choose($buyer, $claim->public_id, 'refund');
        $this->assertSame($buyer->id, $claim->fresh()->customer_id);
        $this->assertSame($buyer->id, OrderRefund::where('warranty_claim_id', $claim->id)->sole()->customer_id);
        $this->assertSame('refund_pending', $claim->fresh()->settlement_state);
    }

    public function test_operator_can_accept_an_already_acquired_replacement_price_change_without_buying_again(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        app(WarrantyClaimService::class)->process($claim->id);
        $this->syncReplacementSource($unit);
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'replacement');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['order' => [
            'id' => 'ORD2', 'reference' => $request['reference'], 'status' => 'completed',
            'quantity_requested' => 1, 'quantity_delivered' => 1, 'unit_price' => 4900, 'amount_charged' => 4900,
            'currency' => 'IDR', 'accounts' => [[
                'id' => 'PR2', 'order_id' => 'ORD2', 'email' => 'replacement@gmail.com', 'password' => 'synthetic-replacement-password',
                'price' => 4900, 'status' => 'active', 'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(),
            ]],
        ]], 201));
        $replacements = app(WarrantyReplacementService::class);
        $replacements->process($claim->id);
        $this->assertSame('replacement_review', $claim->fresh()->settlement_state);
        $this->assertSame(95_100_000_000, $unit->supplier->fresh()->balance_micros);
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $replacements->approveAcquired($actor, $claim->id, 4900);
        $replacements->approveAcquired($actor, $claim->id, 4900);
        $this->assertSame('replaced', $claim->fresh()->settlement_state);
        $this->assertSame(95_100_000_000, $unit->supplier->fresh()->balance_micros);
        $this->assertSame(1, SupplierAccountUnit::where('replaces_unit_id', $unit->id)->count());
        $this->assertSame(0, OrderRefund::count());
        Http::assertSentCount(1);
    }

    public function test_another_customer_and_an_expired_deadline_cannot_open_a_claim(): void
    {
        [$customer, $unit] = $this->fixture();
        $service = app(WarrantyClaimService::class);
        try {
            $service->submit(Customer::factory()->create(), $unit->public_id, $this->proof(), 'Wrong password');
            $this->fail('Another customer opened a claim.');
        } catch (InvalidOrderTransition) {
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
        $unit->update(['refund_deadline' => now()->subSecond()]);
        try {
            $service->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
            $this->fail('An expired claim was accepted.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(0, WarrantyClaim::count());
            $this->assertSame([], Storage::disk('local')->allFiles());
            Http::assertNothingSent();
        }
    }

    public function test_an_uncertain_refund_upload_is_recovered_by_get_without_uploading_again(): void
    {
        [$customer, $unit] = $this->fixture();
        $service = app(WarrantyClaimService::class);
        $claim = $service->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        $posts = $gets = 0;
        Http::fake(function ($request) use (&$posts, &$gets) {
            if ($request->method() === 'POST') {
                $posts++;
                throw new ConnectionException('Synthetic transport failure.');
            }
            $gets++;

            return Http::response(['account' => [
                'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
                'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
            ]]);
        });
        $service->process($claim->id);
        $this->assertSame('submission_unknown', $claim->fresh()->state);
        $this->travel(2)->minutes();
        $service->process($claim->id);
        $service->process($claim->id);
        $this->assertSame(1, $posts);
        $this->assertSame(1, $gets);
        $this->assertSame('approved', $claim->fresh()->state);
        $this->assertSame('awaiting_choice', $claim->fresh()->settlement_state);
        $this->assertNull($claim->fresh()->settlement_choice);
    }

    public function test_buyer_refund_credits_retail_once_and_full_order_refund_only_credits_the_remainder(): void
    {
        [$customer, $unit] = $this->fixture();
        $payment = $unit->order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'initial-funds', 'initial-funds');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'order-debit');
        $claims = app(WarrantyClaimService::class);
        $claim = $claims->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        $claims->process($claim->id);
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
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        app(WarrantyClaimService::class)->process($claim->id);
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

    public function test_verified_replacement_buys_one_new_account_once_without_charging_the_buyer(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        app(WarrantyClaimService::class)->process($claim->id);
        $settlements = app(WarrantySettlementService::class);
        $this->syncReplacementSource($unit);
        $settlements->choose($customer, $claim->public_id, 'replacement');
        $this->syncReplacementSource($unit);
        $settlements->choose($customer, $claim->public_id, 'replacement');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame(1, $request['quantity']);
            $this->assertNotSame('original-reference', $request['reference']);
            $this->assertTrue($request->hasHeader('Idempotency-Key', $request['reference']));

            return Http::response(['order' => [
                'id' => 'ORD2', 'reference' => $request['reference'], 'status' => 'completed',
                'quantity_requested' => 1, 'quantity_delivered' => 1, 'unit_price' => 4800,
                'amount_charged' => 4800, 'currency' => 'IDR', 'accounts' => [[
                    'id' => 'PR2', 'order_id' => 'ORD2', 'email' => 'replacement@gmail.com',
                    'password' => 'synthetic-replacement-password', 'price' => 4800, 'status' => 'active',
                    'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(),
                ]],
            ]], 201);
        });
        $replacements = app(WarrantyReplacementService::class);
        $replacements->process($claim->id);
        $replacements->process($claim->id);
        Http::assertSentCount(1);
        $this->assertSame('replaced', $claim->fresh()->settlement_state);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertSame(0, OrderRefund::count());
        $this->assertNotNull($unit->stockItem->fresh()->retired_at);
        $replacement = SupplierAccountUnit::where('external_id', 'PR2')->sole();
        $this->assertSame(8000, $replacement->retail_amount);
        $this->assertSame('replacement@gmail.com', $replacement->stockItem->credential_payload['email']);
        $this->assertSame(0, $unit->supplier->fresh()->reserved_balance_micros);
        $this->assertSame(95_200_000_000, $unit->supplier->fresh()->balance_micros);
    }

    public function test_durable_claims_are_rediscovered_when_the_original_queue_wakeup_is_lost(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Queue::fake();
        $this->artisan('suppliers:recover-warranty', ['--limit' => 5])->assertSuccessful();
        Queue::assertPushed(ProcessWarrantyClaim::class, fn ($job) => $job->claimId === $claim->id);
        Http::assertNothingSent();
    }

    public function test_confirmation_is_explicit_and_blocks_a_competing_refund_claim(): void
    {
        [$customer, $unit] = $this->fixture();
        Http::fake(function ($request) {
            $this->assertStringEndsWith('/accounts/PR1/confirm', $request->url());

            return Http::response(['account' => [
                'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'completed', 'price' => 4800,
                'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => null,
            ]]);
        });
        $accounts = app(WarrantyAccountService::class);
        $accounts->confirm($customer, $unit->public_id);
        $accounts->confirm($customer, $unit->public_id);
        Http::assertSentCount(1);
        $this->assertSame('completed', $unit->fresh()->provider_state);
        $this->expectException(InvalidOrderTransition::class);
        app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
    }

    public function test_a_proven_rejected_replacement_can_be_changed_to_refund_without_double_compensation(): void
    {
        [$customer, $unit] = $this->fixture();
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        app(WarrantyClaimService::class)->process($claim->id);
        $settlements = app(WarrantySettlementService::class);
        $this->syncReplacementSource($unit);
        $settlements->choose($customer, $claim->public_id, 'replacement');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['error' => ['code' => 'purchase_failed']], 500));
        app(WarrantyReplacementService::class)->process($claim->id);
        $this->assertSame('replacement_failed', $claim->fresh()->settlement_state);
        $settlements->choose($customer, $claim->public_id, 'refund');
        $settlements->choose($customer, $claim->public_id, 'refund');
        $this->assertSame('refund_pending', $claim->fresh()->settlement_state);
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(0, $unit->supplier->fresh()->reserved_balance_micros);
        $this->assertSame(100_000_000_000, $unit->supplier->fresh()->balance_micros);
        Http::assertSentCount(1);
    }

    public function test_supplier_reversal_after_buyer_refund_never_debits_buyer_or_reopens_the_retired_account(): void
    {
        [$customer, $unit] = $this->fixture();
        $payment = $unit->order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 16000, StoreBalanceEntryKind::TopUpCredit, 'test', 'initial', 'initial');
        $ledger->debit($customer, 16000, 'order', $unit->order_id, 'debit');
        $claims = app(WarrantyClaimService::class);
        $claim = $claims->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        $decision = 'approved';
        Http::fake(function () use (&$decision) {
            return Http::response(['account' => [
                'id' => 'PR1', 'order_id' => 'ORD1', 'status' => $decision === 'approved' ? 'refunded' : 'completed', 'price' => 4800,
                'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => $decision],
            ]]);
        });
        $claims->process($claim->id);
        app(WarrantySettlementService::class)->choose($customer, $claim->public_id, 'refund');
        $decision = 'reversed';
        $this->assertTrue($claims->refreshNow($claim->id));
        $this->assertSame('reversed', $claim->fresh()->state);
        $this->assertSame('refunded', $claim->fresh()->settlement_state);
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertNotNull($unit->stockItem->fresh()->retired_at);
        $this->assertCount(2, $claim->fresh()->decision_history);
        $this->assertSame(1, OrderRefund::count());
        $staleEvent = SupplierWebhookEvent::create([
            'supplier_id' => $unit->supplier_id, 'external_event_id' => 'stale-approved-1',
            'event_type' => 'refund.approved', 'resource_id' => 'PR1', 'state' => 'received',
            'next_attempt_at' => now(), 'payload' => ['id' => 'stale-approved-1', 'type' => 'refund.approved', 'data' => ['account' => ['id' => 'PR1', 'refund' => ['status' => 'approved']]]],
        ]);
        app(BuyMailWebhookService::class)->process($staleEvent->id);
        app(BuyMailWebhookService::class)->process($staleEvent->id);
        $this->assertSame('processed', $staleEvent->fresh()->state);
        $this->assertSame('reversed', $claim->fresh()->state);
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(1, OrderRefund::count());
    }

    public function test_unknown_replacement_blocks_refund_switch_and_full_order_refund(): void
    {
        [$customer, $unit] = $this->fixture();
        $claims = app(WarrantyClaimService::class);
        $claim = $claims->submit($customer, $unit->public_id, $this->proof(), 'Wrong password');
        Http::fake(fn () => Http::response(['account' => [
            'id' => 'PR1', 'order_id' => 'ORD1', 'status' => 'refunded', 'price' => 4800,
            'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(), 'refund' => ['status' => 'approved'],
        ]]));
        $claims->process($claim->id);
        $settlements = app(WarrantySettlementService::class);
        $this->syncReplacementSource($unit);
        $settlements->choose($customer, $claim->public_id, 'replacement');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Synthetic timeout.'));
        app(WarrantyReplacementService::class)->process($claim->id);
        $this->assertSame('replacement_unknown', $claim->fresh()->settlement_state);
        $this->assertSame(4_800_000_000, $unit->supplier->fresh()->reserved_balance_micros);
        foreach ([fn () => $settlements->choose($customer, $claim->public_id, 'refund'), fn () => app(OrderLifecycle::class)->applyPaymentRefunded($unit->order->payments()->sole())] as $action) {
            try {
                $action();
                $this->fail('Uncertain acquisition allowed competing buyer compensation.');
            } catch (InvalidOrderTransition) {
                $this->assertSame(0, OrderRefund::count());
            }
        }
        Http::assertSentCount(0); // Connection exception produces no recorded response.
        $attempt = SupplierPurchase::where('warranty_claim_id', $claim->id)->sole();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($attempt) {
            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/orders/ORD2', $request->url());

            return Http::response(['order' => [
                'id' => 'ORD2', 'reference' => $attempt->reference, 'status' => 'completed',
                'quantity_requested' => 1, 'quantity_delivered' => 1, 'unit_price' => 4800, 'amount_charged' => 4800, 'currency' => 'IDR',
                'accounts' => [[
                    'id' => 'PR2', 'order_id' => 'ORD2', 'email' => 'replacement@gmail.com', 'password' => 'synthetic-replacement',
                    'price' => 4800, 'status' => 'active', 'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(),
                ]],
            ]]);
        });
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        app(WarrantyReplacementService::class)->recoverOrder($actor, $claim->id, 'ORD2');
        $this->assertSame('replaced', $claim->fresh()->settlement_state);
        $this->assertSame(0, $unit->supplier->fresh()->reserved_balance_micros);
        $this->assertSame(95_200_000_000, $unit->supplier->fresh()->balance_micros);
        Http::assertSentCount(1);
    }
}
