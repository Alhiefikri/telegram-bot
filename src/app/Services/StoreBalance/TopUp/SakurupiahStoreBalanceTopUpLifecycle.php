<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\SakurupiahEnvironment;
use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceTopUp;
use App\Services\Payments\QrPresentation;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahCreatePreRequestException;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceIntegrityException;
use App\Services\Sakurupiah\SakurupiahPayableInvoice;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahQrisImage;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahStatusUnavailableException;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sakurupiah Store Balance top-up rail.
 *
 * Creates and refreshes the snapshotted channel through the shared adapter.
 * QR payloads and allowlisted image URLs stay encrypted. Settlement stays
 * with StoreBalanceTopUpService.
 */
final class SakurupiahStoreBalanceTopUpLifecycle implements StoreBalanceTopUpLifecycle
{
    private const STATE_CREATING = 'creating';

    private const STATE_CREATED = 'created';

    private const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';

    private const STATE_QRIS_UNUSABLE = 'provider_qris_unusable';

    private const STATE_EXPIRED = 'provider_expired';

    private const STATE_SUCCESS = 'provider_berhasil';

    public function __construct(
        private readonly SakurupiahProviderAdapter $provider,
        private readonly SakurupiahProviderReadiness $readiness,
        private readonly StoreBalanceTopUpDecisionRecorder $events,
        private readonly StoreBalanceTopUpReviewMarker $reviews,
        private readonly QrPresentation $qrPresentation,
        private readonly SakurupiahQrisImage $qrisImage,
    ) {}

