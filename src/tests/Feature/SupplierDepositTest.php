<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StoreBalanceEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\SupplierDepositService;
use Illuminate\Http\Client\Factory;
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
}
