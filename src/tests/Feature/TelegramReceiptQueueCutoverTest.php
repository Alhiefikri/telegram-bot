<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\SendTelegramReceiptActionPanel;
use App\Jobs\SendTelegramReceiptTxt;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramReceiptQueueCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_persisted_receipt_effects_and_moves_database_queue_jobs(): void
    {
        config()->set('queue.default', 'database');

        $credentials = $this->effect(TelegramOutboundEffectType::ReceiptCredentials, 'notifications');
        $actions = $this->effect(TelegramOutboundEffectType::ReceiptOrderActions, 'notifications');
        $admin = $this->effect(TelegramOutboundEffectType::AdminOrderPaidNotification, 'notifications');

        $receiptJob = $this->job(SendTelegramReceipt::class, 'notifications');
        $actionsJob = $this->job(SendTelegramReceiptActionPanel::class, 'notifications');
        $txtJob = $this->job(SendTelegramReceiptTxt::class, 'notifications', reservedAt: now()->timestamp);
        $adminJob = $this->job(SendAdminOrderPaidNotification::class, 'notifications');

        $this->artisan('telegram:receipt-queue-cutover', [
            '--from' => 'notifications',
            '--to' => TelegramOutboundEffectService::RECEIPT_QUEUE,
        ])->assertSuccessful();

        $this->assertSame(TelegramOutboundEffectService::RECEIPT_QUEUE, $credentials->fresh()->queue);
        $this->assertSame(TelegramOutboundEffectService::RECEIPT_QUEUE, $actions->fresh()->queue);
        $this->assertSame('notifications', $admin->fresh()->queue);

        $this->assertSame(TelegramOutboundEffectService::RECEIPT_QUEUE, $this->jobQueue($receiptJob));
        $this->assertSame(TelegramOutboundEffectService::RECEIPT_QUEUE, $this->jobQueue($actionsJob));
        $this->assertSame(TelegramOutboundEffectService::RECEIPT_QUEUE, $this->jobQueue($txtJob));
        $this->assertSame('notifications', $this->jobQueue($adminJob));
    }

    public function test_a_legacy_persisted_queue_still_routes_to_the_receipt_worker(): void
    {
        Queue::fake();
        $effect = $this->effect(TelegramOutboundEffectType::ReceiptCredentials, 'notifications');

        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);

        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceipt::class,
            static fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $effect->getKey(),
        );
    }

    public function test_dry_run_reports_without_mutating_effects_or_jobs(): void
    {
        config()->set('queue.default', 'database');

        $effect = $this->effect(TelegramOutboundEffectType::ReceiptCredentials, 'notifications');
        $job = $this->job(SendTelegramReceipt::class, 'notifications');

        $this->artisan('telegram:receipt-queue-cutover', [
            '--from' => 'notifications',
            '--dry-run' => true,
            '--json' => true,
        ])->expectsOutputToContain('"matched": 1')->assertSuccessful();

        $this->assertSame('notifications', $effect->fresh()->queue);
        $this->assertSame('notifications', $this->jobQueue($job));
    }

    public function test_it_refuses_same_source_and_destination(): void
    {
        $this->artisan('telegram:receipt-queue-cutover', [
            '--from' => 'notifications',
            '--to' => 'notifications',
        ])->assertFailed();
    }

    private function effect(TelegramOutboundEffectType $type, ?string $queue): TelegramOutboundEffect
    {
        return TelegramOutboundEffect::factory()->create([
            'effect_type' => $type->value,
            'queue' => $queue,
            'state' => TelegramOutboundEffectState::Pending,
        ]);
    }

    private function job(string $class, string $queue, ?int $reservedAt = null): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => $class, 'data' => []], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    private function jobQueue(int $id): string
    {
        return (string) DB::table('jobs')->where('id', $id)->value('queue');
    }
}
