<?php

namespace App\Services\Telegram;

use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramOutboundEffect;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Owns Store Balance top-up source eligibility and the handoff from a
 * committed top-up to the shared Telegram Outbound Effect ledger.
 *
 * This module materializes through TelegramOutboundEffectService and queues
 * through TelegramOutboundEffectDispatcher. Claim, lease, ambiguity, and
 * finalization remain exclusively in TelegramOutboundEffectLedgerProtocol.
 */
final class StoreBalanceTopUpOutbound
{
    /**
     * Recovery never publishes historical proofs: a lost handoff is repaired
     * only for recently settled top-ups, so enabling the channel feature never
     * floods the public channel with old receipts.
     */
    private const CHANNEL_PROOF_RECOVERY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly TelegramOutboundEffectService $outboundEffects,
        private readonly TelegramOutboundEffectDispatcher $dispatcher,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
    ) {}

    public function handoffInstructions(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpInstructions($source),
            fn (string $topUpId): ?TelegramOutboundEffect => $this->outboundEffects->forBalanceTopUp($topUpId),
            fn (StoreBalanceTopUp $source): bool => $this->eligibleInstructions($source),
        );
    }

    /**
     * Retry a definite instruction delivery failure without creating a new
     * top-up or effect. Ambiguous deliveries deliberately remain in review and
     * are never reset by this customer-facing action.
     */
    public function retryInstructions(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        try {
            $source = $topUp->fresh(['customer']);
            if ($source === null || ! $this->eligibleInstructions($source)) {
                return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::NotEligible);
            }

            $effect = $this->outboundEffects->forBalanceTopUp((string) $source->getKey());
            if ($effect === null) {
                return $this->handoffInstructions($source);
            }

            $reset = $this->ledger->requestManualRetry((string) $effect->getKey());

            return $this->handoffEffect($reset ?? $effect);
        } catch (Throwable $exception) {
            report($exception);

            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::Failed, retryable: true);
        }
    }

    public function handoffReceipt(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpReceipt($source),
            fn (string $topUpId): ?TelegramOutboundEffect => $this->outboundEffects->forBalanceTopUpReceipt($topUpId),
            fn (StoreBalanceTopUp $source): bool => $this->eligibleReceipt($source),
        );
    }

    public function handoffPaymentProof(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpPaymentProof($source),
            fn (string $topUpId): ?TelegramOutboundEffect => TelegramOutboundEffect::query()
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
                ->first(),
            fn (StoreBalanceTopUp $source): bool => $source->status === StoreBalanceTopUpStatus::AwaitingConfirmation
                && $source->hasPaymentProof(),
        );
    }

    public function handoffOwnerAlert(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpOwnerAlert($source),
            fn (string $topUpId): ?TelegramOutboundEffect => $this->outboundEffects->forBalanceTopUpOwnerAlert($topUpId),
            fn (StoreBalanceTopUp $source): bool => $source->isEligibleForOwnerAttention(),
        );
    }

    public function handoffPaidNotification(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpPaidNotification($source),
            fn (string $topUpId): ?TelegramOutboundEffect => TelegramOutboundEffect::query()
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('source_id', $topUpId)
                ->where('effect_type', TelegramOutboundEffectType::BalanceTopUpPaidNotification->value)
                ->first(),
            fn (StoreBalanceTopUp $source): bool => $source->status === StoreBalanceTopUpStatus::Paid
                && $source->settled_at !== null,
        );
    }

    public function handoffChannelProof(StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult
    {
        return $this->handoff(
            $topUp,
            fn (StoreBalanceTopUp $source): ?TelegramOutboundEffect => $this->outboundEffects->recordBalanceTopUpChannelProof($source),
            fn (string $topUpId): ?TelegramOutboundEffect => $this->outboundEffects->forBalanceTopUpChannelProof($topUpId),
            fn (StoreBalanceTopUp $source): bool => $source->status === StoreBalanceTopUpStatus::Paid
                && $source->settled_at !== null,
        );
    }

    /**
     * Match an interactive callback to a sent instruction effect without
     * exposing effect persistence or delivery state to the callback adapter.
     */
    public function hasSentInstructionFor(
        StoreBalanceTopUp $topUp,
        string $chatId,
        int|string $messageId,
    ): bool {
        try {
            $effect = $this->outboundEffects->forBalanceTopUp((string) $topUp->getKey());

            return $this->matchesSentEffect($effect, $chatId, $messageId);
        } catch (Throwable $exception) {
            Log::warning('telegram.store_balance_top_up.navigation_match_failed', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Match a customer callback to a sent Store Balance receipt.
     */
    public function hasSentReceiptFor(
        Customer $customer,
        string $chatId,
        int|string $messageId,
    ): bool {
        $chatId = trim($chatId);
        $messageId = (string) $messageId;
        if ($chatId === '' || preg_match('/\A[1-9][0-9]{0,18}\z/D', $messageId) !== 1) {
            return false;
        }

        try {
            $effect = TelegramOutboundEffect::query()
                ->where('source_type', StoreBalanceTopUp::class)
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
                ->where('telegram_chat_id', $chatId)
                ->where('telegram_message_id', $messageId)
                ->first();
            if ($effect === null || ! $this->matchesSentEffect($effect, $chatId, $messageId)) {
                return false;
            }

            return StoreBalanceTopUp::query()
                ->whereKey($effect->source_id)
                ->where('customer_id', $customer->getKey())
                ->exists();
        } catch (Throwable $exception) {
            Log::warning('telegram.store_balance_top_up.navigation_match_failed', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Discover committed payable top-ups whose instruction handoff is absent.
     * The returned count is the number of queue handoffs attempted.
     */
    public function recoverMissingInstructions(int $chunk = 100): int
    {
        return $this->recoverMissingFromQuery(
            'topup_instructions',
            $this->missingInstructionsQuery(),
            $chunk,
            fn (StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult => $this->handoffInstructions($topUp),
        );
    }

    /**
     * Discover committed paid and settled top-ups whose receipt handoff is
     * absent. The returned count is the number of queue handoffs attempted.
     */
    public function recoverMissingReceipts(int $chunk = 100): int
    {
        return $this->recoverMissingFromQuery(
            'topup_receipts',
            $this->missingReceiptsQuery(),
            $chunk,
            fn (StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult => $this->handoffReceipt($topUp),
        );
    }

    /**
     * Discover top-ups that may carry real money but could not be settled
     * automatically, so the owner is alerted once per top-up.
     */
    public function recoverMissingOwnerAlerts(int $chunk = 100): int
    {
        return $this->recoverMissingFromQuery(
            'topup_owner_alerts',
            $this->missingOwnerAlertsQuery(),
            $chunk,
            fn (StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult => $this->handoffOwnerAlert($topUp),
        );
    }

    public function recoverMissingPaidNotifications(int $chunk = 100): int
    {
        return $this->recoverMissingFromQuery(
            'topup_paid_notifications',
            $this->topUpsQuery()
                ->where('status', StoreBalanceTopUpStatus::Paid->value)
                ->where('settled_at', '>=', now()->subDays(7))
                ->whereNotExists(function ($effects): void {
                    $effects->selectRaw('1')
                        ->from('telegram_outbound_effects')
                        ->where('telegram_outbound_effects.source_type', StoreBalanceTopUp::class)
                        ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectType::BalanceTopUpPaidNotification->value)
                        ->whereRaw('telegram_outbound_effects.source_id = CAST(store_balance_top_ups.id AS TEXT)');
                })
                ->orderBy('id'),
            $chunk,
            fn (StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult => $this->handoffPaidNotification($topUp),
        );
    }

    /**
     * Discover settled top-ups whose public Store Channel proof handoff is
     * absent. Existing effects, disabled channel settings, and non-dispatchable
     * retries stay owned by the shared ledger and are never blindly resent.
     */
    public function recoverMissingChannelProofs(int $chunk = 100): int
    {
        return $this->recoverMissingFromQuery(
            'topup_channel_proofs',
            $this->missingChannelProofsQuery(),
            $chunk,
            fn (StoreBalanceTopUp $topUp): StoreBalanceTopUpOutboundResult => $this->handoffChannelProof($topUp),
        );
    }

    /**
     * Recover both top-up effect families through their normal handoff rules.
     * Existing effects, review states, and non-dispatchable retries are left
     * to the shared ledger and are not blindly resent.
     */
    public function recoverMissing(int $chunk = 100): int
    {
        return $this->recoverMissingInstructions($chunk)
            + $this->recoverMissingReceipts($chunk)
            + $this->recoverMissingOwnerAlerts($chunk)
            + $this->recoverMissingPaidNotifications($chunk)
            + $this->recoverMissingChannelProofs($chunk);
    }

    /**
     * @param  Closure(StoreBalanceTopUp): ?TelegramOutboundEffect  $materialize
     * @param  Closure(string): ?TelegramOutboundEffect  $find
     * @param  Closure(StoreBalanceTopUp): bool  $eligible
     */
    private function handoff(
        StoreBalanceTopUp $topUp,
        Closure $materialize,
        Closure $find,
        Closure $eligible,
    ): StoreBalanceTopUpOutboundResult {
        try {
            $source = $topUp->fresh(['customer']);
            if ($source === null || ! $eligible($source)) {
                return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::NotEligible);
            }

            $topUpId = (string) $source->getKey();
            if ($topUpId === '') {
                return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::NotEligible);
            }

            $effect = $find($topUpId) ?? $materialize($source);
            if ($effect === null) {
                return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::NotEligible);
            }

            return $this->handoffEffect($effect);
        } catch (UniqueConstraintViolationException $exception) {
            $source = $topUp->fresh(['customer']);
            $topUpId = $source?->getKey();
            $effect = $topUpId === null ? null : $find((string) $topUpId);
            if ($effect !== null) {
                return $this->handoffEffect($effect);
            }

            report($exception);

            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::Failed, retryable: true);
        } catch (Throwable $exception) {
            report($exception);

            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::Failed, retryable: true);
        }
    }

    private function handoffEffect(
        TelegramOutboundEffect $effect,
    ): StoreBalanceTopUpOutboundResult {
        $current = $effect->fresh() ?? $effect;
        $initial = $this->resultForExistingState($current);
        if ($initial !== null) {
            return $initial;
        }

        if (! $this->ledger->isDispatchable($current)) {
            return $this->resultForNonDispatchable($current);
        }

        try {
            $this->dispatcher->dispatch($current);
        } catch (Throwable $exception) {
            report($exception);

            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::Failed, retryable: true);
        }

        $afterDispatch = $current->fresh() ?? $current;

        return $this->resultAfterDispatch($afterDispatch);
    }

    private function resultForExistingState(
        TelegramOutboundEffect $effect,
    ): ?StoreBalanceTopUpOutboundResult {
        $outcome = $this->outcomeForNonDispatchState($this->stateOf($effect));

        return $outcome === null
            ? null
            : new StoreBalanceTopUpOutboundResult($outcome);
    }

    private function resultForNonDispatchable(
        TelegramOutboundEffect $effect,
    ): StoreBalanceTopUpOutboundResult {
        return new StoreBalanceTopUpOutboundResult(
            $this->retryScheduled($effect)
                ? StoreBalanceTopUpOutboundOutcome::RetryScheduled
                : StoreBalanceTopUpOutboundOutcome::Failed,
            retryable: $this->retryScheduled($effect),
        );
    }

    private function resultAfterDispatch(
        TelegramOutboundEffect $effect,
    ): StoreBalanceTopUpOutboundResult {
        $state = $this->stateOf($effect);
        $outcome = $this->outcomeForNonDispatchState($state);
        if ($outcome !== null) {
            return new StoreBalanceTopUpOutboundResult($outcome);
        }

        if ($this->retryScheduled($effect)) {
            return new StoreBalanceTopUpOutboundResult(StoreBalanceTopUpOutboundOutcome::RetryScheduled, retryable: true);
        }

        if ($state === TelegramOutboundEffectState::Failed) {
            return new StoreBalanceTopUpOutboundResult(
                $this->ledger->isTerminalFailure($effect)
                    ? StoreBalanceTopUpOutboundOutcome::Failed
                    : StoreBalanceTopUpOutboundOutcome::Queued,
                retryable: ! $this->ledger->isTerminalFailure($effect),
            );
        }

        return new StoreBalanceTopUpOutboundResult(
            $state === TelegramOutboundEffectState::Pending
                ? StoreBalanceTopUpOutboundOutcome::Queued
                : StoreBalanceTopUpOutboundOutcome::Failed,
        );
    }

    private function outcomeForNonDispatchState(
        ?TelegramOutboundEffectState $state,
    ): ?StoreBalanceTopUpOutboundOutcome {
        return match ($state) {
            TelegramOutboundEffectState::Sent => StoreBalanceTopUpOutboundOutcome::Delivered,
            TelegramOutboundEffectState::Sending => StoreBalanceTopUpOutboundOutcome::InProgress,
            TelegramOutboundEffectState::Unknown,
            TelegramOutboundEffectState::ManualReview => StoreBalanceTopUpOutboundOutcome::ReviewRequired,
            default => null,
        };
    }

    private function retryScheduled(TelegramOutboundEffect $effect): bool
    {
        return $this->stateOf($effect) === TelegramOutboundEffectState::Failed
            && $effect->next_attempt_at !== null
            && $effect->next_attempt_at->isFuture();
    }

    private function eligibleInstructions(StoreBalanceTopUp $topUp): bool
    {
        $deliverable = $topUp->status === StoreBalanceTopUpStatus::AwaitingConfirmation
            || $topUp->isPayable();

        return $deliverable && $this->hasCustomerChat($topUp);
    }

    private function eligibleReceipt(StoreBalanceTopUp $topUp): bool
    {
        return $topUp->status === StoreBalanceTopUpStatus::Paid
            && $topUp->provider_total_payment !== null
            && $topUp->settled_at !== null
            && $this->hasCustomerChat($topUp);
    }

    private function hasCustomerChat(StoreBalanceTopUp $topUp): bool
    {
        $chatId = trim((string) $topUp->chat_id);
        $customer = $topUp->customer;

        return $customer !== null
            && $customer->telegram_id !== null
            && $chatId !== ''
            && (string) $customer->telegram_id === $chatId
            && (int) $customer->getKey() === (int) $topUp->customer_id;
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function topUpsQuery(): Builder
    {
        return StoreBalanceTopUp::query()->with('customer');
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function missingInstructionsQuery(): Builder
    {
        return $this->topUpsQuery()
            ->whereIn('status', [
                StoreBalanceTopUpStatus::Payable->value,
                StoreBalanceTopUpStatus::AwaitingConfirmation->value,
            ])
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StoreBalanceTopUp::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(store_balance_top_ups.id AS TEXT)');
            })
            ->orderBy('id');
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function missingReceiptsQuery(): Builder
    {
        return $this->topUpsQuery()
            ->where('status', StoreBalanceTopUpStatus::Paid->value)
            ->whereNotNull('settled_at')
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StoreBalanceTopUp::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(store_balance_top_ups.id AS TEXT)');
            })
            ->orderBy('id');
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function missingOwnerAlertsQuery(): Builder
    {
        return $this->topUpsQuery()
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', [
                        StoreBalanceTopUpStatus::RecoveryPending->value,
                        StoreBalanceTopUpStatus::InvalidProviderResult->value,
                    ])
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', StoreBalanceTopUpStatus::Expired->value)
                            ->whereNotNull('payment_proof_file_id');
                    });
            })
            ->whereDoesntHave('compensation')
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StoreBalanceTopUp::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_OWNER_ALERT)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(store_balance_top_ups.id AS TEXT)');
            })
            ->orderBy('id');
    }

    /** @return Builder<StoreBalanceTopUp> */
    private function missingChannelProofsQuery(): Builder
    {
        return $this->topUpsQuery()
            ->where('status', StoreBalanceTopUpStatus::Paid->value)
            ->whereNotNull('settled_at')
            ->where('settled_at', '>=', now()->subDays(self::CHANNEL_PROOF_RECOVERY_WINDOW_DAYS))
            ->whereNotNull('provider_total_payment')
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StoreBalanceTopUp::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_CHANNEL_PROOF)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(store_balance_top_ups.id AS TEXT)');
            })
            ->orderBy('id');
    }

    /**
     * @param  Builder<StoreBalanceTopUp>  $query
     * @param  Closure(StoreBalanceTopUp): StoreBalanceTopUpOutboundResult  $handoff
     */
    private function recoverMissingFromQuery(string $facet, Builder $query, int $chunk, Closure $handoff): int
    {
        if ($chunk < 1 || $chunk > 1000) {
            throw new InvalidArgumentException('Store Balance top-up outbound chunk must be between 1 and 1000.');
        }

        $topUps = app(TelegramOutboundRecoveryPage::class)->load($facet, $query, $chunk);
        $dispatches = 0;
        foreach ($topUps as $topUp) {
            $dispatches += $handoff($topUp)->isAccepted() ? 1 : 0;
        }

        return $dispatches;
    }

    private function stateOf(TelegramOutboundEffect $effect): ?TelegramOutboundEffectState
    {
        $state = $effect->getAttribute('state');

        return $state instanceof TelegramOutboundEffectState
            ? $state
            : TelegramOutboundEffectState::tryFrom((string) $state);
    }

    private function matchesSentEffect(
        ?TelegramOutboundEffect $effect,
        string $chatId,
        int|string $messageId,
    ): bool {
        return $effect !== null
            && $this->stateOf($effect) === TelegramOutboundEffectState::Sent
            && (string) $effect->telegram_chat_id === trim($chatId)
            && (int) $effect->telegram_message_id === (int) $messageId;
    }
}
