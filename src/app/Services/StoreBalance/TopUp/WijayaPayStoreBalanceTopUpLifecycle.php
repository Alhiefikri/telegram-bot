<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\Payments\QrPresentation;
use App\Services\WijayaPay\WijayaPayApplicationException;
use App\Services\WijayaPay\WijayaPayCreateInvoice;
use App\Services\WijayaPay\WijayaPayCreatePreRequestException;
use App\Services\WijayaPay\WijayaPayInvoice;
use App\Services\WijayaPay\WijayaPayPayableInvoice;
use App\Services\WijayaPay\WijayaPayPayloadSanitizer;
use App\Services\WijayaPay\WijayaPayProviderAdapter;
use App\Services\WijayaPay\WijayaPayProviderException;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPayQrisImage;
use App\Services\WijayaPay\WijayaPaySchemaException;
use App\Services\WijayaPay\WijayaPayStatus;
use App\Services\WijayaPay\WijayaPayStatusResponse;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * WijayaPay Store Balance top-up rail.
 *
 * Creates and refreshes the same merchant reference through the shared
 * adapter. QR payloads and allowlisted image URLs stay encrypted. Money
 * settlement remains owned by StoreBalanceTopUpService.
 */
final class WijayaPayStoreBalanceTopUpLifecycle implements StoreBalanceTopUpLifecycle
{
    private const STATE_CREATING = 'creating';

    private const STATE_CREATED = 'created';

    private const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    private const STATE_QRIS_UNUSABLE = 'provider_qris_unusable';

    private const STATE_EXPIRED = 'provider_expired';

    private const STATE_SUCCESS = 'provider_berhasil';

    public function __construct(
        private readonly WijayaPayProviderAdapter $provider,
        private readonly WijayaPayProviderReadiness $readiness,
        private readonly StoreBalanceTopUpDecisionRecorder $events,
        private readonly StoreBalanceTopUpReviewMarker $reviews,
        private readonly QrPresentation $qrPresentation,
        private readonly WijayaPayQrisImage $qrisImage,
    ) {}

    public function provider(): string
    {
        return PaymentProvider::WIJAYAPAY;
    }