    public function provider(): string
    {
        return PaymentProvider::SAKURUPIAH;
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
            && $topUp->provider_expires_at?->isFuture() === true) {
            return StoreBalanceTopUpLifecycleResult::payableResult($this->payable($topUp));
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
            ], true)) {
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
        } catch (Throwable $exception) {
            $this->markCreateFailed($topUp, $exception);
            report($exception);

            return StoreBalanceTopUpLifecycleResult::state(
                $exception instanceof SakurupiahCreatePreRequestException
                    ? StoreBalanceTopUpOutcome::ProviderUnavailable
                    : StoreBalanceTopUpOutcome::RecoveryPending,
            );
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
            StoreBalanceTopUpStatus::Expired,
            StoreBalanceTopUpStatus::InvalidProviderResult,
            StoreBalanceTopUpStatus::Compensated,
        ], true)) {
            return new StoreBalanceTopUpReconciliation($this->classify($topUp));
        }

        if (blank($topUp->provider_reference)) {
            return new StoreBalanceTopUpReconciliation($this->classify($topUp));
        }

        try {
            $status = $this->provider->transactionStatus((string) $topUp->provider_reference);
        } catch (SakurupiahStatusUnavailableException $exception) {
            report($exception);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        } catch (Throwable $exception) {
            report($exception);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::RecoveryPending);
        }

        if (! $this->statusMatchesTopUp($topUp, $status)) {
            $this->reviews->mark($topUp, 'provider_identity_mismatch', self::STATE_OUTCOME_UNKNOWN);

            return StoreBalanceTopUpReconciliation::state(StoreBalanceTopUpOutcome::InvalidProviderResult);
        }

        $event = $this->events->record(
            $topUp,
            PaymentProvider::SAKURUPIAH,
            'reconciliation',
            $status->statusValue(),
            [
                ...$status->sanitizedPayload(),
                'merchant_ref_hash' => hash('sha256', (string) $topUp->merchant_ref),
            ],
            $status->transactionId(),
        );

        if ($status->isExpired()) {
            $this->markExpired($topUp);

            return new StoreBalanceTopUpReconciliation(
                StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Expired),
            );
        }

        if ($status->isSuccessful()) {
            return new StoreBalanceTopUpReconciliation(
                $this->classify($topUp->fresh() ?? $topUp),
                $this->evidence($topUp, $status, trusted: true),
                (string) $event->getKey(),
            );
        }

        if ($status->isPending()
            && ! $this->classify($topUp)->isPayable()
            && $status->qrPayload() !== null
            && $status->totalValue() !== null) {
            $this->persistInvoice($topUp, new SakurupiahInvoice(
                trxId: $status->transactionId(),
                merchantRef: (string) $topUp->merchant_ref,
                paymentCode: $status->paymentCode ?? SakurupiahChannelPolicy::forTopUp($topUp),
                status: $status->status,
                total: $status->totalValue(),
                amount: (int) $topUp->amount,
                fee: $status->fee,
                createdAt: $status->occurredAt,
                expiresAt: $status->expiresAt,
                qr: $status->qrPayload(),
                sanitizedPayload: $status->sanitizedPayload(),
                merchantFee: $status->merchantFee(),
            ));
        }

        return new StoreBalanceTopUpReconciliation($this->classify($topUp->fresh() ?? $topUp));
    }

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment {
        $invoice = $this->storedInvoice($topUp);
        $presentation = SakurupiahPayableInvoice::fromTopUp(
            $topUp,
            $invoice,
            $payable->effectiveExpiresAt(),
            SakurupiahEnvironment::runtime(),
        );

        return new StoreBalanceTopUpInstructionAttachment(
            $presentation->isQrisImage()
                ? $this->qrPresentation->image($this->qrisImage->contents($presentation))
                : $this->qrPresentation->payload((string) $presentation->qrisPayload()),
        );
    }

    private function storedInvoice(StoreBalanceTopUp $topUp): SakurupiahInvoice
    {
        return new SakurupiahInvoice(
            trxId: (string) $topUp->provider_reference,
            merchantRef: (string) $topUp->merchant_ref,
            paymentCode: SakurupiahChannelPolicy::forTopUp($topUp),
            status: SakurupiahTransactionStatus::Pending,
            total: (int) $topUp->provider_total_payment,
            amount: (int) $topUp->amount,
            fee: SakurupiahFee::from($topUp->provider_fee),
            expiresAt: $topUp->provider_expires_at?->toImmutable(),
            qr: $topUp->provider_qris_payload ?: $topUp->provider_qris_url,
        );
    }

    private function createInvoice(StoreBalanceTopUp $topUp): SakurupiahCreateInvoice
    {
        $environment = SakurupiahEnvironment::runtime();
        $customer = $topUp->customer;
        $phone = trim((string) $customer?->phone_number);
        if ($phone === '' && filled($customer?->telegram_id)) {
            $phone = trim((string) ManualPaymentSetting::current()?->whatsappSupportNumber());
        }
        if ($phone === '') {
            throw new SakurupiahCreatePreRequestException('Sakurupiah customer phone is required before invoice creation.');
        }

        return new SakurupiahCreateInvoice(
            method: SakurupiahChannelPolicy::forTopUp($topUp),
            phone: $phone,
            amount: (int) $topUp->amount,
            merchantFee: $this->merchantFee(),
            merchantRef: (string) $topUp->merchant_ref,
            callbackUrl: (string) config($environment->configKey('callback_url')),
            returnUrl: (string) config($environment->configKey('return_url')),
            name: $customer?->first_name,
            email: $customer?->email,
            expired: SakurupiahChannelPolicy::expiryHours(SakurupiahChannelPolicy::forTopUp($topUp)),
        );
    }

    private function merchantFee(): int
    {
        $merchantFee = (int) config('services.sakurupiah.qris_contract.merchant_fee', 1);

        return in_array($merchantFee, [1, 2], true) ? $merchantFee : 1;
    }

    private function payable(StoreBalanceTopUp $topUp): StoreBalanceTopUpPayable
    {
        $expiresAt = $topUp->provider_expires_at ?? $topUp->expires_at;
        if ($expiresAt === null) {
            throw new RuntimeException('Sakurupiah Store Balance top-up has no deadline.');
        }

        return new StoreBalanceTopUpPayable(
            creditAmount: (int) $topUp->amount,
            providerFee: $topUp->provider_fee === null ? 0 : (int) $topUp->provider_fee,
            totalAmount: $topUp->provider_total_payment ?? (int) $topUp->amount,
            expiresAt: CarbonImmutable::instance($expiresAt),
        );
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
                || filled($locked->provider_reference)) {
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

    private function persistInvoice(StoreBalanceTopUp $topUp, SakurupiahInvoice $invoice): bool
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
                ], true)) {
                return false;
            }

            if ($invoice->merchantRef !== (string) $locked->merchant_ref
                || $invoice->paymentCode !== SakurupiahChannelPolicy::forTopUp($locked)
                || (filled($locked->provider_reference) && $locked->provider_reference !== $invoice->transactionId())) {
                $this->reviews->mark($locked, 'provider_identity_mismatch', self::STATE_QRIS_UNUSABLE);

                return true;
            }

            $total = $invoice->totalValue();
            $providerExpiresAt = $invoice->expiresAt;
            $rowExpiresAt = $locked->expires_at;
            if ($providerExpiresAt !== null
                && ($rowExpiresAt === null || $providerExpiresAt->lt($rowExpiresAt))) {
                $rowExpiresAt = CarbonImmutable::instance($providerExpiresAt);
            }
            $presentation = null;
            if ($invoice->isPending() && $rowExpiresAt !== null) {
                try {
                    $presentation = SakurupiahPayableInvoice::fromTopUp(
                        $locked,
                        $invoice,
                        $rowExpiresAt,
                        SakurupiahEnvironment::runtime(),
                    );
                    if (! $presentation->isDirectQris() && ! $presentation->isQrisImage()) {
                        $presentation = null;
                    }
                } catch (SakurupiahInvoiceIntegrityException) {
                    $presentation = null;
                }
            }
            $isPayable = $presentation !== null;

            $locked->forceFill([
                'provider_reference' => $invoice->transactionId(),
                'provider_payload' => $invoice->sanitizedPayload(),
                'provider_qris_payload' => $presentation?->qrisPayload(),
                'provider_qris_url' => $presentation?->qrisImageUrl(),
                'provider_fee' => max(0, $total - (int) $locked->amount),
                'provider_total_payment' => $total,
                'provider_payment_method' => 'QRIS',
                'provider_created_at' => now(),
                'provider_expires_at' => $providerExpiresAt,
                'expires_at' => $rowExpiresAt ?? $locked->expires_at,
                'status' => $isPayable
                    ? StoreBalanceTopUpStatus::Payable
                    : ($invoice->isExpired() ? StoreBalanceTopUpStatus::Expired : StoreBalanceTopUpStatus::Pending),
                'provider_create_state' => $isPayable
                    ? self::STATE_CREATED
                    : ($invoice->isExpired() ? self::STATE_EXPIRED : self::STATE_QRIS_UNUSABLE),
                'provider_create_last_error' => $isPayable ? null : self::STATE_QRIS_UNUSABLE,
            ])->save();

            return true;
        }, 5);
    }

    private function markCreateFailed(StoreBalanceTopUp $topUp, Throwable $exception): void
    {
        DB::transaction(function () use ($topUp, $exception): void {
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
                ], true)) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Failed,
                'provider_create_state' => $exception instanceof SakurupiahCreatePreRequestException
                    ? 'provider_unavailable'
                    : self::STATE_OUTCOME_UNKNOWN,
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
            if ($locked === null || $locked->status === StoreBalanceTopUpStatus::Paid) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Expired,
                'provider_create_state' => self::STATE_EXPIRED,
                'provider_create_last_error' => null,
            ])->save();
        }, 5);
    }

    private function statusMatchesTopUp(StoreBalanceTopUp $topUp, SakurupiahStatus $status): bool
    {
        if ($status->transactionId() !== (string) $topUp->provider_reference) {
            return false;
        }

        if (trim((string) $status->merchantRef) !== trim((string) $topUp->merchant_ref)) {
            return false;
        }

        if ($status->paymentCode !== null
            && $status->paymentCode !== SakurupiahChannelPolicy::forTopUp($topUp)) {
            return false;
        }
        if ($status->currency !== null && $status->currency !== 'IDR') {
            return false;
        }

        if ($status->merchantFee() !== null && $status->merchantFee() !== $this->merchantFee()) {
            return false;
        }
        if ($status->amountValue() !== null
            && ! in_array($status->amountValue(), [(int) $topUp->amount, (int) $topUp->provider_total_payment], true)) {
            return false;
        }
        if ($status->totalValue() !== null && $topUp->provider_total_payment !== null
            && $status->totalValue() !== (int) $topUp->provider_total_payment) {
            return false;
        }

        return $status->totalValue() === null || $status->totalValue() >= (int) $topUp->amount;
    }

    private function evidence(
        StoreBalanceTopUp $topUp,
        SakurupiahStatus $status,
        bool $trusted,
    ): StoreBalanceTopUpSettlementEvidence {
        $paidAt = $status->occurredAt ?? null;
        $baseAmount = (int) $topUp->amount;
        $recordedTotal = (int) ($topUp->provider_total_payment ?? $baseAmount);

        // The provider reports the base amount in one observation and the final
        // total in another when the buyer bears the QRIS fee. A reported amount
        // that is neither stays as observed so the matcher still rejects it.
        $reportedAmount = $status->amountValue();

        return new StoreBalanceTopUpSettlementEvidence(
            provider: PaymentProvider::SAKURUPIAH,
            merchantReference: (string) $topUp->merchant_ref,
            providerReference: $status->transactionId(),
            providerStatus: (string) ($status->statusValue() ?? ''),
            amount: $reportedAmount === null || $reportedAmount === $baseAmount || $reportedAmount === $recordedTotal
                ? $baseAmount
                : $reportedAmount,
            totalAmount: $status->totalValue() ?? $recordedTotal,
            trusted: $trusted,
            providerFee: $status->feeValue(),
            paidAt: $paidAt === null ? null : CarbonImmutable::instance($paidAt),
            expiresAt: $status->expiresAt === null ? null : CarbonImmutable::instance($status->expiresAt),
        );
    }

    private function deadlinePassed(StoreBalanceTopUp $topUp): bool
    {
        return ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture())
            || ($topUp->provider_expires_at !== null && ! $topUp->provider_expires_at->isFuture());
    }
}
