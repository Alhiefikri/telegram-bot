<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBinanceVerifyIntents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyBinanceOrderId implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly int $attemptNumber,
        public readonly int $retryNumber = 1,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->paymentId, $this->attemptNumber, $this->retryNumber]);
    }

    public function handle(TelegramBinanceVerifyIntents $intents): void
    {
        $intents->retryOrderId($this->paymentId, $this->orderId, $this->attemptNumber, $this->retryNumber);
    }
}
