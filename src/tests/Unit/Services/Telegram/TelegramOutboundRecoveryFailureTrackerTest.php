<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramOutboundRecoveryFailureTracker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TelegramOutboundRecoveryFailureTrackerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/telegram-outbound-recovery-failures-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    public function test_one_transient_failure_does_not_reach_the_alert_threshold(): void
    {
        $tracker = new TelegramOutboundRecoveryFailureTracker($this->path);

        $this->assertSame(0, $tracker->failures());
        $this->assertSame(1, $tracker->recordFailure());
        $this->assertLessThan(TelegramOutboundRecoveryFailureTracker::ALERT_THRESHOLD, $tracker->failures());
    }

    public function test_consecutive_failures_reach_the_threshold_and_success_resets_it(): void
    {
        $tracker = new TelegramOutboundRecoveryFailureTracker($this->path);

        $this->assertSame(1, $tracker->recordFailure());
        $this->assertSame(2, $tracker->recordFailure());
        $this->assertSame(TelegramOutboundRecoveryFailureTracker::ALERT_THRESHOLD, $tracker->failures());

        $tracker->recordSuccess();

        $this->assertSame(0, $tracker->failures());
        $this->assertSame(1, $tracker->recordFailure());
    }
}
