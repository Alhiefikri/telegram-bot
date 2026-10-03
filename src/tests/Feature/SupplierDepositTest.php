<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StoreBalanceEntry;
use App\Models\Supplier;
use App\Models\SupplierDeposit;
use App\Models\User;
use App\Services\Suppliers\SupplierDepositService;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierDepositTest extends NonTransactionalDatabaseTestCase
{
    public function test_supplier_funding_is_one_private_instruction_and_never_a_customer_credit(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(SupplierDepositService::class);
        $deposit = $service->create($supplier, 100000, 'deposit-intent-1', $owner);
        $again = $service->create($supplier, 100000, 'deposit-intent-1', $owner);
        $this->assertSame($deposit->id, $again->id);
        Http::fake(fn () => Http::response(['deposit' => ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()]], 201));
        $service->process($deposit->id);
        $service->process($deposit->id);
        Http::assertSentCount(1);
        $this->assertSame('pending', $deposit->fresh()->state);
        $this->assertSame('TOPUP1', $deposit->fresh()->external_id);
        $this->assertNull($supplier->fresh()->balance_micros);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertArrayNotHasKey('payment_url', $deposit->fresh()->toArray());
    }

    public function test_paid_evidence_wins_over_a_cancel_response_without_crediting_customer_money(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(SupplierDepositService::class);
        $deposit = $service->create($supplier, 100000, 'deposit-race-intent', $owner);
        $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()];
        Http::fake(fn () => Http::response(['deposit' => $row]));
        $service->process($deposit->id);
        $service->cancel($deposit->fresh(), $owner);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['deposit' => array_replace($row, ['status' => $request->method() === 'POST' ? 'cancelled' : 'paid'])]));
        $service->process($deposit->id);
        $this->assertSame('paid', $deposit->fresh()->state);
        $version = $supplier->fresh()->version;
        $service->reconcileKnownDeposit($supplier->fresh(), 'TOPUP1');
        $this->assertSame($version, $supplier->fresh()->version);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertNull($supplier->fresh()->balance_micros);
        Http::assertSentCount(3);
    }

    public function test_expired_cancel_dispatch_preserves_the_operator_operation_until_authoritative_terminal_evidence(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(SupplierDepositService::class);
        $deposit = $service->create($supplier, 100000, 'deposit-cancel-recovery', $owner);
        $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()];
        Http::fake(fn () => Http::response(['deposit' => $row]));
        $service->process($deposit->id);
        $service->cancel($deposit->fresh(), $owner);
        // A worker stopped after durably claiming cancellation, before HTTP.
        $deposit->fresh()->update(['state' => 'sending', 'lease_expires_at' => now()->subMinute()]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $cancelled = false;
        Http::fake(function ($request) use ($row, &$cancelled) {
            $this->assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') {
                $this->assertStringEndsWith('/deposits/TOPUP1/cancel', $request->url());
                $cancelled = true;
            }

            return Http::response(['deposit' => array_replace($row, ['status' => $cancelled ? 'cancelled' : 'pending'])]);
        });
        $service->process($deposit->id);
        $this->assertTrue($cancelled);
        $this->assertSame('cancelled', $deposit->fresh()->state);
        Http::assertSentCount(2);
    }

    public function test_pending_authoritative_read_does_not_erase_a_durable_cancel_request(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(SupplierDepositService::class);
        $deposit = $service->create($supplier, 100000, 'deposit-cancel-pending', $owner);
        $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()];
        Http::fake(fn () => Http::response(['deposit' => $row]));
        $service->process($deposit->id);
        $service->cancel($deposit->fresh(), $owner);
        $service->process($deposit->id);
        $this->assertSame('cancel_requested', $deposit->fresh()->state);
        $this->assertSame('cancel', $deposit->fresh()->operation);
        $this->assertNull($deposit->fresh()->claim_token);
        $this->assertNotNull($deposit->fresh()->next_attempt_at);
    }

    public function test_paid_webhook_read_during_create_cannot_claim_the_external_identity_of_an_unresolved_intent(): void
    {
        Queue::fake();
        $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123']);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service = app(SupplierDepositService::class);
        $deposit = $service->create($supplier, 100000, 'deposit-webhook-race', $owner);
        $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'paid', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()];
        $deferred = false;
        Http::fake(function ($request) use ($row, $service, $supplier, &$deferred) {
            $this->assertSame(0, DB::transactionLevel());
            if ($request->method() === 'POST') {
                // The provider sent a paid webhook before returning the create response.
                try {
                    $service->reconcileKnownDeposit($supplier, 'TOPUP1');
                } catch (SupplierUnavailable $exception) {
                    $this->assertSame('deposit_identity_unresolved', $exception->errorCode);
                    $deferred = true;
                }
            }

            return Http::response(['deposit' => $row]);
        });
        $service->process($deposit->id);
        $this->assertTrue($deferred);
        $this->assertSame(1, SupplierDeposit::count());
        $this->assertSame('TOPUP1', $deposit->fresh()->external_id);
        $this->assertSame('paid', $deposit->fresh()->state);
        $version = $supplier->fresh()->version;
        $reconciled = $service->reconcileKnownDeposit($supplier->fresh(), 'TOPUP1');
        $this->assertSame($deposit->id, $reconciled->id);
        $this->assertSame($version, $supplier->fresh()->version);
        $this->assertSame(0, StoreBalanceEntry::count());
        Http::assertSentCount(3);
    }

    public function test_a_stale_pending_read_cannot_reopen_a_terminal_deposit_after_a_new_intent_exists(): void
    {
        Queue::fake();
        foreach (['cancelled', 'expired'] as $terminal) {
            $supplier = Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key-'.$terminal, 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'is_active' => true, 'external_account_id' => '123-'.$terminal]);
            $owner = User::factory()->create(['role' => UserRole::Owner]);
            $service = app(SupplierDepositService::class);
            $deposit = $service->create($supplier, 100000, 'deposit-stale-read', $owner);
            $row = ['id' => 'TOPUP1', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'https://payments.example.test/topup1', 'expires_at' => now()->addHour()->utc()->toIso8601String()];
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response(['deposit' => $row]));
            $service->process($deposit->id);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $readCount = 0;
            $next = null;
            Http::fake(function ($request) use ($service, $supplier, $row, $terminal, $owner, &$readCount, &$next) {
                $this->assertSame('GET', $request->method());
                $this->assertSame(0, DB::transactionLevel());
                if (++$readCount === 1) {
                    // A newer authoritative observation settles while this read is in flight.
                    $service->reconcileKnownDeposit($supplier, 'TOPUP1');
                    $next = $service->create($supplier, 100000, 'deposit-next-intent', $owner);

                    return Http::response(['deposit' => $row]);
                }

                return Http::response(['deposit' => array_replace($row, ['status' => $terminal])]);
            });
            $service->reconcileKnownDeposit($supplier, 'TOPUP1');
            $this->assertSame($terminal, $deposit->fresh()->state);
            $this->assertSame('requested', $next->fresh()->state);
            Http::assertSentCount(2);
        }
        $this->assertSame(0, StoreBalanceEntry::count());
    }
}
