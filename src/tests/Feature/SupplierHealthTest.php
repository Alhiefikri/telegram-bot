<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendSupplierHealthNotification;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierHealthEvent;
use App\Models\SupplierPurchase;
use App\Models\TelegramOutboundEffect;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierHealthDeliveryPipeline;
use App\Services\Suppliers\SupplierHealthService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\NonTransactionalDatabaseTestCase;

#[Group('postgres-release')]
final class SupplierHealthTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['services.telegram.admin_telegram_chat_id' => '790001', 'services.telegram.token' => 'health-test-token']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_actual_command_twice_materializes_one_effect_and_sends_one_private_message(): void
    {
        $supplier = $this->supplier(['balance_micros' => 1_000_000]);
        $levels = [];
        Http::fake(function ($request) use (&$levels) {
            $levels[] = DB::connection()->transactionLevel();

            return Http::response(['ok' => true, 'result' => ['message_id' => 12345]]);
        });

        $this->artisan('suppliers:notify-health', ['--limit' => 1])->assertSuccessful();
        $this->artisan('suppliers:notify-health', ['--limit' => 1])->assertSuccessful();

        $effect = $this->effect();
        $this->assertSame(1, SupplierHealthEvent::count());
        $this->assertSame(1, $this->effects()->count());
        $this->assertSame(SupplierHealthEvent::class, $effect->source_type);
        $this->assertSame('790001', (string) $effect->telegram_chat_id);
        $this->assertNotNull($supplier->fresh()->low_balance_alerted_at);
        Queue::assertPushed(SendSupplierHealthNotification::class);
        $job = new SendSupplierHealthNotification((string) $effect->id);
        $job->handle(app(SupplierHealthDeliveryPipeline::class));
        $job->handle(app(SupplierHealthDeliveryPipeline::class));
        $this->artisan('suppliers:notify-health', ['--limit' => 1])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '790001'
            && str_contains($request['text'], 'Private connection')
            && str_contains($request['text'], 'Saldo tersedia: 1.000000 USDT')
            && str_contains($request['text'], route('admin.suppliers.purchases')));
        $this->assertSame([0], $levels);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(1, $this->effects()->count());
    }

    public static function lowBalances(): iterable
    {
        yield 'exactly equal to threshold' => [1_000_000, 0, '1.000000'];
        yield 'holds make balance exactly equal' => [5_000_000, 4_000_000, '1.000000'];
        yield 'holds exceed wallet' => [500_000, 2_000_000, '0.000000'];
        yield 'negative observed wallet' => [-1_000_000, 0, '0.000000'];
        yield 'minimum signed wallet cannot underflow when subtracting holds' => [PHP_INT_MIN, 1, '0.000000'];
    }

    #[DataProvider('lowBalances')]
    public function test_low_balance_uses_integer_available_funds_and_clamps_negative_projection(int $balance, int $reserved, string $display): void
    {
        $supplier = $this->supplier(['balance_micros' => $balance, 'reserved_balance_micros' => $reserved]);

        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(0, $this->health()->materialize());

        $this->assertStringContainsString('Saldo tersedia: '.$display.' USDT', SupplierHealthEvent::sole()->message);
        $this->assertNotNull($supplier->fresh()->low_balance_alerted_at);
        Http::assertNothingSent();
    }

    public function test_valid_normal_observation_rearms_a_new_low_episode(): void
    {
        $supplier = $this->supplier(['balance_micros' => 1_000_000]);
        $this->assertSame(1, $this->health()->materialize());
        $supplier->forceFill(['balance_micros' => 2_000_000, 'balance_observed_at' => now()])->save();
        $this->assertSame(0, $this->health()->materialize());
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $supplier->forceFill(['balance_micros' => 1_000_000, 'balance_observed_at' => now()])->save();

        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(0, $this->health()->materialize());
        $this->assertSame(2, $this->effects()->count());
    }

    public function test_threshold_zero_disables_even_a_stale_episode_and_reenable_alerts_again(): void
    {
        $supplier = $this->supplier(['balance_micros' => 0]);
        $this->health()->materialize();
        $supplier->forceFill(['low_balance_threshold_micros' => 0, 'balance_observed_at' => now()->subHour(), 'sync_expires_at' => now()->subHour()])->save();

        $this->assertSame(0, $this->health()->materialize());
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $supplier->forceFill(['low_balance_threshold_micros' => 1_000_000])->save();
        $this->assertSame(0, $this->health()->materialize(), 'Re-enabling must not reinterpret stale funds as zero.');
        $supplier->forceFill(['balance_observed_at' => now(), 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)])->save();
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(2, $this->effects()->count());
    }

    public static function unknownBalances(): iterable
    {
        yield 'null wallet' => [null, 'fresh'];
        yield 'wallet never observed' => [0, 'missing'];
        yield 'expired wallet despite fresh catalog' => [0, 'stale'];
        yield 'exact freshness boundary is stale' => [0, 'boundary'];
    }

    #[DataProvider('unknownBalances')]
    public function test_unknown_or_stale_balance_is_neither_zero_nor_recovery(?int $balance, string $observation): void
    {
        $supplier = $this->supplier(['balance_micros' => $balance, 'balance_observed_at' => $this->observation($observation)]);
        $this->assertSame(0, $this->health()->materialize());
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $supplier->forceFill(['balance_micros' => 0, 'balance_observed_at' => now()])->save();
        $this->assertSame(1, $this->health()->materialize());
        $marker = $supplier->fresh()->low_balance_alerted_at;
        $supplier->forceFill(['balance_micros' => $balance === null ? null : 10_000_000, 'balance_observed_at' => $this->observation($observation === 'fresh' ? 'fresh' : $observation)])->save();
        $this->assertSame(0, $this->health()->materialize());
        $this->assertTrue($marker->equalTo($supplier->fresh()->low_balance_alerted_at));
        $supplier->forceFill(['balance_micros' => 0, 'balance_observed_at' => now()])->save();
        $this->assertSame(0, $this->health()->materialize(), 'Unknown/stale observation must not rearm the existing low episode.');
        $this->assertSame(1, $this->effects()->count());
    }

    public function test_error_switch_a_b_a_alerts_once_per_code_until_observed_normal_recovery(): void
    {
        $supplier = $this->supplier(['last_error' => 'authentication']);
        $this->assertSame(1, $this->health()->materialize());
        $supplier->forceFill(['last_error' => 'rate_limited', 'version' => 2])->save();
        $this->assertSame(1, $this->health()->materialize());
        $supplier->forceFill(['last_error' => 'authentication', 'version' => 3])->save();
        $this->assertSame(0, $this->health()->materialize());
        $supplier->forceFill(['last_error' => null, 'sync_expires_at' => now()->subMinute()])->save();
        $this->assertSame(0, $this->health()->materialize());
        $supplier->forceFill(['last_error' => 'authentication'])->save();
        $this->assertSame(0, $this->health()->materialize(), 'Stale data is not an observed error recovery.');
        $supplier->forceFill(['last_error' => null, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_observed_at' => now()])->save();
        $this->assertSame(0, $this->health()->materialize());
        $supplier->forceFill(['last_error' => 'authentication'])->save();
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(3, $this->effects()->count());
    }

    public function test_sync_owns_observations_but_only_materialization_owns_low_alert_marker(): void
    {
        $supplier = $this->supplier(['balance_micros' => null, 'balance_observed_at' => null]);
        $balance = '1';
        $levels = [];
        Http::fake(function ($request) use (&$balance, &$levels) {
            $levels[] = DB::connection()->transactionLevel();

            return Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => $balance] : ['products' => []]);
        });
        $sync = app(SupplierCatalogSync::class);
        $this->assertTrue($sync->sync($supplier));
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $this->assertSame(0, $this->effects()->count());
        $this->assertNotNull($supplier->fresh()->balance_observed_at);
        $this->assertSame(1, $this->health()->materialize());
        $marker = $supplier->fresh()->low_balance_alerted_at;
        $this->travel(1)->minutes();
        $this->assertTrue($sync->sync($supplier));
        $this->assertTrue($marker->equalTo($supplier->fresh()->low_balance_alerted_at));
        $this->assertSame(0, $this->health()->materialize(), 'Equality remains low after a fresh successful sync.');
        $balance = '2';
        $this->assertTrue($sync->sync($supplier));
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        $balance = '1';
        $this->assertTrue($sync->sync($supplier));
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(2, $this->effects()->count());
        $this->assertNotEmpty($levels);
        $this->assertSame([0], array_values(array_unique($levels)), 'Supplier GET must never run inside a transaction.');
    }

    public function test_catalog_sync_with_unsettled_purchase_does_not_freshen_or_recover_wallet_episode(): void
    {
        $supplier = $this->supplier(['balance_micros' => 0]);
        $this->assertSame(1, $this->health()->materialize());
        $marker = $supplier->fresh()->low_balance_alerted_at;
        $observed = $supplier->fresh()->balance_observed_at;
        $this->purchase($this->fulfillment($supplier), $supplier, 'awaiting_delivery');
        $this->travel(6)->minutes();
        Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => '10'] : ['products' => []]));

        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $fresh = $supplier->fresh();
        $this->assertTrue($observed->equalTo($fresh->balance_observed_at));
        $this->assertSame(0, $fresh->balance_micros);
        $this->assertTrue($marker->equalTo($fresh->low_balance_alerted_at));
        $this->assertTrue($fresh->sync_expires_at->isFuture());
        $this->assertSame(0, $this->health()->materialize());
        $this->assertSame(1, $this->effects()->count());
    }

    public function test_same_count_new_review_identity_alerts_but_resolution_alone_does_not(): void
    {
        $supplier = $this->supplier();
        $first = $this->fulfillment($supplier, 'review');
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(0, $this->health()->materialize());
        $first->update(['state' => 'delivered']);
        $second = $this->fulfillment($supplier, 'review');
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(1, SupplierFulfillment::where('state', 'review')->count());
        $second->update(['state' => 'delivered']);
        $this->assertSame(0, $this->health()->materialize());
        $this->assertSame(2, $this->effects()->count());
        foreach (SupplierHealthEvent::all() as $event) {
            $this->assertStringContainsString('Review/ambigu baru: 1 obligation', $event->message);
        }
        $first->update(['state' => 'review']);
        $this->assertSame(1, $this->health()->materialize(), 'A resolved record entering review again is a new state episode.');
    }

    public function test_unsettled_purchase_is_included_after_customer_delivery_and_same_obligation_is_not_counted_twice(): void
    {
        $supplier = $this->supplier(['balance_micros' => 1_000_000, 'last_error' => 'transport']);
        $delivered = $this->fulfillment($supplier, 'delivered');
        $purchase = $this->purchase($delivered, $supplier, 'ambiguous');
        $review = $this->fulfillment($supplier, 'review');
        $this->purchase($review, $supplier, 'review');

        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(0, $this->health()->materialize());
        $message = SupplierHealthEvent::sole()->message;
        $this->assertStringContainsString('Review/ambigu baru: 2 obligation', $message);
        $this->assertStringContainsString('Saldo tersedia <= ambang', $message);
        $this->assertStringContainsString('Koneksi supplier terganggu', $message);
        $this->assertStringContainsString('jangan beli ulang', $message);
        $this->assertSame(1, $this->effects()->count(), 'New conditions on the same connection share one scan message.');
        $firstEventId = SupplierHealthEvent::sole()->id;
        $purchase->update(['state' => 'review']);
        $this->assertSame(1, $this->health()->materialize(), 'A new purchase state episode must remain visible even at the same total count.');
        $this->assertStringContainsString('Review/ambigu baru: 1 obligation', SupplierHealthEvent::whereKeyNot($firstEventId)->sole()->message);
    }

    public function test_unselected_or_unreadable_review_connections_are_not_guessed(): void
    {
        $first = $this->supplier(['name' => 'Not selected A']);
        $second = $this->supplier(['name' => 'Not selected B']);
        $this->fulfillment(null, 'review', [['supplier_id' => $first->id], ['supplier_id' => $second->id]]);
        $unreadable = $this->fulfillment(null, 'review');
        DB::table('supplier_fulfillments')->where('id', $unreadable->id)->update(['sources_snapshot' => 'not-valid-encrypted-snapshot']);

        $this->assertSame(1, $this->health()->materialize());
        $event = SupplierHealthEvent::sole();
        $this->assertNull($event->supplier_id);
        $this->assertStringContainsString('belum ada koneksi terpilih', $event->message);
        $this->assertStringContainsString('Review/ambigu baru: 2 obligation', $event->message);
        $this->assertStringNotContainsString('Not selected A', $event->message);
        $this->assertStringNotContainsString('Not selected B', $event->message);
        $this->assertStringNotContainsString('not-valid-encrypted-snapshot', $event->message);
    }

    public function test_a_single_proven_snapshot_connection_attributes_fulfillment_without_a_purchase(): void
    {
        $supplier = $this->supplier();
        $this->fulfillment(null, 'review', [['supplier_id' => $supplier->id], ['supplier_id' => $supplier->id]]);

        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame($supplier->id, (int) SupplierHealthEvent::sole()->supplier_id);
        $this->assertStringContainsString('Private connection', SupplierHealthEvent::sole()->message);
    }

    public function test_bounded_cursor_discovery_eventually_visits_every_supplier_and_fulfillment(): void
    {
        $first = $this->supplier(['balance_micros' => 0]);
        $second = $this->supplier(['balance_micros' => 0]);
        $third = $this->supplier(['balance_micros' => 0]);
        $this->fulfillment($first, 'delivered');
        $this->fulfillment($second, 'delivered');
        $this->fulfillment($third, 'review');

        $this->assertSame(1, $this->health()->materialize(1));
        $this->assertSame(1, DB::table('supplier_health_states')->count());
        $this->assertNull($second->fresh()->low_balance_alerted_at);
        $this->assertSame(1, $this->health()->materialize(1));
        $this->assertSame(1, $this->health()->materialize(1));
        $this->assertSame(3, $this->effects()->count());
        $this->assertNotNull($third->fresh()->low_balance_alerted_at);
        $this->assertStringContainsString('Review/ambigu baru: 1 obligation', SupplierHealthEvent::where('supplier_id', $third->id)->sole()->message);
        $this->assertSame(0, $this->health()->materialize(1));
        $this->assertSame(0, $this->health()->materialize(1));
        $this->assertSame(0, $this->health()->materialize(1));
    }

    public function test_missing_admin_target_consumes_no_low_error_review_or_cursor_dedupe(): void
    {
        $supplier = $this->supplier(['balance_micros' => 0, 'last_error' => 'invalid_key']);
        $this->fulfillment($supplier, 'review');
        config(['services.telegram.admin_telegram_chat_id' => '']);
        $this->artisan('suppliers:notify-health', ['--limit' => 1])
            ->expectsOutputToContain('tidak ada dedupe yang dikonsumsi')->assertSuccessful();

        $this->assertSame(0, $this->effects()->count());
        $this->assertSame(0, SupplierHealthEvent::count());
        $this->assertSame(0, DB::table('supplier_health_states')->count());
        $this->assertSame(0, DB::table('supplier_health_reviews')->count());
        $this->assertSame(0, (int) DB::table('supplier_health_scans')->value('supplier_cursor'));
        $this->assertSame(0, (int) DB::table('supplier_health_scans')->value('fulfillment_cursor'));
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        config(['services.telegram.admin_telegram_chat_id' => '790001']);
        $this->assertSame(1, $this->health()->materialize());
        $message = SupplierHealthEvent::sole()->message;
        $this->assertStringContainsString('Saldo tersedia <= ambang', $message);
        $this->assertStringContainsString('API key tidak valid', $message);
        $this->assertStringContainsString('Review/ambigu baru: 1 obligation', $message);
    }

    public function test_transaction_rollback_leaves_no_event_effect_marker_dedupe_or_queued_job(): void
    {
        $supplier = $this->supplier(['balance_micros' => 0, 'last_error' => 'transport']);
        $this->fulfillment($supplier, 'review');
        try {
            DB::transaction(function () use ($supplier): void {
                $this->assertSame(1, $this->health()->materialize());
                $this->assertSame(1, $this->effects()->count());
                $this->assertNotNull($supplier->fresh()->low_balance_alerted_at);
                Queue::assertNothingPushed();
                throw new RuntimeException('Deliberate health rollback');
            });
            $this->fail('The deliberate rollback must escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate health rollback', $exception->getMessage());
        }

        $this->assertSame(0, $this->effects()->count());
        $this->assertSame(0, SupplierHealthEvent::count());
        $this->assertSame(0, DB::table('supplier_health_states')->count());
        $this->assertSame(0, DB::table('supplier_health_reviews')->count());
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(1, $this->health()->materialize());
    }

    public function test_lost_redis_wakeup_is_recovered_from_the_existing_durable_effect(): void
    {
        $this->supplier(['balance_micros' => 0]);
        $this->health()->materialize();
        $effect = $this->effect();
        Queue::fake();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 12345]]));

        $this->assertSame(1, $this->health()->dispatchDue());
        Queue::assertPushed(SendSupplierHealthNotification::class, fn ($job) => $job->effectId === (string) $effect->id);
        $queued = Queue::pushed(SendSupplierHealthNotification::class)->sole();
        $queued->handle(app(SupplierHealthDeliveryPipeline::class));

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(0, $this->health()->dispatchDue());
        $this->assertSame(1, $this->effects()->count());
        Http::assertSentCount(1);
    }

    public static function safeErrors(): iterable
    {
        yield 'authentication' => ['authentication', 'Autentikasi gagal'];
        yield 'invalid key' => ['invalid_key', 'Verifikasi atau rotasi key'];
        yield 'encrypted credential unreadable' => ['credential_unreadable', 'APP_KEY/backup'];
        yield 'rate limiting' => ['rate_limited', 'Tunggu lalu sinkronkan'];
        yield 'transport' => ['transport', 'Tunggu lalu sinkronkan'];
    }

    #[DataProvider('safeErrors')]
    public function test_messages_escape_connection_data_and_use_only_safe_allowlisted_error_explanations(string $error, string $advice): void
    {
        $supplier = $this->supplier([
            'name' => '<b>Private & "quoted"</b>', 'last_error' => $error,
            'balance_micros' => null, 'balance_observed_at' => null,
            'api_key' => 'SECRET-API-KEY', 'webhook_secret' => 'SECRET-WEBHOOK',
            'account_metadata' => ['raw_body' => 'SECRET-PROVIDER-BODY', 'Authorization' => 'SECRET-HEADER'],
        ]);
        $fulfillment = $this->fulfillment($supplier, 'review', [['supplier_id' => $supplier->id, 'api_key' => 'SECRET-SNAPSHOT']]);
        $this->purchase($fulfillment, $supplier, 'ambiguous', ['delivery_payload' => ['SECRET-DELIVERY'], 'last_error' => 'SECRET-EXCEPTION']);
        $this->health()->materialize();
        $effect = $this->effect();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 12345]]));
        app(SupplierHealthDeliveryPipeline::class)->deliver($effect);

        $message = SupplierHealthEvent::sole()->message;
        $this->assertStringContainsString('&lt;b&gt;Private &amp; &quot;quoted&quot;&lt;/b&gt;', $message);
        $this->assertStringNotContainsString('<b>Private', $message);
        $this->assertStringContainsString($advice, $message);
        $this->assertStringContainsString('Saldo tersedia: belum diketahui', $message);
        $this->assertStringContainsString('Observasi saldo: belum diketahui', $message);
        $this->assertStringContainsString('stale; bukan bukti saldo nol', $message);
        $this->assertStringContainsString('jangan beli ulang', $message);
        $this->assertLessThanOrEqual(4096, mb_strlen($message));
        $safePersistence = json_encode([$message, $effect->fresh()->getAttributes()], JSON_THROW_ON_ERROR);
        foreach (['SECRET-API-KEY', 'SECRET-WEBHOOK', 'SECRET-PROVIDER-BODY', 'SECRET-HEADER', 'SECRET-SNAPSHOT', 'SECRET-DELIVERY', 'SECRET-EXCEPTION'] as $secret) {
            $this->assertStringNotContainsString($secret, $safePersistence);
        }
        Http::assertSent(fn ($request) => $request['text'] === $message && $request['parse_mode'] === 'HTML' && (string) $request['chat_id'] === '790001');
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
    }

    public function test_arbitrary_provider_error_payload_is_not_an_alert_reason_or_a_recovery(): void
    {
        $supplier = $this->supplier(['last_error' => 'transport']);
        $this->health()->materialize();
        $supplier->forceFill(['last_error' => 'provider SECRET-RAW-BODY Authorization: SECRET-HEADER'])->save();
        $this->assertSame(0, $this->health()->materialize());
        $supplier->forceFill(['last_error' => 'transport'])->save();
        $this->assertSame(0, $this->health()->materialize());
        $this->assertSame(1, $this->effects()->count());
        $this->assertStringNotContainsString('SECRET-', SupplierHealthEvent::sole()->message);
    }

    public function test_definite_telegram_rate_limit_retries_the_same_ledger_effect_after_its_due_time(): void
    {
        $this->supplier(['balance_micros' => 0]);
        $this->health()->materialize();
        $effect = $this->effect();
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 10]], 429)
            ->push(['ok' => true, 'result' => ['message_id' => 12345]])]);
        $pipeline = app(SupplierHealthDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertNotNull($effect->fresh()->next_attempt_at);
        $pipeline->deliver($effect);
        Http::assertSentCount(1);
        $this->travelTo($effect->fresh()->next_attempt_at->addSecond());
        $this->assertSame(1, $this->health()->dispatchDue());
        $pipeline->deliver($effect);

        Http::assertSentCount(2);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(2, $effect->fresh()->attempts);
        $this->assertSame(1, $this->effects()->count());
    }

    public function test_ambiguous_telegram_delivery_is_never_blind_resent_by_scanner_or_pipeline(): void
    {
        $this->supplier(['balance_micros' => 0]);
        $this->health()->materialize();
        $effect = $this->effect();
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;
            throw new ConnectionException('Synthetic timeout after request transmission');
        });
        $pipeline = app(SupplierHealthDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->next_attempt_at);
        $this->travel(1)->hours();
        $this->artisan('suppliers:notify-health')->assertSuccessful();
        $pipeline->deliver($effect);

        $this->assertSame(1, $requests);
        $this->assertSame(1, $effect->fresh()->attempts);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(1, $this->effects()->count());
    }

    public function test_authoritative_connection_recovery_is_independent_of_a_withheld_wallet_observation(): void
    {
        $supplier = $this->supplier(['balance_micros' => null, 'balance_observed_at' => null, 'last_error' => 'authentication']);
        $this->purchase($this->fulfillment($supplier), $supplier, 'awaiting_delivery');
        $this->assertSame(1, $this->health()->materialize());
        Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => '10'] : ['products' => []]));

        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $this->assertNull($supplier->fresh()->balance_micros);
        $this->assertNull($supplier->fresh()->balance_observed_at);
        $this->assertNull($supplier->fresh()->last_error);
        $this->assertSame(0, $this->health()->materialize());
        $supplier->refresh()->forceFill(['last_error' => 'authentication'])->save();
        $this->assertSame(1, $this->health()->materialize());
        $this->assertSame(2, $this->effects()->count());
        $this->assertNull($supplier->fresh()->low_balance_alerted_at);
    }

    private function health(): SupplierHealthService
    {
        return app(SupplierHealthService::class);
    }

    private function effects(): Builder
    {
        return TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::SupplierHealth->value);
    }

    private function effect(): TelegramOutboundEffect
    {
        return $this->effects()->sole();
    }

    private function observation(string $kind): ?CarbonInterface
    {
        return match ($kind) {
            'missing' => null,
            'stale' => now()->subMinutes(6),
            'boundary' => now()->subSeconds(SupplierCatalogSync::FRESHNESS_SECONDS),
            default => now(),
        };
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::forceCreate(array_merge([
            'name' => 'Private connection', 'provider' => 'prodseller', 'api_key' => 'fixture-health-key',
            'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => 10_000_000, 'reserved_balance_micros' => 0,
            'low_balance_threshold_micros' => 1_000_000, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_observed_at' => now(),
        ], $attributes));
    }

    private function fulfillment(?Supplier $supplier, string $state = 'reserved', ?array $snapshot = null): SupplierFulfillment
    {
        $item = OrderItem::factory()->create(['local_quantity' => 0, 'supplier_quantity' => 1]);

        return SupplierFulfillment::create([
            'order_id' => $item->order_id, 'order_item_id' => $item->id, 'quantity' => 1,
            'state' => $state, 'maximum_cost_idr' => 5000,
            'sources_snapshot' => $snapshot ?? ($supplier === null ? [] : [['supplier_id' => $supplier->id]]),
            'reserved_supplier_id' => $supplier?->id,
        ]);
    }

    private function purchase(SupplierFulfillment $fulfillment, Supplier $supplier, string $state, array $attributes = []): SupplierPurchase
    {
        $catalog = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => (string) Str::uuid(),
            'name' => 'Private fixture SKU', 'unit_price_micros' => 1_000_000,
            'stock' => 10, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);

        return SupplierPurchase::create(array_merge([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $catalog->id, 'reference' => (string) Str::uuid(),
            'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000, 'state' => $state,
        ], $attributes));
    }
}
