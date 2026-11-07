<?php

namespace App\Services\WijayaPay;

use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Support\PaymentProvider;

/**
 * Routes a signed WijayaPay callback to the Store Balance top-up rail.
 *
 * The callback is only a wakeup and an authenticated reference claim. The
 * authoritative /api/get-status lookup owned by StoreBalanceTopUpService
 * decides whether any money moves, including for an already expired top-up.
 */
final class WijayaPayStoreBalanceTopUpCallbackProcessor
{
    public function __construct(
        private readonly WijayaPayStoreBalanceTopUpEventLogger $events,
        private readonly StoreBalanceTopUpService $topUps,
    ) {}

    public function find(string $merchantRef): ?StoreBalanceTopUp
    {
        return StoreBalanceTopUp::query()
            ->whereRaw('LOWER(TRIM(provider)) = ?', [PaymentProvider::WIJAYAPAY])
            ->where('merchant_ref', $merchantRef)
            ->first();
    }

    public function processCallback(WijayaPayCallback $callback): ?StoreBalanceTopUpEvent
    {
        $topUp = $this->find($callback->refId);
        if ($topUp === null) {
            return null;
        }

        $event = $this->events->logCallback($callback, $topUp);
        if ($event->processed_at !== null) {
            return $event->fresh();
        }

        if ($callback->isPending()
            || in_array($topUp->status, [
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Compensated,
                StoreBalanceTopUpStatus::InvalidProviderResult,
            ], true)
        ) {
            return $this->events->markProcessed($event);
        }

        // Expired status is deliberately not terminal for verification: a
        // provider-confirmed payment that arrives late must still be found and
        // settled under the existing late-settlement rules. InvalidProviderResult
        // stays in manual review and is handled above.
        $this->topUps->reconcile($topUp->fresh() ?? $topUp);
        $current = $topUp->fresh();

        if ($current?->status === StoreBalanceTopUpStatus::Paid
            || $current?->status === StoreBalanceTopUpStatus::InvalidProviderResult
            || ($callback->isExpired() && $current?->status === StoreBalanceTopUpStatus::Expired)
        ) {
            return $this->events->markProcessed($event);
        }

        // A paid callback contradicted by an authoritative pending/expired
        // answer remains durable, unprocessed evidence instead of being
        // treated as a failure or as proof of payment.
        return $event->fresh();
    }
}
