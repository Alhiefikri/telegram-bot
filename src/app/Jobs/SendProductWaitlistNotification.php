<?php

namespace App\Jobs;

use App\Services\ProductWaitlistNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductWaitlistNotification implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 900;

    public function __construct(public readonly int|string $notificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProductWaitlistNotificationService $notifications): void
    {
        $notifications->deliver($this->notificationId);
    }
}
