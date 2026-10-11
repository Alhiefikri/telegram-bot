<?php

namespace App\Jobs;

use App\Services\Customer\CustomerAuthMailDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendCustomerAuthMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 55;

    public function __construct(public readonly int $workId)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [60, 120, 300, 300];
    }

    public function handle(CustomerAuthMailDeliveryService $mail): void
    {
        $mail->deliver($this->workId);
    }
}
