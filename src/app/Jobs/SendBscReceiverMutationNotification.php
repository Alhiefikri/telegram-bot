<?php

namespace App\Jobs;

use App\Services\Bsc\BscReceiverMutationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBscReceiverMutationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array<string, string> $payload */
    public function __construct(public readonly array $payload) {}

    public function handle(BscReceiverMutationNotifier $notifier): void
    {
        $notifier->notify($this->payload);
    }
}
