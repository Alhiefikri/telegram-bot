<?php

namespace App\Jobs;

use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class VerifyKlikQrisWebhookPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    /** @param array<mixed> $payload */
    public function __construct(public readonly array $payload) {}

    public function handle(KlikQrisCallbackProcessor $processor): void
    {
        ProviderVerificationProfiles::using(
            ProviderVerificationProfile::Reconciliation,
            fn () => $processor->processCallback($this->payload),
        );
    }
}
