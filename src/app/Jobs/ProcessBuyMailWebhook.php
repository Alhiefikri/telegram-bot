<?php

namespace App\Jobs;

use App\Services\Suppliers\BuyMailWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessBuyMailWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $eventId)
    {
        $this->onConnection('supplier')->onQueue('supplier-claims');
    }

    public function handle(BuyMailWebhookService $webhooks): void
    {
        $webhooks->process($this->eventId);
    }
}
