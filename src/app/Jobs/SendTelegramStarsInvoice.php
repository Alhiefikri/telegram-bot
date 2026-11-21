<?php

namespace App\Jobs;

use App\Services\TelegramStars\TelegramStarsInvoiceOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendTelegramStarsInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(TelegramStarsInvoiceOutbound $outbound): void
    {
        $outbound->deliver($this->effectId);
    }
}
