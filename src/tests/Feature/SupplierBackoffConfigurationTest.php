<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\SupplierCurrencyRate;
use App\Models\User;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierCurrencyRateManager;
use App\Services\Suppliers\SupplierManager;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierBackoffConfigurationTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        SupplierCurrencyRate::create(['currency' => 'USD', 'exchange_rate_idr' => '16000', 'version' => 1]);
        SupplierCurrencyRate::create(['currency' => 'VND', 'exchange_rate_idr' => '0.65', 'version' => 1]);
        Http::preventStrayRequests();
    }

    public function test_changing_credentials_allows_the_next_normal_sync_during_previous_backoff(): void
    {
        $supplier = $this->supplier();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => '8'] : ['products' => []]));

        app(SupplierManager::class)->save($supplier, $this->input(['api_key' => 'new-synthetic-key']), $actor, null);

        $this->assertSame(0, $supplier->fresh()->sync_failures);
        $this->assertNull($supplier->fresh()->sync_next_attempt_at);
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier->fresh()));
        $this->assertSame(8_000_000, $supplier->fresh()->balance_micros);
        Http::assertSentCount(2);
    }

    public function test_provider_and_currency_changes_reset_backoff(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        foreach ([['provider' => 'csxstore'], ['currency' => 'VND']] as $change) {
            $supplier = $this->supplier();
            app(SupplierManager::class)->save($supplier, $this->input($change), $actor, null);
            $this->assertSame(0, $supplier->fresh()->sync_failures);
            $this->assertNull($supplier->fresh()->sync_next_attempt_at);
        }
    }

    public function test_global_rate_change_preserves_failure_and_observations(): void
    {
        $supplier = $this->supplier();
        $next = $supplier->sync_next_attempt_at;
        $observed = $supplier->synced_at;
        $actor = User::factory()->create(['role' => UserRole::Owner]);

        app(SupplierCurrencyRateManager::class)->save(['USD' => ['exchange_rate_idr' => '17000', 'version' => 1]], $actor, null);

        $supplier->refresh();
        $this->assertSame(3, $supplier->sync_failures);
        $this->assertTrue($next->equalTo($supplier->sync_next_attempt_at));
        $this->assertTrue($observed->equalTo($supplier->synced_at));
        $this->assertSame('transport', $supplier->last_error);
    }

    public function test_edit_preserves_live_sync_lease_until_it_expires(): void
    {
        $supplier = $this->supplier()->forceFill(['sync_token' => 'live-token', 'sync_lease_expires_at' => now()->addMinute()]);
        $supplier->save();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        Http::fake();

        app(SupplierManager::class)->save($supplier, $this->input(['api_key' => 'new-synthetic-key']), $actor, null);

        $this->assertSame('live-token', $supplier->fresh()->sync_token);
        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier->fresh()));
        Http::assertNothingSent();
    }

    public function test_sync_distinguishes_a_known_request_timeout_from_a_connection_failure(): void
    {
        $supplier = $this->supplier()->forceFill(['sync_failures' => 0, 'sync_next_attempt_at' => null]);
        $supplier->save();
        Http::fake(function (): void {
            throw new ConnectException(
                'Synthetic timeout',
                new Request('GET', 'https://example.test'),
                null,
                ['errno' => 28],
            );
        });

        $this->assertFalse(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertSame('request_timeout', $supplier->fresh()->last_error);
        $this->assertTrue($supplier->fresh()->sync_next_attempt_at->isFuture());
    }

    private function supplier(): Supplier
    {
        return Supplier::forceCreate([
            'name' => 'Private connection', 'provider' => 'prodseller', 'api_key' => 'old-synthetic-key',
            'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => '16000',
            'is_active' => true, 'version' => 1, 'sync_failures' => 3,
            'sync_next_attempt_at' => now()->addMinutes(8), 'last_error' => 'transport',
            'synced_at' => now()->subMinute(), 'sync_expires_at' => now()->addMinutes(4),
        ]);
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'name' => 'Private connection', 'provider' => 'prodseller', 'api_key' => '',
            'currency' => 'USDT', 'currency_confirmed' => true, 'is_active' => true,
            'low_balance_threshold_micros' => 0, 'version' => 1,
        ], $changes);
    }
}
