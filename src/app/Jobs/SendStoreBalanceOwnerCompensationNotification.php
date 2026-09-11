<?php

namespace App\Jobs;

use App\Services\Telegram\OwnerCompensationNotificationDeliveryPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendStoreBalanceOwnerCompensationNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $compensationId,
        public ?string $jobEnqueuedAt = null,
        public ?string $effectId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->effectId === null
            ? 'owner-compensation:'.$this->compensationId
            : 'effect:'.$this->effectId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(?OwnerCompensationNotificationDeliveryPipeline $delivery = null): void
    {
        ($delivery ??= app(OwnerCompensationNotificationDeliveryPipeline::class))->deliver(
            $this->compensationId,
            $this->effectId,
        );
    }
}
