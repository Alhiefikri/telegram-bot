<?php

namespace App\Jobs;

use App\Models\ManualPaymentSetting;
use App\Services\Telegram\TelegramAdminStatsIntents;
use App\Support\DogfoodTiming;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDailyAdminStatsNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public readonly string $jobEnqueuedAt;

    public function __construct(
        string $jobEnqueuedAt = '',
    ) {
        $this->jobEnqueuedAt = $jobEnqueuedAt ?: now()->toISOString();
    }

    public function handle(TelegramAdminStatsIntents $statsIntents): void
    {
        $startedAt = DogfoodTiming::now();
        $memoryBefore = memory_get_usage(true);
        DogfoodTiming::resetQueries();

        DogfoodTiming::log('dogfood.telegram.send_daily_admin_stats_notification_job_picked_up', [
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
        ]);

        try {
            $setting = ManualPaymentSetting::current();
            $adminChatId = $setting?->admin_telegram_chat_id;

            if ($adminChatId) {
                $statsIntents->sendAutomatedDailyStats($adminChatId);
            }
        } finally {
            DogfoodTiming::log('dogfood.telegram.send_daily_admin_stats_notification_job_finished', DogfoodTiming::queryStats() + [
                'handler_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
                'memory_before_bytes' => $memoryBefore,
                'memory_after_bytes' => memory_get_usage(true),
            ]);
        }
    }
}
