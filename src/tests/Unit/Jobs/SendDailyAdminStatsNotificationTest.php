<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendDailyAdminStatsNotification;
use App\Models\ManualPaymentSetting;
use App\Services\Telegram\TelegramAdminStatsIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendDailyAdminStatsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_daily_admin_stats_calls_intents_when_admin_chat_id_is_set(): void
    {
        ManualPaymentSetting::create([
            'admin_telegram_chat_id' => '123456789',
            'is_active' => true,
        ]);

        $mockIntents = $this->mock(TelegramAdminStatsIntents::class);
        $mockIntents->shouldReceive('sendAutomatedDailyStats')
            ->once()
            ->with('123456789');

        $job = new SendDailyAdminStatsNotification();
        $job->handle($mockIntents);
    }

    public function test_send_daily_admin_stats_does_nothing_if_no_admin_chat_id(): void
    {
        ManualPaymentSetting::query()->delete();

        $mockIntents = $this->mock(TelegramAdminStatsIntents::class);
        $mockIntents->shouldReceive('sendAutomatedDailyStats')->never();

        $job = new SendDailyAdminStatsNotification();
        $job->handle($mockIntents);
    }
}
