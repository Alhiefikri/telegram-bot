<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Events\StoreBalanceTopUpSettled;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpInstructionAttachment;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpLifecycleResolver;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpLifecycleResult;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPayable;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpPresentation;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpProviderResolver;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpReconciliation;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpReviewMarker;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpSettlementEvidence;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StoreBalanceTopUpService
{
    private const ACTION_PATTERN = '/\Abalance:topup:([1-9][0-9]{0,18}):(check|retry)\z/D';

    public function __construct(
        private readonly StoreBalanceLedger $ledger,
        private readonly StoreBalanceTopUpEventLogger $events,
        private readonly StoreBalanceTopUpLifecycleResolver $lifecycles,
        private readonly StoreBalanceTopUpProviderResolver $providers,
        private readonly StoreBalanceTopUpReviewMarker $reviews,
        private readonly ?AuditLogger $audit = null,
    ) {}

    public static function actionCallback(string $action, int|string $topUpId): string
    {
        $action = strtolower(trim($action));
        $topUpId = (string) $topUpId;
        if (! in_array($action, ['check', 'retry'], true)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $topUpId) !== 1) {
            throw new RuntimeException('Store Balance top-up action is invalid.');
        }

        return 'balance:topup:'.$topUpId.':'.$action;
    }

    public static function isActionCallbackFormat(string $data): bool
    {
        return strlen($data) <= 64 && preg_match(self::ACTION_PATTERN, $data) === 1;
    }

    /** @return array{action: string, topUpId: string}|null */
    public static function actionFromCallback(string $data): ?array
    {
        if (preg_match(self::ACTION_PATTERN, $data, $matches) !== 1) {
            return null;
        }

        return ['action' => $matches[2], 'topUpId' => $matches[1]];
    }

    public function createOrReuseConfirmedIntent(StoreBalanceFundingIntent $intent): StoreBalanceTopUp
    {
        $selection = $this->providers->resolve();

        return DB::transaction(function () use ($intent, $selection): StoreBalanceTopUp {
            $customer = Customer::query()
                ->whereKey($intent->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedIntent = StoreBalanceFundingIntent::query()
                ->whereKey($intent->getKey())
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedIntent->status !== StoreBalanceFundingIntentStatus::Confirmed) {
                throw new RuntimeException('Store Balance top-up requires a confirmed funding intent.');
            }

            $root = TelegramNavigationRoot::query()
                ->whereKey($lockedIntent->telegram_navigation_root_id)
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->first();
            if ($root === null
                || $root->chat_id !== $lockedIntent->chat_id
                || (int) $root->message_id !== (int) $lockedIntent->canonical_message_id) {
                throw new RuntimeException('Store Balance top-up requires the current navigation root.');
            }

            $existing = StoreBalanceTopUp::query()
                ->where('store_balance_funding_intent_id', $lockedIntent->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing->fresh(['customer', 'fundingIntent', 'navigationRoot']);
            }

            try {
                return StoreBalanceTopUp::query()->create([
                    'customer_id' => $customer->getKey(),
                    'store_balance_funding_intent_id' => $lockedIntent->getKey(),
                    'telegram_navigation_root_id' => $lockedIntent->telegram_navigation_root_id,
                    'chat_id' => $lockedIntent->chat_id,
                    'canonical_message_id' => $lockedIntent->canonical_message_id,
                    'locale' => $customer->locale,
                    'provider' => $selection->provider(),
                    'payment_setting_id' => $selection->settingId(),
                    'payment_provider_version' => $selection->providerVersion(),
                    'provider_payment_method' => $this->paymentMethodFor($selection->provider()),
                    'merchant_ref' => self::merchantReference($lockedIntent),
                    'idempotency_key' => self::idempotencyKey($lockedIntent),
                    'currency' => 'IDR',
                    'amount' => $lockedIntent->amount,
                    'status' => StoreBalanceTopUpStatus::Pending,
                    'expires_at' => $this->expiresAtFor($lockedIntent, $selection->provider()),
                ])->fresh(['customer', 'fundingIntent', 'navigationRoot']);
            } catch (QueryException $exception) {
                if (! $this->isIntentUniqueConflict($exception)) {
                    throw $exception;
                }

                return StoreBalanceTopUp::query()
                    ->where('store_balance_funding_intent_id', $lockedIntent->getKey())
                    ->firstOrFail()
                    ->fresh(['customer', 'fundingIntent', 'navigationRoot']);
            }
        }, 5);
    }

    public function forAction(Customer $customer, string $chatId, string $topUpId): ?StoreBalanceTopUp
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $topUpId) !== 1) {
            return null;
        }

        return StoreBalanceTopUp::query()
            ->with(['customer', 'navigationRoot'])
            ->whereKey($topUpId)
            ->where('customer_id', $customer->getKey())
            ->where('chat_id', trim($chatId))
            ->first();
    }

    public function classify(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $rail = $this->lifecycles->resolve((string) $topUp->provider);

        return $rail === null
            ? StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable)
            : $rail->classify($topUp);
    }

    public function presentation(StoreBalanceTopUp $topUp, bool $refresh = true): StoreBalanceTopUpPresentation
    {
        $rail = $this->lifecycles->resolve((string) $topUp->provider);

        return $rail === null
            ? StoreBalanceTopUpPresentation::fromLifecycle(
                $this->classify($topUp),
                paid: $topUp->status === StoreBalanceTopUpStatus::Paid,
                retryable: $topUp->canRetryCreate(),
            )
            : $rail->presentation($topUp, $refresh);
    }

    public function lifecycle(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $rail = $this->lifecycles->resolve((string) $topUp->provider);
        if ($rail === null) {
            return $this->markProviderUnavailable($topUp);
        }

        $result = $rail->lifecycle($topUp);
        if ($result->outcome() === StoreBalanceTopUpOutcome::ProviderUnavailable) {
            return $this->markProviderUnavailable($topUp);
        }

        return $result;
    }

    public function instructionAttachment(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpPayable $payable,
    ): StoreBalanceTopUpInstructionAttachment {
        $rail = $this->lifecycles->resolve((string) $topUp->provider);
        if ($rail === null) {
            throw new RuntimeException('Store Balance top-up has no payable payment rail.');
        }

        return $rail->instructionAttachment($topUp, $payable);
    }

    public function reconcile(StoreBalanceTopUp $topUp): StoreBalanceTopUpLifecycleResult
    {
        $rail = $this->lifecycles->resolve((string) $topUp->provider);
        if ($rail === null) {
            return $this->markProviderUnavailable($topUp);
        }

        $reconciliation = $rail->reconcile($topUp);
        if ($reconciliation->result()->outcome() === StoreBalanceTopUpOutcome::ProviderUnavailable) {
            return $this->markProviderUnavailable($topUp);
        }

        $evidence = $reconciliation->evidence();
        if ($evidence !== null && $evidence->isTrusted()) {
            $event = $this->settleVerifiedEvidence(
                $topUp,
                $evidence,
                fn (StoreBalanceTopUp $locked): StoreBalanceTopUpEvent => $this->reconciliationEvent($locked, $reconciliation),
            );

            if ($event->processed_at !== null) {
                $this->markReconciledPaid($topUp);
            } elseif ($evidence->isSuccess()) {
                $current = $topUp->fresh();
                if ($current !== null && ! $this->isTerminal($current)) {
                    $due = ($current->expires_at !== null && ! $current->expires_at->isFuture())
                        || ($current->provider_expires_at !== null && ! $current->provider_expires_at->isFuture());
                    if ($due) {
                        $this->expireIfDue($current);
                    } else {
                        $this->reviews->mark($current, $this->reconciliationReason($current, $evidence));
                    }
                }
            }
        }

        $current = $topUp->fresh();

        return $current === null ? $reconciliation->result() : $rail->classify($current);
    }

    public function expireIfDue(StoreBalanceTopUp $topUp): bool
    {
        return DB::transaction(function () use ($topUp): bool {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $this->isTerminal($locked)) {
                return false;
            }

            $now = now();
            $due = ($locked->expires_at !== null && $locked->expires_at->lte($now))
                || ($locked->provider_expires_at !== null && $locked->provider_expires_at->lte($now));
            if (! $due) {
                return false;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Expired,
                'provider_create_state' => 'provider_expired',
                'provider_create_last_error' => null,
            ])->save();

            return true;
        });
    }

    private function markReconciledPaid(StoreBalanceTopUp $topUp): void
    {
        DB::transaction(function () use ($topUp): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== StoreBalanceTopUpStatus::Paid) {
                return;
            }

            $state = trim((string) $locked->provider_create_state);
            if (! str_starts_with($state, 'provider_')) {
                return;
            }

            $locked->forceFill(['provider_create_state' => 'reconciled_'.substr($state, 9)])->save();
        });
    }

    private function reconciliationEvent(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpReconciliation $reconciliation,
    ): StoreBalanceTopUpEvent {
        $eventId = $reconciliation->eventId();
        if ($eventId === null) {
            throw new RuntimeException('Store Balance top-up reconciliation evidence has no event.');
        }

        return StoreBalanceTopUpEvent::query()
            ->whereKey($eventId)
            ->where('store_balance_top_up_id', $topUp->getKey())
            ->firstOrFail();
    }

    /**
     * @param  Closure(StoreBalanceTopUp): StoreBalanceTopUpEvent  $eventFactory
     */
    public function settleVerifiedEvidence(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpSettlementEvidence $evidence,
        Closure $eventFactory,
    ): StoreBalanceTopUpEvent {
        return DB::transaction(function () use ($topUp, $evidence, $eventFactory): StoreBalanceTopUpEvent {
            $customer = Customer::query()
                ->whereKey($topUp->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ledger->lockForTransaction($customer);

            $lockedTopUp = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $event = $eventFactory($lockedTopUp);
            if ((int) $event->store_balance_top_up_id !== (int) $lockedTopUp->getKey()) {
                throw new RuntimeException('Store Balance top-up event ownership mismatch.');
            }

            $lockedEvent = StoreBalanceTopUpEvent::query()
                ->whereKey($event->getKey())
                ->where('store_balance_top_up_id', $lockedTopUp->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEvent->processed_at !== null) {
                if ($lockedTopUp->status === StoreBalanceTopUpStatus::Paid) {
                    StoreBalanceTopUpSettled::dispatch((string) $lockedTopUp->getKey());
                }

                return $lockedEvent->fresh();
            }

            if (! $evidence->isTrusted() || ! $this->evidenceMatchesTopUp($lockedTopUp, $evidence)) {
                return $lockedEvent->fresh();
            }

            if ($lockedTopUp->status === StoreBalanceTopUpStatus::Paid) {
                if (! $this->matchesSettledEvidence($lockedTopUp, $evidence)) {
                    return $lockedEvent->fresh();
                }

                $lockedEvent->forceFill(['processed_at' => now()])->save();
                StoreBalanceTopUpSettled::dispatch((string) $lockedTopUp->getKey());

                return $lockedEvent->fresh();
            }
            if ($lockedTopUp->status === StoreBalanceTopUpStatus::Compensated) {
                if (! $this->matchesSettledEvidence($lockedTopUp, $evidence)) {
                    return $lockedEvent->fresh();
                }

                $lockedEvent->forceFill(['processed_at' => now()])->save();
                ($this->audit ?? app(AuditLogger::class))->logWithResolvedContext(
                    'store_balance.top_up.compensation_provider_evidence',
                    $lockedTopUp,
                    old: ['status' => StoreBalanceTopUpStatus::Compensated->value],
                    new: [
                        'status' => StoreBalanceTopUpStatus::Compensated->value,
                        'provider_event_id' => (int) $lockedEvent->getKey(),
                        'provider_reference_hash' => $this->referenceHash($evidence->providerReference()),
                        'late_evidence' => true,
                    ],
                );

                return $lockedEvent->fresh();
            }

            if (! $this->isEligibleForSettlement($lockedTopUp, $evidence)) {
                return $lockedEvent->fresh();
            }

            $mutation = $this->ledger->creditTopUp(
                $customer,
                (int) $lockedTopUp->amount,
                (string) $lockedTopUp->getKey(),
                self::creditIdempotencyKey((string) $lockedTopUp->getKey()),
            );

            $previousStatus = $lockedTopUp->status->value;
            $lockedTopUp->forceFill([
                'status' => StoreBalanceTopUpStatus::Paid,
                'provider_create_state' => 'provider_'.$evidence->rawStatus(),
                'paid_at' => $evidence->paidAt() ?? now(),
                'settled_at' => now(),
            ])->save();
            $lockedEvent->forceFill(['processed_at' => now()])->save();

            if ($mutation->applied) {
                ($this->audit ?? app(AuditLogger::class))->logWithResolvedContext(
                    'store_balance.top_up.settled',
                    $lockedTopUp,
                    old: ['status' => $previousStatus],
                    new: [
                        'status' => StoreBalanceTopUpStatus::Paid->value,
                        'amount' => (int) $lockedTopUp->amount,
                        'provider_total_payment' => (int) $lockedTopUp->provider_total_payment,
                        'provider_reference_hash' => $this->referenceHash($lockedTopUp->provider_reference),
                        'provider_event_id' => (int) $lockedEvent->getKey(),
                        'credit_entry_id' => $mutation->entryId,
                        'balance_after' => $mutation->balanceAfter,
                    ],
                );
            }

            StoreBalanceTopUpSettled::dispatch((string) $lockedTopUp->getKey());

            return $lockedEvent->fresh();
        }, attempts: 5);
    }

    /**
     * @param  Closure(StoreBalanceTopUp): StoreBalanceTopUpEvent  $eventFactory
     */
    public function settleAuthoritativeTransaction(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
        Closure $eventFactory,
    ): StoreBalanceTopUpEvent {
        return $this->settleVerifiedEvidence(
            $topUp,
            $this->klikQrisEvidence($topUp, $transaction),
            $eventFactory,
        );
    }

    public function applyAuthoritativeTransaction(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
        StoreBalanceTopUpEvent $event,
    ): StoreBalanceTopUpEvent {
        return $this->settleVerifiedEvidence(
            $topUp,
            $this->klikQrisEvidence($topUp, $transaction),
            fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => StoreBalanceTopUpEvent::query()
                ->whereKey($event->getKey())
                ->where('store_balance_top_up_id', $lockedTopUp->getKey())
                ->lockForUpdate()
                ->firstOrFail(),
        );
    }

    public function recordSettledCallback(
        StoreBalanceTopUp $topUp,
        KlikQrisCallback $callback,
    ): StoreBalanceTopUpEvent {
        return DB::transaction(function () use ($topUp, $callback): StoreBalanceTopUpEvent {
            $lockedTopUp = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->where('customer_id', $topUp->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $event = $this->events->logCallback($callback, $lockedTopUp);
            if ((int) $event->store_balance_top_up_id !== (int) $lockedTopUp->getKey()) {
                throw new RuntimeException('Store Balance top-up event ownership mismatch.');
            }

            $lockedEvent = StoreBalanceTopUpEvent::query()
                ->whereKey($event->getKey())
                ->where('store_balance_top_up_id', $lockedTopUp->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedEvent->processed_at !== null) {
                return $lockedEvent->fresh();
            }

            if ($this->matchesSettledCallback($lockedTopUp, $callback)) {
                $lockedEvent->forceFill(['processed_at' => now()])->save();
                StoreBalanceTopUpSettled::dispatch((string) $lockedTopUp->getKey());
            }

            return $lockedEvent->fresh();
        }, attempts: 5);
    }

    public function recordCompensatedCallback(
        StoreBalanceTopUp $topUp,
        KlikQrisCallback $callback,
    ): StoreBalanceTopUpEvent {
        return DB::transaction(function () use ($topUp, $callback): StoreBalanceTopUpEvent {
            $lockedTopUp = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->where('customer_id', $topUp->customer_id)
                ->lockForUpdate()
                ->firstOrFail();
            $event = $this->events->logCallback($callback, $lockedTopUp);
            if ((int) $event->store_balance_top_up_id !== (int) $lockedTopUp->getKey()) {
                throw new RuntimeException('Store Balance top-up event ownership mismatch.');
            }

            $lockedEvent = StoreBalanceTopUpEvent::query()
                ->whereKey($event->getKey())
                ->where('store_balance_top_up_id', $lockedTopUp->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedEvent->processed_at !== null) {
                return $lockedEvent->fresh();
            }

            if ($lockedTopUp->status === StoreBalanceTopUpStatus::Compensated
                && $this->matchesCompensatedCallback($lockedTopUp, $callback)) {
                $lockedEvent->forceFill(['processed_at' => now()])->save();
                ($this->audit ?? app(AuditLogger::class))->logWithResolvedContext(
                    'store_balance.top_up.compensation_provider_evidence',
                    $lockedTopUp,
                    old: ['status' => StoreBalanceTopUpStatus::Compensated->value],
                    new: [
                        'status' => StoreBalanceTopUpStatus::Compensated->value,
                        'provider_event_id' => (int) $lockedEvent->getKey(),
                        'provider_reference_hash' => $this->referenceHash($callback->providerReference()),
                        'late_evidence' => true,
                    ],
                );
            }

            return $lockedEvent->fresh();
        }, attempts: 5);
    }

    public static function merchantReference(StoreBalanceFundingIntent $intent): string
    {
        return 'BAL-'.$intent->getKey().'-'.substr(hash('sha256', (string) $intent->nonce), 0, 16);
    }

    public static function idempotencyKey(StoreBalanceFundingIntent $intent): string
    {
        return 'balance-top-up:intent:'.$intent->getKey();
    }

    public static function creditIdempotencyKey(string $topUpId): string
    {
        return 'balance_top_up:'.$topUpId.':credit';
    }

    private function markProviderUnavailable(
        StoreBalanceTopUp $topUp,
        string $reason = 'provider_unavailable',
    ): StoreBalanceTopUpLifecycleResult {
        DB::transaction(function () use ($topUp, $reason): void {
            $locked = StoreBalanceTopUp::query()
                ->whereKey($topUp->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $this->isTerminal($locked)) {
                return;
            }

            $locked->forceFill([
                'status' => StoreBalanceTopUpStatus::Failed,
                'provider_create_state' => 'provider_unavailable',
                'provider_create_last_error' => $reason,
            ])->save();
        }, attempts: 5);

        $topUp->refresh();

        return StoreBalanceTopUpLifecycleResult::state(StoreBalanceTopUpOutcome::ProviderUnavailable);
    }

    private function evidenceMatchesTopUp(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpSettlementEvidence $evidence,
    ): bool {
        if (strtolower(trim((string) $topUp->provider)) !== $evidence->provider()) {
            return false;
        }

        if ($evidence->merchantReference() !== null
            && trim((string) $topUp->merchant_ref) !== ''
            && $evidence->merchantReference() !== trim((string) $topUp->merchant_ref)) {
            return false;
        }

        return $evidence->amount() === (int) $topUp->amount;
    }

    private function isEligibleForSettlement(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpSettlementEvidence $evidence,
    ): bool {
        return in_array($topUp->status, [
            StoreBalanceTopUpStatus::Pending,
            StoreBalanceTopUpStatus::Payable,
            StoreBalanceTopUpStatus::AwaitingConfirmation,
        ], true)
            && strtoupper(trim((string) $topUp->currency)) === 'IDR'
            && in_array(strtoupper(trim((string) $topUp->provider_payment_method)), ['QRIS', 'QR_MANUAL'], true)
            && $topUp->expires_at?->isFuture() === true
            && $topUp->provider_expires_at?->isFuture() === true
            && $topUp->provider_fee !== null
            && $topUp->provider_total_payment !== null
            && (int) $topUp->provider_total_payment >= (int) $topUp->amount
            && $evidence->isSuccess()
            && $evidence->amount() === (int) $topUp->amount
            && $evidence->totalAmount() === (int) $topUp->provider_total_payment;
    }

    private function matchesSettledEvidence(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpSettlementEvidence $evidence,
    ): bool {
        $providerReference = $evidence->providerReference();
        $totalMatches = $topUp->status === StoreBalanceTopUpStatus::Compensated
            ? $evidence->totalAmount() >= (int) $topUp->amount
            : $topUp->provider_total_payment !== null
                && $evidence->totalAmount() === (int) $topUp->provider_total_payment;

        return $evidence->isSuccess()
            && $evidence->amount() === (int) $topUp->amount
            && $totalMatches
            && ($providerReference === null
                || $topUp->provider_reference === null
                || $providerReference === (string) $topUp->provider_reference);
    }

    private function matchesSettledCallback(
        StoreBalanceTopUp $topUp,
        KlikQrisCallback $callback,
    ): bool {
        $providerReference = $callback->providerReference();

        return $topUp->status === StoreBalanceTopUpStatus::Paid
            && $topUp->settled_at !== null
            && $callback->isPaidTrigger()
            && strtolower(trim((string) $topUp->provider)) === PaymentProvider::KLIKQRIS
            && trim((string) $topUp->merchant_ref) === $callback->orderId()
            && (int) $topUp->amount === $callback->amount()->rupiah()
            && $topUp->provider_total_payment !== null
            && (int) $topUp->provider_total_payment === $callback->totalAmount()
            && ($providerReference === null
                || $topUp->provider_reference === null
                || $providerReference === (string) $topUp->provider_reference);
    }

    private function matchesCompensatedCallback(
        StoreBalanceTopUp $topUp,
        KlikQrisCallback $callback,
    ): bool {
        $providerReference = $callback->providerReference();

        return $topUp->status === StoreBalanceTopUpStatus::Compensated
            && $callback->isPaidTrigger()
            && strtolower(trim((string) $topUp->provider)) === PaymentProvider::KLIKQRIS
            && trim((string) $topUp->merchant_ref) === $callback->orderId()
            && (int) $topUp->amount === $callback->amount()->rupiah()
            && $callback->totalAmount() >= (int) $topUp->amount
            && ($providerReference === null
                || $topUp->provider_reference === null
                || $providerReference === (string) $topUp->provider_reference);
    }

    private function klikQrisEvidence(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
    ): StoreBalanceTopUpSettlementEvidence {
        $stored = $topUp->providerSignature();
        $received = $transaction->signature();

        return new StoreBalanceTopUpSettlementEvidence(
            provider: PaymentProvider::KLIKQRIS,
            merchantReference: $transaction->orderId(),
            providerReference: $transaction->providerReference(),
            providerStatus: $transaction->status(),
            amount: $transaction->amount()->rupiah(),
            totalAmount: $transaction->totalAmount(),
            trusted: $stored !== null && $received !== null && hash_equals($stored, $received),
            providerFee: $transaction->providerFee(),
            paidAt: $transaction->paidAt() === null ? null : CarbonImmutable::instance($transaction->paidAt()),
            expiresAt: $transaction->expiresAt(),
        );
    }

    private function reconciliationReason(
        StoreBalanceTopUp $topUp,
        StoreBalanceTopUpSettlementEvidence $evidence,
    ): string {
        if ($topUp->provider_total_payment === null
            || $evidence->totalAmount() !== (int) $topUp->provider_total_payment) {
            return 'quote_conflict';
        }

        if ($evidence->providerFee() !== null
            && $topUp->provider_fee !== null
            && $evidence->providerFee() !== (int) $topUp->provider_fee) {
            return 'quote_conflict';
        }

        if ($evidence->providerReference() !== null
            && $topUp->provider_reference !== null
            && $evidence->providerReference() !== trim((string) $topUp->provider_reference)) {
            return 'quote_conflict';
        }

        if ($evidence->expiresAt() !== null
            && $topUp->provider_expires_at !== null
            && $evidence->expiresAt()->getTimestamp() !== $topUp->provider_expires_at->getTimestamp()) {
            return 'quote_conflict';
        }

        return 'paid_evidence_not_settleable';
    }

    private function isTerminal(StoreBalanceTopUp $topUp): bool
    {
        return in_array($topUp->status, [
            StoreBalanceTopUpStatus::Paid,
            StoreBalanceTopUpStatus::Expired,
            StoreBalanceTopUpStatus::InvalidProviderResult,
            StoreBalanceTopUpStatus::Compensated,
        ], true);
    }

    private function paymentMethodFor(string $provider): string
    {
        return match ($provider) {
            PaymentProvider::MANUAL => 'QR_MANUAL',
            PaymentProvider::SAKURUPIAH => 'QRIS',
            default => 'qris',
        };
    }

    private function expiresAtFor(StoreBalanceFundingIntent $intent, string $provider): mixed
    {
        if ($provider !== PaymentProvider::MANUAL) {
            return $intent->expires_at;
        }

        $ttlMinutes = ManualPaymentSetting::current()?->qrisPaymentTtlMinutes()
            ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES;
        $deadline = now()->addMinutes($ttlMinutes);

        return $intent->expires_at !== null && $intent->expires_at->lt($deadline)
            ? $intent->expires_at
            : $deadline;
    }

    private function referenceHash(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : hash('sha256', $reference);
    }

    private function isIntentUniqueConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $constraint = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($constraint, 'store_balance_top_ups_store_balance_funding_intent_id_unique');
    }
}
