<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisPayableBalanceTopUp;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisQrisImage;
use App\Services\KlikQris\KlikQrisStatusResponseException;
use App\Services\KlikQris\KlikQrisStatusUnavailableException;
use App\Services\KlikQris\KlikQrisTopUpLifecycleResult;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

/**
 * KlikQRIS Store Balance top-up rail.
 *
 * Delegates provider work to KlikQrisNativeQrisService and maps its
 * provider-specific outcomes and presentation onto the neutral top-up seam.
 * The order/Payment lifecycle of that service is untouched.
 */
final class KlikQrisStoreBalanceTopUpLifecycle implements StoreBalanceTopUpLifecycle
{
    public function __construct(
        private readonly KlikQrisNativeQrisService $nativeQris,
        private readonly KlikQrisProviderReadiness $readiness,
        private readonly KlikQrisQrisImage $images,
        private readonly StoreBalanceTopUpEventLogger $events,
        private readonly StoreBalanceTopUpReviewMarker $reviews,
    ) {}

    public function provider(): string
    {
        return PaymentProvider::KLIKQRIS;
    }

    public function classify(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $topUp->refresh();
        if ($this->isProviderUnavailable($topUp)) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable);
        }

        return $this->fromKlikQris($this->nativeQris->classifyTopUp($topUp));
    }

    public function presentation(StoreBalanceTopUp $topUp, bool $refresh = true): StoreBalanceTopUpPresentation
    {
        $presentation = $this->nativeQris->topUpPresentation($topUp, refresh: $refresh);
        $lifecycle = $this->isProviderUnavailable($topUp)
            ? StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable)
            : $this->fromKlikQrisPresentation($presentation->outcome(), $presentation->payable());

        return StoreBalanceTopUpPresentation::fromLifecycle(
            $lifecycle,
            paid: $presentation->isPaid(),
            recovered: $presentation->isRecovered(),
            retryable: $presentation->canRetry(),
            manualReview: $presentation->isManualReview(),
            checkable: $presentation->canCheck(),
        );
    }

    public function lifecycle(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        // Provider readiness gates create/recovery work only. A top-up whose
        // quote is already persisted stays readable and payable even while the
        // provider or its operational prerequisites are unavailable.
        $classified = $this->fromKlikQris($this->nativeQris->classifyTopUp($topUp));
        if ($classified->isPayable()
            || in_array($classified->outcome(), [
                StoreBalanceTopUpOutcome::Expired,
                StoreBalanceTopUpOutcome::InvalidProviderResult,
            ], true)) {
            return $classified;
        }

        if (! $this->readiness->active()->isReady()) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable);
        }

        if ($this->isProviderUnavailable($topUp)) {
            $this->resetUnavailable($topUp);
            $topUp->refresh();
        }

        try {
            return $this->fromKlikQris($this->nativeQris->lifecycleForTopUp($topUp));
        } catch (Throwable $exception) {
            report($exception);

            return $this->fromKlikQris($this->nativeQris->classifyTopUp($topUp));
        }
    }

    public function reconcile(StoreBalanceTopUp $topUp): StoreBalanceTopUpReconciliation
    {
        if (! $this->readiness->active()->isReady()) {
            // A configuration outage is not evidence about this top-up; keep
            // its state untouched and retry through scheduled reconciliation.
            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        $verified = null;
        $eventId = null;

        try {
            $result = $this->nativeQris->reconcileTopUp(
                $topUp,
                function (StoreBalanceTopUp $current, KlikQrisTransaction $transaction) use (&$verified, &$eventId): void {
                    if (! $this->hasTrustedSignature($current, $transaction)) {
                        return;
                    }

                    $verified = $this->evidence($current, $transaction, trusted: true);
                    $eventId = (string) $this->events->logReconciliation($transaction, $current)->getKey();
                },
            );
        } catch (KlikQrisStatusResponseException $exception) {
            $this->reviews->mark($topUp, $this->statusResponseReason($exception));

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
        } catch (UnexpectedValueException) {
            $this->reviews->mark($topUp, 'status_response_invalid');

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
        } catch (KlikQrisStatusUnavailableException) {
            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        $current = $topUp->fresh();
        if ($current !== null
            && $current->provider_create_state === 'provider_signature_missing'
            && ! in_array($current->status, [
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::Compensated,
            ], true)) {
            $this->reviews->mark($current, 'provider_signature_missing');
            $current = $topUp->fresh();
        }

        $result ??= StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Creating);

        return new StoreBalanceTopUpReconciliation(
            $current === null ? $result : $this->fromKlikQris($this->nativeQris->classifyTopUp($current)),
            $verified,
            $verified === null ? null : $eventId,
        );
    }

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment {
        $klikQrisPayable = KlikQrisPayableBalanceTopUp::fromTopUp($topUp);

        return new StoreBalanceTopUpInstructionAttachment(
            $this->images->contentsForTopUp($klikQrisPayable),
        );
    }

    private function evidence(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
        bool $trusted,
    ): StoreBalanceTopUpSettlementEvidence {
        return new StoreBalanceTopUpSettlementEvidence(
            provider: PaymentProvider::KLIKQRIS,
            merchantReference: $transaction->orderId(),
            providerReference: $transaction->providerReference(),
            providerStatus: $transaction->status(),
            amount: $transaction->amount()->rupiah(),
            totalAmount: $transaction->totalAmount(),
            trusted: $trusted,
            providerFee: $transaction->providerFee(),
            paidAt: $transaction->paidAt() === null ? null : CarbonImmutable::instance($transaction->paidAt()),
            expiresAt: $transaction->expiresAt(),
        );
    }

    private function isProviderUnavailable(StoreBalanceTopUp $topUp): bool
    {
        return trim((string) $topUp->provider_create_state) === 'provider_unavailable'
            && ! in_array($topUp->status, [
                StoreBalanceTopUpStatus::Paid,
                StoreBalanceTopUpStatus::Expired,
                StoreBalanceTopUpStatus::InvalidProviderResult,
                StoreBalanceTopUpStatus::Compensated,
            ], true);
    }

    private function resetUnavailable(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->provider_create_state !== 'provider_unavailable') {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => null,
                'provider_create_last_error' => null,
            ])->save();
        }, attempts: 5);
    }

    private function hasTrustedSignature(StoreBalanceTopUp $topUp, KlikQrisTransaction $transaction): bool
    {
        $stored = $topUp->providerSignature();
        $received = $transaction->signature();

        return $stored !== null
            && $received !== null
            && hash_equals($stored, $received);
    }

    private function statusResponseReason(KlikQrisStatusResponseException $exception): string
    {
        return $exception->httpStatus === null
            ? 'status_response_invalid'
            : 'status_http_'.$exception->httpStatus;
    }

    private function fromKlikQris(KlikQrisTopUpLifecycleResult $result): StoreBalanceTopUpLifecycleResult
    {
        return $this->fromKlikQrisPresentation($result->outcome(), $result->payable());
    }

    private function fromKlikQrisPresentation(
        KlikQrisNativeQrisOutcome $outcome,
        ?KlikQrisPayableBalanceTopUp $payable,
    ): StoreBalanceTopUpLifecycleResult {
        if ($outcome === KlikQrisNativeQrisOutcome::Payable && $payable !== null) {
            return StoreBalanceTopUpLifecycleResult::payableResult(
                new StoreBalanceTopUpPayable(
                    creditAmount: $payable->creditAmount(),
                    providerFee: $payable->providerFee(),
                    totalAmount: $payable->totalAmount(),
                    expiresAt: $payable->effectiveExpiresAt(),
                ),
            );
        }

        return StoreBalanceTopUpLifecycleResult::state(
            match ($outcome) {
                KlikQrisNativeQrisOutcome::Creating => StoreBalanceTopUpOutcome::Creating,
                KlikQrisNativeQrisOutcome::RecoveryPending => StoreBalanceTopUpOutcome::RecoveryPending,
                KlikQrisNativeQrisOutcome::ProviderTotalPending => StoreBalanceTopUpOutcome::ProviderTotalPending,
                KlikQrisNativeQrisOutcome::Expired => StoreBalanceTopUpOutcome::Expired,
                default => StoreBalanceTopUpOutcome::InvalidProviderResult,
            },
        );
    }
}
