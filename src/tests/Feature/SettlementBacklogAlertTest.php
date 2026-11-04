<?php

namespace Tests\Feature;

use App\Enums\BalanceAlertSeverity;
use App\Enums\BalanceAlertState;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendBalanceOperationsAlert;
use App\Models\BalanceOperationsAlertEpisode;
use App\Models\TelegramOutboundEffect;
use App\Services\Operations\BalanceOperationsAlertDelivery;
use App\Services\Operations\BalanceOperationsAlertService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UsdtTopUpTestCase;

final class SettlementBacklogAlertTest extends UsdtTopUpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.admin_telegram_chat_id', '900100200');
    }

    public function test_backlog_updates_diagnostics_without_open_changed_or_resolution_notifications(): void
    {
        DB::table('customer_usdt_top_up_settlement_work')->update(['next_check_at' => now()->subMinutes(31)]);
        $work = DB::table('customer_usdt_top_up_settlement_work')->first();
        $service = app(BalanceOperationsAlertService::class);

        $this->assertSame(0, $service->evaluate()->notified);
        $episode = BalanceOperationsAlertEpisode::query()->where('alert_key', 'settlement_backlog')->sole();
        $this->assertSame(BalanceAlertState::Open, $episode->state);
        $this->travel(5)->minutes();
        $this->assertSame(0, $service->evaluate()->notified);
        $this->assertEquals($work, DB::table('customer_usdt_top_up_settlement_work')->first());

        DB::table('customer_usdt_top_up_settlement_work')->update(['next_check_at' => now()->addMinute()]);
        $evaluation = $service->evaluate();
        $this->assertSame(1, $evaluation->resolved);
        $this->assertSame(0, $evaluation->notified);
        $this->assertSame(0, $evaluation->deliveryPending);
        $this->assertSame(BalanceAlertState::Resolved, $episode->fresh()->state);
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
        Queue::assertNotPushed(SendBalanceOperationsAlert::class);

        config()->set('services.telegram.admin_telegram_chat_id', '');
        DB::table('customer_usdt_top_up_settlement_work')->update(['next_check_at' => now()->subMinutes(31)]);
        $reopened = $service->evaluate();
        $this->assertSame(1, $reopened->opened);
        $this->assertSame(0, $reopened->notified);
        $this->assertSame(0, $reopened->deliveryPending);
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    #[DataProvider('queuedAlerts')]
    public function test_queued_alert_is_terminal_without_transport_or_retry(string $state, bool $resolved): void
    {
        $effect = $this->alertEffect('settlement_backlog', $resolved);
        $effect->forceFill(['state' => $state, 'attempts' => $state === 'failed' ? 1 : 0])->save();
        $this->mock(TelegramBotClient::class)->shouldNotReceive('sendMessage');

        $job = new SendBalanceOperationsAlert((string) $effect->id);
        $job->handle(app(BalanceOperationsAlertDelivery::class));
        $job->handle(app(BalanceOperationsAlertDelivery::class));

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertSame('balance_operations_alert_disabled', $effect->last_error_code);
        $this->assertNull($effect->sent_at);
        $this->assertNull($effect->next_attempt_at);
        $this->assertNull($effect->claim_token);
        $this->assertSame($state === 'failed' ? 2 : 1, $effect->attempts);
        $this->assertFalse(app(TelegramOutboundEffectLedgerProtocol::class)->retryableQuery()->whereKey($effect->id)->exists());
    }

    public static function queuedAlerts(): array
    {
        return [
            'pending open' => ['pending', false],
            'pending resolution' => ['pending', true],
            'retry open' => ['failed', false],
            'retry resolution' => ['failed', true],
        ];
    }

    public function test_other_keys_still_send_even_with_the_same_message_text(): void
    {
        $keys = array_diff(BalanceOperationsAlertService::ALERT_KEYS, ['settlement_backlog']);
        $this->mock(TelegramBotClient::class)->shouldReceive('sendMessage')->times(count($keys) * 2)
            ->andReturn(['ok' => true, 'result' => ['message_id' => 123]]);

        foreach ($keys as $key) {
            $effect = $this->alertEffect($key, false);
            (new SendBalanceOperationsAlert((string) $effect->id))->handle(app(BalanceOperationsAlertDelivery::class));
            $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state, $key);

            $episode = BalanceOperationsAlertEpisode::query()->where('alert_key', $key)->sole();
            $resolution = app(TelegramOutboundEffectService::class)->recordBalanceOperationsAlert(
                $episode, '900100200', '[SELESAI] Kondisi pulih', true,
            );
            (new SendBalanceOperationsAlert((string) $resolution->id))->handle(app(BalanceOperationsAlertDelivery::class));
            $this->assertSame(TelegramOutboundEffectState::Sent, $resolution->fresh()->state, $key);
        }
    }

    private function alertEffect(string $key, bool $resolved): TelegramOutboundEffect
    {
        $episode = BalanceOperationsAlertEpisode::query()->create([
            'alert_key' => $key,
            'severity' => BalanceAlertSeverity::Warning,
            'state' => $resolved ? BalanceAlertState::Resolved : BalanceAlertState::Open,
            'summary' => 'Backlog penyelesaian top-up USDT',
            'fingerprint' => hash('sha256', $key),
            'evidence' => [],
            'occurrences' => 1,
            'open_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return app(TelegramOutboundEffectService::class)->recordBalanceOperationsAlert(
            $episode, '900100200', 'Backlog penyelesaian top-up USDT', $resolved,
        );
    }
}
