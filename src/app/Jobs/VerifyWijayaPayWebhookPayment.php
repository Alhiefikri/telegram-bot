<?php

namespace App\Jobs;

use App\Enums\PaymentEventType;
use App\Models\PaymentEvent;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayStatusUnavailableException;
use App\Support\PaymentProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class VerifyWijayaPayWebhookPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [5, 10, 15, 30, 60];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wijayapay:webhook:event:'.$this->eventId;
    }

    public function handle(WijayaPayCallbackProcessor $processor): void
    {
        $event = PaymentEvent::query()->find($this->eventId);
        if ($event === null
            || $event->provider !== PaymentProvider::WIJAYAPAY
            || $event->event_type !== PaymentEventType::Callback
            || $event->processed_at !== null
        ) {
            return;
        }

        $processedEvent = ProviderVerificationProfiles::using(
            ProviderVerificationProfile::Reconciliation,
            fn (): PaymentEvent => $processor->processQueuedEvent($event),
        );

        if (! $processedEvent instanceof PaymentEvent || $processedEvent->processed_at === null) {
            throw new WijayaPayStatusUnavailableException;
        }
    }
}
