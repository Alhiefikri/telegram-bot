<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Models\AuditLog;
use App\Models\PaymentEvent;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_retained_data_deletes_only_old_processed_records(): void
    {
        config()->set('services.data_retention_days', 30);
        $old = now()->subDays(31);
        $recent = now()->subDays(29);

        $oldProcessedTelegram = TelegramUpdate::factory()->create(['processed_at' => $old, 'created_at' => $old]);
        $oldUnprocessedTelegram = TelegramUpdate::factory()->create(['processed_at' => null, 'created_at' => $old]);
        $recentProcessedTelegram = TelegramUpdate::factory()->create(['processed_at' => $recent, 'created_at' => $recent]);
        $oldPaymentEvent = PaymentEvent::factory()->create(['event_type' => PaymentEventType::Callback, 'received_at' => $old, 'last_received_at' => $old]);
        $recentPaymentEvent = PaymentEvent::factory()->create(['event_type' => PaymentEventType::Callback, 'received_at' => $recent, 'last_received_at' => $recent]);
        $oldAudit = AuditLog::factory()->create(['created_at' => $old]);
        $recentAudit = AuditLog::factory()->create(['created_at' => $recent]);

        $this->artisan('ops:cleanup-retained-data --chunk=1')
            ->expectsOutput('Deleted retained data: telegram_updates=1 payment_events=1 audit_logs=1.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('telegram_updates', ['id' => $oldProcessedTelegram->id]);
        $this->assertDatabaseHas('telegram_updates', ['id' => $oldUnprocessedTelegram->id]);
        $this->assertDatabaseHas('telegram_updates', ['id' => $recentProcessedTelegram->id]);
        $this->assertDatabaseMissing('payment_events', ['id' => $oldPaymentEvent->id]);
        $this->assertDatabaseHas('payment_events', ['id' => $recentPaymentEvent->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $oldAudit->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentAudit->id]);
    }
}
