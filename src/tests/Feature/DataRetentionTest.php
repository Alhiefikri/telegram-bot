<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\ManualFulfillmentReview;
use App\Models\PaymentEvent;
use App\Models\TelegramUpdate;
use App\Services\Pakasir\PakasirPaymentEventLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('retainedStarsEvents')]
    public function test_regular_cleanup_does_not_delete_processed_stars_evidence(string $event): void
    {
        config()->set('services.data_retention_days', 30);
        $old = now()->subDays(31);
        $ordinary = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old]);
        $stars = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old, 'stars_event' => $event]);

        $this->artisan('ops:cleanup-retained-data --max-rows=1')->assertSuccessful();

        $this->assertDatabaseMissing('telegram_updates', ['id' => $ordinary->id]);
        $this->assertDatabaseHas('telegram_updates', ['id' => $stars->id, 'stars_event' => $event]);
    }

    public static function retainedStarsEvents(): array
    {
        return [['pre_checkout'], ['successful_payment'], ['refunded_payment']];
    }

    public function test_cleanup_retained_data_preserves_payment_evidence_and_retention_boundaries(): void
    {
        $this->freezeTime();
        config()->set('services.data_retention_days', 30);
        $old = now()->subDays(31);
        $cutoff = now()->subDays(30);
        $recent = now()->subDays(29);

        $oldProcessedTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old]);
        $oldUnprocessedTelegram = TelegramUpdate::factory()->create(['processed_at' => null, 'created_at' => $old]);
        $recentlyProcessedTelegram = TelegramUpdate::factory()->create(['processed_at' => $recent, 'created_at' => $old]);
        $processingBoundaryTelegram = TelegramUpdate::factory()->create(['processed_at' => $cutoff, 'created_at' => $old]);
        $claimedTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old, 'processing_started_at' => now()]);
        $retryTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old, 'next_retry_at' => now()]);
        $failedTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old, 'terminal_failed_at' => $old]);
        $boundaryTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $cutoff]);
        $recentTelegram = TelegramUpdate::factory()->create(['processed_at' => $recent, 'created_at' => $recent]);
        $oldPaymentEvent = PaymentEvent::factory()->create(['received_at' => $old, 'last_received_at' => $old, 'processed_at' => $old]);
        $recentPaymentEvent = PaymentEvent::factory()->create(['received_at' => $recent, 'last_received_at' => $recent]);
        $oldAudit = AuditLog::factory()->create(['created_at' => $old]);
        $boundaryAudit = AuditLog::factory()->create(['created_at' => $cutoff]);
        $recentAudit = AuditLog::factory()->create(['created_at' => $recent]);

        $this->artisan('ops:cleanup-retained-data --chunk=1')->assertSuccessful();

        $this->assertDatabaseMissing('telegram_updates', ['id' => $oldProcessedTelegram->id]);
        foreach ([$oldUnprocessedTelegram, $recentlyProcessedTelegram, $processingBoundaryTelegram, $claimedTelegram, $retryTelegram, $failedTelegram, $boundaryTelegram, $recentTelegram] as $update) {
            $this->assertDatabaseHas('telegram_updates', ['id' => $update->id]);
        }
        $this->assertDatabaseHas('payment_events', ['id' => $oldPaymentEvent->id]);
        $this->assertDatabaseHas('payment_events', ['id' => $recentPaymentEvent->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $oldAudit->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $boundaryAudit->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentAudit->id]);
    }

    public function test_payment_event_processing_markers_never_authorize_evidence_deletion(): void
    {
        config()->set('services.data_retention_days', 30);
        $old = now()->subYear();
        $events = collect();
        foreach (PaymentEventType::cases() as $type) {
            foreach ([null, $old] as $processedAt) {
                $events->push(PaymentEvent::factory()->create([
                    'event_type' => $type,
                    'received_at' => $old,
                    'last_received_at' => $old,
                    'processed_at' => $processedAt,
                    'idempotency_key' => hash('sha256', $type->value.($processedAt === null ? 'unprocessed' : 'processed')),
                ]));
            }
        }
        $events->push(PaymentEvent::factory()->create([
            'payment_id' => null,
            'provider' => 'unknown-provider',
            'provider_status' => 'unknown',
            'received_at' => $old,
            'last_received_at' => now(),
            'processed_at' => $old,
        ]));
        $paidEvent = PaymentEvent::factory()->create(['received_at' => $old, 'last_received_at' => $old, 'processed_at' => $old]);
        $paidEvent->payment->update(['status' => PaymentStatus::Paid, 'paid_at' => $old]);
        $events->push($paidEvent);
        $review = ManualFulfillmentReview::query()->create([
            'order_id' => $paidEvent->payment->order_id,
            'payment_id' => $paidEvent->payment_id,
            'amount' => $paidEvent->payment->amount,
            'currency' => 'IDR',
            'reason' => 'late_payment',
            'state' => 'pending',
        ]);
        $audit = AuditLog::factory()->create([
            'subject_type' => ManualFulfillmentReview::class,
            'subject_id' => (string) $review->id,
            'action' => 'manual_fulfillment.reviewed',
            'created_at' => $old,
        ]);

        $this->artisan('ops:cleanup-retained-data --chunk=1')->assertSuccessful();

        foreach ($events as $event) {
            $this->assertDatabaseHas('payment_events', ['id' => $event->id, 'idempotency_key' => $event->idempotency_key]);
        }
        $this->assertDatabaseHas('payments', ['id' => $paidEvent->payment_id, 'status' => PaymentStatus::Paid->value]);
        $this->assertDatabaseHas('manual_fulfillment_reviews', ['id' => $review->id, 'state' => 'pending']);
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
    }

    public function test_old_processed_payment_callback_keeps_its_canonical_replay_identity(): void
    {
        $this->freezeTime();
        $old = now()->subYear();
        $payload = [
            'amount' => 10000,
            'order_id' => 'retention-replay',
            'project' => 'retention-test',
            'status' => 'completed',
            'payment_method' => 'qris',
            'completed_at' => $old->toISOString(),
        ];
        $logger = app(PakasirPaymentEventLogger::class);
        $event = $logger->log(PaymentEventType::Callback, $payload, null);
        $event->update(['received_at' => $old, 'last_received_at' => $old, 'processed_at' => $old]);

        $this->artisan('ops:cleanup-retained-data --chunk=1')->assertSuccessful();
        $replayed = $logger->log(PaymentEventType::Callback, $payload, null);

        $this->assertSame($event->id, $replayed->id);
        $this->assertSame(2, $replayed->received_count);
        $this->assertNotNull($replayed->processed_at);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_row_budget_caps_the_final_batch_without_touching_held_tables(): void
    {
        $old = now()->subYear();
        TelegramUpdate::factory()->count(5)->create(['created_at' => $old, 'processed_at' => $old]);
        AuditLog::factory()->count(4)->create(['created_at' => $old]);

        $this->artisan('ops:cleanup-retained-data --chunk=2 --max-rows=3')
            ->expectsOutput('Retention cleanup stopped at the row budget; eligible rows may remain.')
            ->assertSuccessful();

        $this->assertDatabaseCount('telegram_updates', 2);
        $this->assertDatabaseCount('audit_logs', 4);
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_cannot_delete_data(string $option, string $value): void
    {
        $old = now()->subYear();
        $update = TelegramUpdate::factory()->create(['created_at' => $old, 'processed_at' => $old]);

        $this->artisan('ops:cleanup-retained-data', [$option => $value])->assertFailed();

        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id]);
    }

    public static function invalidLimits(): array
    {
        return [
            ['--chunk', '0'], ['--chunk', '1001'], ['--chunk', 'garbage'],
            ['--max-rows', '-1'], ['--max-rows', '100001'], ['--max-rows', '1.5'],
            ['--max-seconds', '0'], ['--max-seconds', '301'], ['--max-seconds', 'NaN'],
        ];
    }

    public function test_deadline_after_selection_prevents_deletion_even_with_frozen_wall_time(): void
    {
        $this->freezeTime();
        $old = now()->subYear();
        $update = TelegramUpdate::factory()->create(['created_at' => $old, 'processed_at' => $old]);
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'telegram_updates')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });

        $this->artisan('ops:cleanup-retained-data --max-seconds=1')
            ->expectsOutput('Retention cleanup stopped at the time budget; eligible rows may remain.')
            ->assertSuccessful();

        $this->assertTrue($delayed);
        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id]);
    }

    public function test_deadline_is_shared_across_batches_without_touching_held_tables(): void
    {
        $old = now()->subYear();
        TelegramUpdate::factory()->count(2)->create(['created_at' => $old, 'processed_at' => $old]);
        $audit = AuditLog::factory()->create(['created_at' => $old]);
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'telegram_updates')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });

        $this->artisan('ops:cleanup-retained-data --chunk=1 --max-seconds=1')
            ->expectsOutput('Retention cleanup stopped at the time budget; eligible rows may remain.')
            ->assertSuccessful();

        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
    }

    public function test_eligibility_is_rechecked_after_candidate_selection(): void
    {
        $old = now()->subYear();
        $update = TelegramUpdate::factory()->create(['created_at' => $old, 'processed_at' => $old]);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($update, &$changed): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'telegram_updates')) {
                $changed = true;
                DB::table('telegram_updates')->where('id', $update->id)->update(['processed_at' => null]);
            }
        });

        $this->artisan('ops:cleanup-retained-data --max-rows=1')->assertSuccessful();

        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id, 'processed_at' => null]);
    }

    public function test_postgres_slow_delete_cannot_outlive_the_remaining_wall_budget(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL statement timeout contract.');
        }

        $old = now()->subYear();
        $update = TelegramUpdate::factory()->create(['created_at' => $old, 'processed_at' => $old]);
        $timeouts = DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        DB::unprepared('CREATE FUNCTION pg_temp.retention_slow_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(5); RETURN OLD; END $$');
        DB::unprepared('CREATE TRIGGER retention_slow_delete BEFORE DELETE ON telegram_updates FOR EACH ROW EXECUTE FUNCTION pg_temp.retention_slow_delete()');
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'telegram_updates')) {
                $delayed = true;
                usleep(650_000);
            }
        });

        try {
            $started = hrtime(true);
            $this->artisan('ops:cleanup-retained-data --max-seconds=1')
                ->expectsOutputToContain('SQLSTATE 57014')
                ->assertFailed();
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;

            $this->assertLessThan(2.5, $elapsed);
            $this->assertDatabaseHas('telegram_updates', ['id' => $update->id]);
            $restored = DB::selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
            $this->assertEquals($timeouts, $restored);
        } finally {
            DB::unprepared('DROP TRIGGER retention_slow_delete ON telegram_updates');
            DB::unprepared('DROP FUNCTION pg_temp.retention_slow_delete()');
        }
    }

    public function test_postgres_lock_wait_is_bounded_and_failed_batch_preserves_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL lock timeout contract.');
        }

        $old = now()->subYear();
        $update = TelegramUpdate::factory()->create(['created_at' => $old, 'processed_at' => $old]);
        DB::unprepared('CREATE FUNCTION pg_temp.retention_locked_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN LOCK TABLE telegram_updates IN ACCESS EXCLUSIVE MODE; RETURN OLD; END $$');
        DB::unprepared('CREATE TRIGGER retention_locked_delete BEFORE DELETE ON telegram_updates FOR EACH ROW EXECUTE FUNCTION pg_temp.retention_locked_delete()');
        config()->set('database.connections.retention_blocker', config('database.connections.'.DB::getDefaultConnection()));
        $blocker = DB::connection('retention_blocker');
        $blocker->beginTransaction();
        $blocker->statement('LOCK TABLE telegram_updates IN ACCESS SHARE MODE');

        try {
            $started = hrtime(true);
            $this->artisan('ops:cleanup-retained-data --max-seconds=3')
                ->expectsOutputToContain('SQLSTATE 55P03')
                ->assertFailed();

            $this->assertLessThan(2.5, (hrtime(true) - $started) / 1_000_000_000);
            $this->assertDatabaseHas('telegram_updates', ['id' => $update->id]);
        } finally {
            $blocker->rollBack();
            DB::purge('retention_blocker');
            DB::unprepared('DROP TRIGGER retention_locked_delete ON telegram_updates');
            DB::unprepared('DROP FUNCTION pg_temp.retention_locked_delete()');
        }
    }
}
