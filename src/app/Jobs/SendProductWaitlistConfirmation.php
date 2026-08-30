<?php

namespace App\Jobs;

use App\Services\ProductWaitlistSubscriptionService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductWaitlistConfirmation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int|string $subscriptionId,
        public readonly ?string $confirmationUrl = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->subscriptionId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProductWaitlistSubscriptionService $subscriptions): void
    {
        $subscriptions->sendConfirmation($this->subscriptionId, $this->confirmationUrl);
    }
}
