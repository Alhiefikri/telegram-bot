<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class BuyMailAccountIdentityTest extends NonTransactionalDatabaseTestCase
{
    public function test_profile_cannot_retarget_historical_acquisitions_to_a_different_account(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        Http::fake(fn () => Http::response($this->profile('account-1')));
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->profile('account-2')));
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier->fresh()));
        $this->assertSame('account-1', $supplier->fresh()->external_account_id);
        $this->assertNull($supplier->fresh()->sync_expires_at);
        $this->assertSame('account_identity_mismatch', $supplier->fresh()->last_error);
    }

    public function test_two_connections_cannot_advertise_the_same_external_wallet_twice(): void
    {
        Queue::fake();
        $first = $this->supplier();
        $second = $this->supplier();
        Http::fake(fn () => Http::response($this->profile('same-account')));
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($first));
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($second));
        $this->assertNull($second->fresh()->external_account_id);
        $this->assertNull($second->fresh()->balance_micros);
    }

    private function supplier(): Supplier
    {
        return Supplier::forceCreate(['name' => 'Private source', 'provider' => 'buymail', 'api_key' => 'synthetic-key', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'currency_confirmed' => true, 'is_active' => true]);
    }

    public function test_rotating_a_key_must_verify_the_same_supplier_account_before_preserving_history(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $supplier->forceFill(['external_account_id' => 'account-1'])->save();
        $supplier->refresh();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        Http::fake(fn () => Http::response($this->profile('different-account')));
        $input = $supplier->only(['name', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'low_balance_threshold_micros']);
        $input += ['version' => $supplier->version, 'api_key' => 'new-synthetic-key'];
        try {
            app(SupplierManager::class)->save($supplier, $input, $actor, null);
            $this->fail('A replacement key retargeted the supplier history.');
        } catch (ValidationException) {
            $this->assertSame('synthetic-key', $supplier->fresh()->api_key);
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->profile('account-1')));
        app(SupplierManager::class)->save($supplier->fresh(), $input, $actor, null);
        $this->assertSame('new-synthetic-key', $supplier->fresh()->api_key);
        $this->assertSame('account-1', $supplier->fresh()->external_account_id);
    }

    public function test_verified_same_account_rotation_preserves_unresolved_wallet_holds_for_get_recovery(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $supplier->forceFill(['external_account_id' => 'account-1', 'balance_micros' => 100_000_000_000, 'reserved_balance_micros' => 4_800_000_000])->save();
        $supplier->refresh();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        Http::fake(fn () => Http::response($this->profile('account-1')));
        $input = $supplier->only(['name', 'provider', 'currency', 'currency_confirmed', 'exchange_rate_idr', 'is_active', 'low_balance_threshold_micros']);
        $input += ['version' => $supplier->version, 'api_key' => 'new-synthetic-key'];
        app(SupplierManager::class)->save($supplier, $input, $actor, null);
        $this->assertSame('new-synthetic-key', $supplier->fresh()->api_key);
        $this->assertSame(4_800_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(100_000_000_000, $supplier->fresh()->balance_micros);
    }

    private function profile(string $id): array
    {
        return ['id' => $id, 'currency' => 'IDR', 'balance' => 100000, 'price_per_account' => 4800, 'stock_available' => 20, 'refund_window_minutes' => 120, 'refunds_enabled' => true, 'limits' => ['max_quantity_per_order' => 50]];
    }
}
