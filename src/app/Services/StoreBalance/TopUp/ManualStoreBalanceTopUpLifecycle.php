<?php

namespace App\Services\StoreBalance\TopUp;

use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual Store Balance top-up rail.
 *
 * The customer pays the store's static QR and an authorized operator confirms
 * the payment from the admin Telegram notification. This rail never talks to a
 * provider: it owns the awaiting-confirmation state and the instruction
 * attachment only, while settlement stays with StoreBalanceTopUpService.
 */
final class ManualStoreBalanceTopUpLifecycle implements StoreBalanceTopUpLifecycle
{
    private const QR_ASSET = 'qr-lhie.jpg';

    public function provider(): string
    {
        return PaymentProvider::MANUAL;
    }

    public function classify(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $topUp->refresh();

        return match ($topUp->status) {
            StoreBalanceTopUpStatus::AwaitingConfirmation => StoreBalanceTopUpLifecycleResult::awaitingConfirmation($this->payable($topUp)),
            StoreBalanceTopUpStatus::Expired => StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::Expired),
            StoreBalanceTopUpStatus::Failed => StoreBalanceTopUpLifecycleResult::state(
                $topUp->canRetryCreate()
                    ? StoreBalanceTopUpOutcome::Creating
                    : StoreBalanceTopUpOutcome::InvalidProviderResult,
            ),
            default => StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::InvalidProviderResult),
        };
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
                && in_array($state, ['manual_confirmed', 'reconciled_manual_confirmed'], true),
            retryable: $topUp->canRetryCreate(),
            manualReview: false,
            checkable: $topUp->status === StoreBalanceTopUpStatus::AwaitingConfirmation,
            rejected: $topUp->status === StoreBalanceTopUpStatus::Failed
                && $state === 'manual_rejected',
        );
    }

    public function lifecycle(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $topUp->refresh();

        if ($topUp->status === StoreBalanceTopUpStatus::AwaitingConfirmation) {
            return StoreBalanceTopUpLifecycleResult::awaitingConfirmation($this->payable($topUp));
        }

        if ($topUp->status === StoreBalanceTopUpStatus::Pending) {
            $this->markAwaitingConfirmation($topUp);
            $topUp->refresh();

            return StoreBalanceTopUpLifecycleResult::awaitingConfirmation($this->payable($topUp));
        }

        if ($topUp->canRetryCreate()) {
            $this->resetUnavailable($topUp);
            $topUp->refresh();

            if ($topUp->status === StoreBalanceTopUpStatus::Pending) {
                $this->markAwaitingConfirmation($topUp);
                $topUp->refresh();

                return StoreBalanceTopUpLifecycleResult::awaitingConfirmation($this->payable($topUp));
            }
        }

        return $this->classify($topUp);
    }

    public function reconcile(StoreBalanceTopUp $topUp): StoreBalanceTopUpReconciliation
    {
        $topUp->refresh();
        if ($topUp->status === StoreBalanceTopUpStatus::AwaitingConfirmation
            && ! $this->deadlinePassed($topUp)) {
            return new StoreBalanceTopUpReconciliation(StoreBalanceTopUpLifecycleResult::awaitingConfirmation($this->payable($topUp)));
        }

        return new StoreBalanceTopUpReconciliation($this->classify($topUp));
    }

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment {
        $path = public_path(self::QR_ASSET);
        if (! is_readable($path)) {
            throw new RuntimeException('Manual QR image is not readable: '.$path);
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Manual QR image is empty: '.$path);
        }

        return new StoreBalanceTopUpInstructionAttachment($contents);
    }

    private function payable(StoreBalanceTopUp $topUp): StoreBalanceTopUpPayable
    {
        $expiresAt = $topUp->expires_at ?? $topUp->provider_expires_at;
        if ($expiresAt === null) {
            throw new RuntimeException('Manual Store Balance top-up has no deadline.');
        }

        return new StoreBalanceTopUpPayable(
            creditAmount: (int) $topUp->amount,
            providerFee: $topUp->provider_fee === null ? 0 : (int) $topUp->provider_fee,
            totalAmount: $topUp->provider_total_payment === null
                ? (int) $topUp->amount
                : (int) $topUp->provider_total_payment,
            expiresAt: CarbonImmutable::instance($expiresAt),
        );
    }

    private function markAwaitingConfirmation(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== StoreBalanceTopUpStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::AwaitingConfirmation,
                'provider_fee' => $locked->provider_fee ?? 0,
                'provider_total_payment' => $locked->provider_total_payment ?? (int) $locked->amount,
                'provider_created_at' => $locked->provider_created_at ?? now(),
                'provider_expires_at' => $locked->provider_expires_at ?? $locked->expires_at,
                'provider_create_state' => 'awaiting_confirmation',
                'provider_create_last_error' => null,
            ])->save();
        }, attempts: 5);
    }

    private function resetUnavailable(StoreBalanceTopUp $topUp): void
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
        }, attempts: 5);
    }

    private function deadlinePassed(StoreBalanceTopUp $topUp): bool
    {
        $expiresAt = $topUp->expires_at ?? $topUp->provider_expires_at;

        return $expiresAt !== null && ! $expiresAt->isFuture();
    }
}
