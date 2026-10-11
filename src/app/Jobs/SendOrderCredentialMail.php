<?php

namespace App\Jobs;

use App\Services\OrderCredentialMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Wakeup job for one durable credential email row. The payload carries the
 * event ID only: the recipient and the credential plaintext are read from the
 * database inside the delivery service.
 */
class SendOrderCredentialMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 55;

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 300];
    }

    public function handle(OrderCredentialMailService $mails): void
    {
        $mails->deliver($this->eventId);
    }
}
