<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Supplier;
use App\Models\SupplierHealthEvent;
use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierHealthDeliveryPipeline;
use App\Services\Suppliers\SupplierHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
final class SupplierHealthPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || env('RUN_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Supplier health races require the isolated PostgreSQL concurrency lane and pcntl.');
        }
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        config(['services.telegram.admin_telegram_chat_id' => '790001', 'services.telegram.token' => 'health-race-token']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    #[Group('postgres-release')]
    public function test_concurrent_materializers_commit_one_low_episode_event_marker_and_network_effect(): void
    {
        $supplier = Supplier::forceCreate([
            'name' => 'Concurrent private connection', 'provider' => 'prodseller', 'api_key' => 'fixture-health-race',
            'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => 5_000_000, 'reserved_balance_micros' => 4_000_000,
            'low_balance_threshold_micros' => 1_000_000, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_observed_at' => now(),
        ]);

        $results = $this->race([
            fn () => app(SupplierHealthService::class)->materialize(1),
            fn () => app(SupplierHealthService::class)->materialize(1),
        ]);

        $this->assertSame(1, array_sum($results));
        $this->assertSame(1, SupplierHealthEvent::count());
        $event = SupplierHealthEvent::sole();
        $effect = TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::SupplierHealth->value)->sole();
        $this->assertSame((string) $event->id, $effect->source_id);
        $this->assertSame($supplier->id, (int) $event->supplier_id);
        $this->assertNotNull($supplier->fresh()->low_balance_alerted_at);
        $this->assertSame(1, DB::table('supplier_health_states')->where('supplier_id', $supplier->id)->count());
        $this->assertSame(0, app(SupplierHealthService::class)->materialize(1));
        $this->assertStringContainsString('Saldo tersedia: 1.000000 USDT', $event->message);
        Http::assertNothingSent();

        $levels = [];
        Http::fake(function () use (&$levels) {
            $levels[] = DB::connection()->transactionLevel();

            return Http::response(['ok' => true, 'result' => ['message_id' => 12345]]);
        });
        app(SupplierHealthDeliveryPipeline::class)->deliver($effect);
        app(SupplierHealthDeliveryPipeline::class)->deliver($effect);

        Http::assertSentCount(1);
        $this->assertSame([0], $levels);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(1, $effect->fresh()->attempts);
    }
}
