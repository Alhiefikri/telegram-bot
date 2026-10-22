<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Support\DogfoodTiming;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Occupies the payment verification queue for a bounded time so the baseline
 * can measure whether a Telegram update is blocked behind it. It performs no
 * provider call and moves no money.
 */
final class SlowPaymentVerificationProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $sleepMs,
        public string $marker,
    ) {}

    public function handle(): void
    {
        DogfoodTiming::log('dogfood.telegram.queue_probe_started', [
            'correlation_id' => DogfoodTiming::correlation($this->marker),
            'probe_sleep_ms' => $this->sleepMs,
        ]);

        usleep(max(0, $this->sleepMs) * 1000);

        DogfoodTiming::log('dogfood.telegram.queue_probe_finished', [
            'correlation_id' => DogfoodTiming::correlation($this->marker),
        ]);
    }
}
