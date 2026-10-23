<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramCredentialPinService;
use App\Support\DogfoodTiming;
use App\Support\QueueTopology;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Attempts the optional credential pin after the receipt was already
 * acknowledged. The job owns no delivery state: a failure simply leaves the
 * Order unpinned for `telegram:recover-outbound` to retry.
 */
class PinTelegramCredentialReceipt implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public string $orderId,
        public string $effectId,
        public ?string $jobEnqueuedAt = null,
    ) {
        $this->onQueue(QueueTopology::PINS);
    }

    public function handle(TelegramCredentialPinService $pins): void
    {
        DogfoodTiming::log('dogfood.telegram.credential_pin_job_picked_up', [
            'order_correlation_id' => DogfoodTiming::correlation($this->orderId),
            'effect_correlation_id' => DogfoodTiming::correlation($this->effectId),
            'job_enqueued_at' => $this->jobEnqueuedAt,
            'job_picked_up_at' => now()->toISOString(),
            'queue_wait_ms' => DogfoodTiming::queueWaitMs($this->jobEnqueuedAt),
            'queue_wait_unknown' => $this->jobEnqueuedAt === null,
        ]);

        $order = Order::query()
            ->with('customer')
            ->find($this->orderId);
        $effect = TelegramOutboundEffect::query()
            ->whereKey($this->effectId)
            ->where('source_type', Order::class)
            ->where('source_id', $this->orderId)
            ->first();

        if (! $order instanceof Order || ! $effect instanceof TelegramOutboundEffect) {
            return;
        }

        $pins->pin($order, $effect);
    }
}
