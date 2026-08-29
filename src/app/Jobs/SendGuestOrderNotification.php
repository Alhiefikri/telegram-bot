<?php

namespace App\Jobs;

use App\Services\GuestOrderNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendGuestOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(GuestOrderNotificationService $notifications): void
    {
        $notifications->deliver($this->eventId);
    }
}
