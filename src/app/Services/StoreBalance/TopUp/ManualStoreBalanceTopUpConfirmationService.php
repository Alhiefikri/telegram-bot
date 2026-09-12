<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\ManualStoreBalanceTopUpDecision;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Services\AuditLogger;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner/admin confirmation of a manual Store Balance top-up.
 *
 * Approval credits the requested amount exactly once through the shared
 * top-up settlement path; rejection never moves money. Both decisions are
 * idempotent and audited.
 */
final class ManualStoreBalanceTopUpConfirmationService
{
    public function __construct(
        private readonly StoreBalanceTopUpService $topUps,
        private readonly StoreBalanceTopUpDecisionRecorder $events,
        private readonly ?AuditLogger $audit = null,
    ) {}

    public function approve(
        StoreBalanceTopUp $topUp,
        Customer $operator,
        string $operatorChatId,
    ): ManualStoreBalanceTopUpDecision {
        $topUp->refresh();

        if ($topUp->status === StoreBalanceTopUpStatus::Paid) {
            return ManualStoreBalanceTopUpDecision::AlreadyConfirmed;
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Expired) {
            return ManualStoreBalanceTopUpDecision::Expired;
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Failed) {
            return ManualStoreBalanceTopUpDecision::AlreadyRejected;
        }

        if (strtolower(trim((string) $topUp->provider)) !== PaymentProvider::MANUAL
            || $topUp->status !== StoreBalanceTopUpStatus::AwaitingConfirmation) {
            return ManualStoreBalanceTopUpDecision::NotEligible;
        }

        if ($this->deadlinePassed($topUp)) {
            $this->topUps->expireIfDue($topUp);

            return ManualStoreBalanceTopUpDecision::Expired;
        }

        $evidence = new StoreBalanceTopUpSettlementEvidence(
            provider: PaymentProvider::MANUAL,
            merchantReference: (string) $topUp->merchant_ref,
            providerReference: null,
            providerStatus: 'CONFIRMED',
            amount: (int) $topUp->amount,
            totalAmount: $topUp->provider_total_payment === null
                ? (int) $topUp->amount
                : (int) $topUp->provider_total_payment,
            trusted: true,
            providerFee: $topUp->provider_fee === null ? 0 : (int) $topUp->provider_fee,
            paidAt: CarbonImmutable::instance(now()),
        );

        $this->topUps->settleVerifiedEvidence(
            $topUp,
            $evidence,
            fn (StoreBalanceTopUp $locked): StoreBalanceTopUpEvent => $this->events->record(
                $locked,
                PaymentProvider::MANUAL,
                'manual_confirmation',
                'CONFIRMED',
                [
                    'outcome' => 'confirmed',
                    'amount' => (int) $locked->amount,
                    'operator' => $this->operatorReference($operatorChatId),
                ],
            ),
        );

        $settled = $topUp->fresh();
        if ($settled !== null && $settled->status === StoreBalanceTopUpStatus::Paid) {
            $this->markConfirmed($settled);

            ($this->audit ?? app(AuditLogger::class))->logWithResolvedContext(
                'store_balance.top_up.manually_confirmed',
                $settled,
                old: ['status' => StoreBalanceTopUpStatus::AwaitingConfirmation->value],
                new: [
                    'status' => StoreBalanceTopUpStatus::Paid->value,
                    'amount' => (int) $settled->amount,
                    'operator_customer_id' => (int) $operator->getKey(),
                    'operator_reference' => $this->operatorReference($operatorChatId),
                ],
            );

            return ManualStoreBalanceTopUpDecision::Confirmed;
        }

        return ManualStoreBalanceTopUpDecision::NotEligible;
    }

    public function reject(
        StoreBalanceTopUp $topUp,
        Customer $operator,
        string $operatorChatId,
    ): ManualStoreBalanceTopUpDecision {
        $topUp->refresh();

        if ($topUp->status === StoreBalanceTopUpStatus::Paid) {
            return ManualStoreBalanceTopUpDecision::AlreadyConfirmed;
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Failed) {
            return ManualStoreBalanceTopUpDecision::AlreadyRejected;
        }

        if (strtolower(trim((string) $topUp->provider)) !== PaymentProvider::MANUAL
            || $topUp->status !== StoreBalanceTopUpStatus::AwaitingConfirmation) {
            return ManualStoreBalanceTopUpDecision::NotEligible;
        }

        $this->events->record(
            $topUp,
            PaymentProvider::MANUAL,
            'manual_rejection',
            'REJECTED',
            [
                'outcome' => 'rejected',
                'amount' => (int) $topUp->amount,
                'operator' => $this->operatorReference($operatorChatId),
            ],
        );

        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== StoreBalanceTopUpStatus::AwaitingConfirmation) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Failed,
                'provider_create_state' => 'manual_rejected',
                'provider_create_last_error' => 'manual_rejected',
            ])->save();
        }, attempts: 5);

        $this->events->record(
            $topUp->fresh() ?? $topUp,
            PaymentProvider::MANUAL,
            'manual_rejection_state',
            'REJECTED',
            [
                'outcome' => 'rejected',
                'operator' => $this->operatorReference($operatorChatId),
            ],
        );

        ($this->audit ?? app(AuditLogger::class))->logWithResolvedContext(
            'store_balance.top_up.manually_rejected',
            $topUp,
            old: ['status' => StoreBalanceTopUpStatus::AwaitingConfirmation->value],
            new: [
                'status' => StoreBalanceTopUpStatus::Failed->value,
                'amount' => (int) $topUp->amount,
                'operator_customer_id' => (int) $operator->getKey(),
                'operator_reference' => $this->operatorReference($operatorChatId),
            ],
        );

        return ManualStoreBalanceTopUpDecision::Rejected;
    }

    private function markConfirmed(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null
                || $locked->status !== StoreBalanceTopUpStatus::Paid
                || $locked->provider_create_state !== 'provider_CONFIRMED') {
                return;
            }

            $locked->forceFill(['provider_create_state' => 'manual_confirmed'])->save();
        }, attempts: 5);
    }

    private function deadlinePassed(StoreBalanceTopUp $topUp): bool
    {
        return ($topUp->expires_at !== null && ! $topUp->expires_at->isFuture())
            || ($topUp->provider_expires_at !== null && ! $topUp->provider_expires_at->isFuture());
    }

    private function operatorReference(string $operatorChatId): string
    {
        return substr(hash('sha256', trim($operatorChatId)), 0, 16);
    }
}