    public function classify(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $topUp->refresh();

        if (in_array($topUp->status, [
            StoreBalanceTopUpStatus::Paid,
            StoreBalanceTopUpStatus::Compensated,
            StoreBalanceTopUpStatus::InvalidProviderResult,
        ], true)) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Expired || $this->deadlinePassed($topUp)) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Expired);
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Payable
            && trim((string) $topUp->provider_create_state) === self::STATE_CREATED
            && (filled($topUp->provider_qris_payload) || filled($topUp->provider_qris_url))
            && $topUp->provider_total_payment !== null
            && $topUp->provider_fee !== null
            && $topUp->provider_expires_at?->isFuture() === true
        ) {
            try {
                WijayaPayPayableInvoice::fromPersistedTopUp($topUp);

                return StoreBalanceTopUpLifecycleResult::payableResult($this->payable($topUp));
            } catch (WijayaPaySchemaException) {
                return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
            }
        }

        return StoreBalanceTopUpLifecycleResult::state(
            match (trim((string) $topUp->provider_create_state)) {
                self::STATE_CREATING,
                self::STATE_OUTCOME_UNKNOWN => StoreBalanceTopUpOutcome::RecoveryPending,
                self::STATE_QRIS_UNUSABLE => StoreBalanceTopUpOutcome::InvalidProviderResult,
                self::STATE_EXPIRED => StoreBalanceTopUpOutcome::Expired,
                default => StoreBalanceTopUpOutcome::Creating,
            },
        );
    }

    public function presentation(StoreBalanceTopUp $topUp, bool $refresh = true): StoreBalanceTopUpPresentation
    {
        if ($refresh) {
            $topUp->refresh();
        }

        $state = trim((string) $topUp->provider_create_state);

        return StoreBalanceTopUpPresentation::fromLifecycle(
            $this->classify($topUp),
            paid: $topUp->status === StoreBalanceTopUpStatus::Paid,
            recovered: $topUp->status === StoreBalanceTopUpStatus::Paid
                && in_array($state, [self::STATE_SUCCESS, 'reconciled_'.self::STATE_SUCCESS], true),
            retryable: $topUp->canRetryCreate(),
            manualReview: $topUp->status === StoreBalanceTopUpStatus::InvalidProviderResult,
            checkable: $topUp->status === StoreBalanceTopUpStatus::Payable,
        );
    }

    public function lifecycle(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $classified = $this->classify($topUp);
        if ($classified->isPayable()
            || in_array($classified->outcome(), [
                StoreBalanceTopUpOutcome::Expired,
                StoreBalanceTopUpOutcome::InvalidProviderResult,
                StoreBalanceTopUpOutcome::RecoveryPending,
            ], true)
        ) {
            return $classified;
        }

        if (! $this->readiness->configurationReport()->isReady()) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable);
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Failed) {
            $this->resetFailure($topUp);
            $topUp->refresh();
        }

        if (! $this->markCreating($topUp)) {
            return $this->classify($topUp);
        }

        try {
            $invoice = $this->provider->createInvoice($this->createInvoice($topUp->fresh()));
        } catch (WijayaPayCreatePreRequestException $exception) {
            $this->markCreateFailed($topUp, $exception, providerUnavailable: true);
            Log::warning('WijayaPay top-up create did not reach the provider.', [
                'top_up_id' => $topUp->getKey(),
                'reason' => $exception->reason,
            ]);

            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable);
        } catch (WijayaPayProviderException $exception) {
            $this->markCreateFailed($topUp, $exception);

            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Creating);
        } catch (Throwable $exception) {
            $this->markCreateFailed($topUp, $exception);
            Log::warning('WijayaPay top-up create outcome is unknown.', [
                'top_up_id' => $topUp->getKey(),
                'exception_class' => $exception::class,
            ]);

            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        if (! $this->persistInvoice($topUp, $invoice)) {
            return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Expired);
        }

        return $this->classify($topUp->fresh() ?? $topUp);
    }

    public function reconcile(StoreBalanceTopUp $topUp): StoreBalanceTopUpReconciliation
    {
        $topUp->refresh();

        if (! $this->readiness->configurationReport()->isReady()) {
            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        if (in_array($topUp->status, [
            StoreBalanceTopUpStatus::Paid,
            StoreBalanceTopUpStatus::InvalidProviderResult,
            StoreBalanceTopUpStatus::Compensated,
        ], true)) {
            return new StoreBalanceTopUpReconciliation($this->classify($topUp));
        }

        if (blank($topUp->merchant_ref)) {
            return new StoreBalanceTopUpReconciliation($this->classify($topUp));
        }

        try {
            $status = $this->provider->transactionStatus((string) $topUp->merchant_ref);
        } catch (WijayaPayProviderException $exception) {
            if ($exception->isNotFound() && $this->canRetryAmbiguousCreate($topUp)) {
                $this->resetForRetry($topUp);

                return new StoreBalanceTopUpReconciliation($this->lifecycle($topUp->fresh() ?? $topUp));
            }

            Log::warning('WijayaPay top-up status lookup failed safely.', [
                'top_up_id' => $topUp->getKey(),
                'exception_class' => $exception::class,
                'reason' => $exception->reason,
            ]);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        } catch (Throwable $exception) {
            Log::warning('WijayaPay top-up status lookup failed safely.', [
                'top_up_id' => $topUp->getKey(),
                'exception_class' => $exception::class,
                'reason' => 'unexpected',
            ]);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        $diagnostics = $this->statusDiagnostics($topUp, $status);
        $this->recordObservation($topUp, $status, $diagnostics);
        if ($diagnostics['conflicting_fields'] !== []) {
            $this->reviews->mark($topUp, 'provider_identity_mismatch', self::STATE_OUTCOME_UNKNOWN);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
        }

        $event = $this->events->record(
            $topUp,
            PaymentProvider::WIJAYAPAY,
            'reconciliation',
            $status->statusValue(),
            [
                ...$status->sanitizedPayload,
                'merchant_ref_hash' => hash('sha256', (string) $topUp->merchant_ref),
            ],
            $status->trxReference,
        );

        if ($status->isExpired()) {
            $this->markExpired($topUp);

            return new StoreBalanceTopUpReconciliation(
                StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Expired),
            );
        }

        if ($status->isPaid()) {
            if (! $this->persistPaidObservation($topUp, $status)) {
                $this->reviews->mark($topUp, 'provider_evidence_missing', self::STATE_OUTCOME_UNKNOWN);

                return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
            }

            $topUp->refresh();

            if ($diagnostics['missing_fields'] !== []) {
                $this->reviews->mark($topUp, 'provider_evidence_missing', self::STATE_OUTCOME_UNKNOWN);

                return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
            }

            return new StoreBalanceTopUpReconciliation(
                $this->classify($topUp->fresh() ?? $topUp),
                $this->evidence($topUp, $status, trusted: true),
                (string) $event->getKey(),
            );
        }

        if ($status->isPending()
            && ! $this->deadlinePassed($topUp)
            && ! $this->classify($topUp)->isPayable()
        ) {
            $this->persistStatusPresentation($topUp, $status);
        }

        return new StoreBalanceTopUpReconciliation($this->classify($topUp->fresh() ?? $topUp));
    }

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment {
        try {
            $presentation = WijayaPayPayableInvoice::fromPersistedTopUp($topUp);
        } catch (WijayaPaySchemaException $exception) {
            throw new RuntimeException('WijayaPay top-up presentation is unavailable.', previous: $exception);
        }

        return new StoreBalanceTopUpInstructionAttachment(
            $presentation->isQrisImage()
                ? $this->qrPresentation->image($this->qrisImage->contents($presentation))
                : $this->qrPresentation->payload((string) $presentation->qrisPayload),
        );
    }

    private function createInvoice(StoreBalanceTopUp $topUp): WijayaPayCreateInvoice
    {
        $topUp->loadMissing('customer');

        try {
            return new WijayaPayCreateInvoice(
                codeMerchant: (string) config('services.wijayapay.code_merchant'),
                apiKey: (string) config('services.wijayapay.api_key'),
                paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                refId: (string) $topUp->merchant_ref,
                amount: (int) $topUp->amount,
                callbackUrl: (string) config('services.wijayapay.callback_url'),
                customerName: $topUp->customer?->first_name,
            );
        } catch (\InvalidArgumentException|\TypeError $exception) {
            throw new WijayaPayCreatePreRequestException('request_invalid', $exception);
        }
    }

    private function markCreating(StoreBalanceTopUp $topUp): bool
    {
        $reserved = DB::transaction(function () use ($topUp): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status === StoreBalanceTopUpStatus::Payable
                || in_array($locked->status, [
                    StoreBalanceTopUpStatus::Paid,
                    StoreBalanceTopUpStatus::Expired,
                    StoreBalanceTopUpStatus::InvalidProviderResult,
                    StoreBalanceTopUpStatus::Compensated,
                ], true)
                || in_array($locked->provider_create_state, [self::STATE_CREATING, self::STATE_OUTCOME_UNKNOWN], true)
                || ($locked->status === StoreBalanceTopUpStatus::Failed && ! $locked->canRetryCreate())
                || filled($locked->provider_reference)
            ) {
                return false;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => self::STATE_CREATING,
                'provider_create_attempted_at' => now(),
                'provider_create_last_error' => null,
            ])->save();

            return true;
        }, 5);

        if ($reserved) {
            $topUp->refresh();
        }

        return $reserved;
    }

    private function persistInvoice(StoreBalanceTopUp $topUp, WijayaPayInvoice $invoice): bool
    {
        return DB::transaction(function () use ($topUp, $invoice): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || in_array($locked->status, [
                    StoreBalanceTopUpStatus::Paid,
                    StoreBalanceTopUpStatus::Expired,
                    StoreBalanceTopUpStatus::InvalidProviderResult,
                    StoreBalanceTopUpStatus::Compensated,
                ], true)
            ) {
                return false;
            }

            if ($invoice->refId !== (string) $locked->merchant_ref
                || (filled($locked->provider_reference) && $locked->provider_reference !== $invoice->trxReference)
            ) {
                $this->reviews->mark($locked, 'provider_identity_mismatch', self::STATE_QRIS_UNUSABLE);

                return true;
            }

            $providerExpiresAt = $invoice->expiresAt;
            $rowExpiresAt = $locked->expires_at;
            if ($providerExpiresAt !== null
                && ($rowExpiresAt === null || $providerExpiresAt->lt($rowExpiresAt))
            ) {
                $rowExpiresAt = CarbonImmutable::instance($providerExpiresAt);
            }

            [$qrisPayload, $qrisImageUrl] = $this->safePresentation($invoice);
            $isPayable = $invoice->isPending()
                && $rowExpiresAt !== null
                && $rowExpiresAt->isFuture()
                && ($qrisPayload !== null || $qrisImageUrl !== null);

            $locked->forceFill([
                'provider_reference' => $invoice->trxReference,
                'provider_payload' => WijayaPayPayloadSanitizer::sanitize($invoice->sanitizedPayload),
                'provider_qris_payload' => $isPayable ? $qrisPayload : null,
                'provider_qris_url' => $isPayable ? $qrisImageUrl : null,
                'provider_fee' => $invoice->totalFee,
                'provider_total_payment' => $invoice->totalValue(),
                'provider_payment_method' => WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'expires_at' => $rowExpiresAt ?? $locked->expires_at,
                'status' => $isPayable
                    ? StoreBalanceTopUpStatus::Payable
                    : ($invoice->isExpired() ? StoreBalanceTopUpStatus::Expired : StoreBalanceTopUpStatus::Pending),
                'provider_create_state' => $isPayable
                    ? self::STATE_CREATED
                    : ($invoice->isExpired() ? self::STATE_EXPIRED : self::STATE_QRIS_UNUSABLE),
                'provider_create_last_error' => $isPayable
                    ? null
                    : ($invoice->isExpired() ? self::STATE_EXPIRED : self::STATE_QRIS_UNUSABLE),
            ])->save();

            return true;
        }, 5);
    }

    private function persistStatusPresentation(StoreBalanceTopUp $topUp, WijayaPayStatusResponse $status): void
    {
        if ($status->totalValue() === null) {
            return;
        }

        try {
            $invoice = new WijayaPayInvoice(
                refId: $status->refId,
                trxReference: $status->trxReference,
                paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                status: WijayaPayStatus::Pending,
                totalBayar: $status->totalValue(),
                totalFee: $status->feeValue() ?? 0,
                totalDiterima: max(0, $status->totalValue() - ($status->feeValue() ?? 0)),
                feePayer: WijayaPayInvoice::feePayer(
                    (int) $topUp->amount,
                    $status->totalValue(),
                    $status->feeValue() ?? 0,
                ),
                expiresAt: $status->expiresAt,
                updatedAt: $status->updatedAt,
                qrString: $status->qrPayload(),
                qrImageUrl: $status->qrImageUrl(),
                sanitizedPayload: $status->sanitizedPayload,
            );

            $this->persistInvoice($topUp, $invoice);
        } catch (WijayaPaySchemaException) {
            $this->reviews->mark($topUp, 'provider_presentation_unusable', self::STATE_QRIS_UNUSABLE);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function safePresentation(WijayaPayInvoice $invoice): array
    {
        try {
            return WijayaPayPayableInvoice::resolvePresentation(
                $invoice->qrPayload(),
                $invoice->qrImageUrl(),
                $invoice->totalValue(),
            );
        } catch (WijayaPaySchemaException) {
            return [null, null];
        }
    }

    private function markCreateFailed(StoreBalanceTopUp $topUp, Throwable $exception, bool $providerUnavailable = false): void
    {
        DB::transaction(function () use ($topUp, $exception, $providerUnavailable): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || in_array($locked->status, [
                    StoreBalanceTopUpStatus::Paid,
                    StoreBalanceTopUpStatus::Expired,
                    StoreBalanceTopUpStatus::InvalidProviderResult,
                    StoreBalanceTopUpStatus::Compensated,
                ], true)
            ) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Failed,
                'provider_create_state' => $providerUnavailable
                    ? 'provider_unavailable'
                    : ($exception instanceof WijayaPayCreatePreRequestException
                        || $exception instanceof WijayaPayApplicationException
                        ? 'create_failed'
                        : self::STATE_OUTCOME_UNKNOWN),
                'provider_create_last_error' => substr($exception::class, 0, 500),
            ])->save();
        }, 5);
    }

    private function resetFailure(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || ! $locked->canRetryCreate()) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => null,
                'provider_create_last_error' => null,
            ])->save();
        }, 5);
    }

    private function markExpired(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || in_array($locked->status, [
                    StoreBalanceTopUpStatus::Paid,
                    StoreBalanceTopUpStatus::Compensated,
                    StoreBalanceTopUpStatus::InvalidProviderResult,
                ], true)
            ) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Expired,
                'provider_create_state' => self::STATE_EXPIRED,
                'provider_create_last_error' => null,
            ])->save();
        }, 5);
    }

    /** @return array{missing_fields: list<string>, conflicting_fields: list<string>} */
    private function statusDiagnostics(StoreBalanceTopUp $topUp, WijayaPayStatusResponse $status): array
    {
        $missing = [];
        $conflicting = [];

        if ($status->refId !== (string) $topUp->merchant_ref) {
            $conflicting[] = 'merchant_ref';
        }
        if (filled($topUp->provider_reference) && $status->trxReference !== (string) $topUp->provider_reference) {
            $conflicting[] = 'provider_reference';
        }

        $total = $topUp->provider_total_payment;
        $amount = $status->amountValue();
        $amountCandidates = [(int) $topUp->amount, $total];
        if ($status->feeValue() !== null && $status->feeValue() >= 0) {
            $amountCandidates[] = (int) $topUp->amount + $status->feeValue();
        }
        if ($status->totalValue() === null && $status->totalDibayar === null) {
            $missing[] = 'total';
        }
        if ($amount !== null
            && ! in_array($amount, array_values(array_unique($amountCandidates)), true)
        ) {
            $conflicting[] = 'amount';
        }
        if ($status->totalValue() !== null
            && (($total !== null && $status->totalValue() !== $total) || $status->totalValue() < (int) $topUp->amount)
        ) {
            $conflicting[] = 'total';
        }
        if ($status->feeValue() !== null
            && $topUp->provider_fee !== null
            && $status->feeValue() !== (int) $topUp->provider_fee
        ) {
            $conflicting[] = 'fee';
        }

        if ($status->feeValue() !== null) {
            try {
                WijayaPayInvoice::assertFeeWithinCap($status->feeValue(), (int) $topUp->amount);
            } catch (WijayaPaySchemaException) {
                $conflicting[] = 'fee';
            }

            if ($status->totalValue() !== null && $status->feeValue() > $status->totalValue()) {
                $conflicting[] = 'fee';
            }
        }

        return ['missing_fields' => $missing, 'conflicting_fields' => array_values(array_unique($conflicting))];
    }

    /** @param array{missing_fields: list<string>, conflicting_fields: list<string>} $diagnostics */
    private function recordObservation(StoreBalanceTopUp $topUp, WijayaPayStatusResponse $status, array $diagnostics): void
    {
        $this->events->record(
            $topUp,
            PaymentProvider::WIJAYAPAY,
            'reconciliation_evidence',
            $status->statusValue(),
            ['source' => 'status', ...$diagnostics],
            $status->trxReference,
        );
    }

    private function evidence(
        StoreBalanceTopUp $topUp,
        WijayaPayStatusResponse $status,
        bool $trusted,
    ): StoreBalanceTopUpSettlementEvidence {
        $baseAmount = (int) $topUp->amount;
        $recordedTotal = (int) ($topUp->provider_total_payment ?? $baseAmount);
        $reportedAmount = $status->amountValue();
        $reportedFee = $status->feeValue();
        $isCustomerFeeTotal = $reportedAmount !== null
            && $reportedFee !== null
            && $reportedAmount === $baseAmount + $reportedFee;

        return new StoreBalanceTopUpSettlementEvidence(
            provider: PaymentProvider::WIJAYAPAY,
            merchantReference: (string) $topUp->merchant_ref,
            providerReference: $status->trxReference,
            providerStatus: $status->statusValue(),
            amount: $reportedAmount === null || $reportedAmount === $baseAmount || $reportedAmount === $recordedTotal || $isCustomerFeeTotal
                ? $baseAmount
                : $reportedAmount,
            totalAmount: $status->totalValue() ?? $recordedTotal,
            trusted: $trusted,
            providerFee: $reportedFee,
            paidAt: $status->updatedAt === null ? null : CarbonImmutable::instance($status->updatedAt),
            expiresAt: $status->expiresAt === null ? null : CarbonImmutable::instance($status->expiresAt),
        );
    }

    private function persistPaidObservation(StoreBalanceTopUp $topUp, WijayaPayStatusResponse $status): bool
    {
        return DB::transaction(function () use ($topUp, $status): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || in_array($locked->status, [
                    StoreBalanceTopUpStatus::Paid,
                    StoreBalanceTopUpStatus::Compensated,
                    StoreBalanceTopUpStatus::InvalidProviderResult,
                ], true)
            ) {
                return true;
            }

            if (filled($locked->provider_reference)
                && $locked->provider_reference !== $status->trxReference
            ) {
                $this->reviews->mark($locked, 'provider_reference_mismatch', self::STATE_OUTCOME_UNKNOWN);

                return false;
            }

            $total = $status->totalValue() ?? $status->totalDibayar;
            $fee = $status->feeValue() ?? $locked->provider_fee;
            if ($total === null
                || $fee === null
                || $total < (int) $locked->amount
                || $fee < 0
                || $fee > $total
            ) {
                return false;
            }

            $providerExpiresAt = $status->expiresAt ?? $locked->provider_expires_at;
            $expiresAt = $locked->expires_at;
            if ($providerExpiresAt !== null
                && ($expiresAt === null || $providerExpiresAt->lt($expiresAt))
            ) {
                $expiresAt = CarbonImmutable::instance($providerExpiresAt);
            }

            $locked->forceFill([
                'provider_reference' => $locked->provider_reference ?: $status->trxReference,
                'provider_payload' => WijayaPayPayloadSanitizer::sanitize(array_replace_recursive(
                    is_array($locked->provider_payload) ? $locked->provider_payload : [],
                    $status->sanitizedPayload,
                )),
                'provider_fee' => $fee,
                'provider_total_payment' => $total,
                'provider_payment_method' => WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
                'provider_expires_at' => $providerExpiresAt,
                'expires_at' => $expiresAt,
            ])->save();

            return true;
        }, 5);
    }

    private function deadlinePassed(StoreBalanceTopUp $topUp): bool
    {
        return ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture())
            || ($topUp->provider_expires_at !== null && ! $topUp->provider_expires_at->isFuture());
    }

    private function canRetryAmbiguousCreate(StoreBalanceTopUp $topUp): bool
    {
        if (filled($topUp->provider_reference)
            || $topUp->provider_total_payment !== null
            || $this->deadlinePassed($topUp)
            || ! in_array($topUp->provider_create_state, [
                self::STATE_OUTCOME_UNKNOWN,
                self::STATE_CREATING,
                'create_failed',
            ], true)
        ) {
            return false;
        }

        $windowSeconds = max(0, (int) config('services.wijayapay.create_recovery_window_seconds', 900));
        if ($windowSeconds > 0
            && $topUp->created_at !== null
            && $topUp->created_at->addSeconds($windowSeconds)->isPast()
        ) {
            return false;
        }

        $intervalSeconds = max(0, (int) config('services.wijayapay.create_retry_min_interval_seconds', 60));
        $attemptedAt = $topUp->provider_create_attempted_at;

        return $intervalSeconds === 0
            || $attemptedAt === null
            || $attemptedAt->addSeconds($intervalSeconds)->isPast();
    }

    private function resetForRetry(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || filled($locked->provider_reference)
                || $locked->provider_total_payment !== null
            ) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Pending,
                'provider_create_state' => null,
                'provider_create_last_error' => null,
            ])->save();
        }, 5);
    }

    private function payable(StoreBalanceTopUp $topUp): StoreBalanceTopUpPayable
    {
        $expiresAt = $topUp->provider_expires_at ?? $topUp->expires_at;
        if ($expiresAt === null) {
            throw new RuntimeException('WijayaPay Store Balance top-up has no deadline.');
        }

        return new StoreBalanceTopUpPayable(
            creditAmount: (int) $topUp->amount,
            providerFee: $topUp->provider_fee === null ? 0 : (int) $topUp->provider_fee,
            totalAmount: $topUp->provider_total_payment ?? (int) $topUp->amount,
            expiresAt: CarbonImmutable::instance($expiresAt),
        );
    }
}
