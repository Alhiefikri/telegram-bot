<?php

namespace App\Jobs;

use App\Services\ProductWaitlistNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeProductWaitlist implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 900;

    public function __construct(public readonly int|string $movementId) {}

    public function uniqueId(): string
    {
        return (string) $this->movementId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProductWaitlistNotificationService $notifications): void
    {
        $notifications->materialize($this->movementId);
    }
}
