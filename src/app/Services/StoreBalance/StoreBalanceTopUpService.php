<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTopUpLifecycleResult;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\KlikQris\StoreBalanceTopUpEventLogger;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class StoreBalanceTopUpService
{
    private const ACTION_PATTERN = '/\Abalance:topup:([1-9][0-9]{0,18}):(check|retry)\z/D';

    public function __construct(
        private readonly StoreBalanceLedger $ledger,
        private readonly StoreBalanceTopUpEventLogger $events,
        private readonly KlikQrisNativeQrisService $nativeQris,
        private readonly ?AuditLogger $audit = null,
        private readonly ?TelegramOutboundEffectService $outboundEffects = null,
        private readonly ?TelegramOutboundEffectDispatcher $outboundDispatcher = null,
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
        return DB::transaction(function () use ($intent): StoreBalanceTopUp {
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
                    'provider' => PaymentProvider::KLIKQRIS,
                    'merchant_ref' => self::merchantReference($lockedIntent),
                    'idempotency_key' => self::idempotencyKey($lockedIntent),
                    'currency' => 'IDR',
                    'amount' => $lockedIntent->amount,
                    'status' => StoreBalanceTopUpStatus::Pending,
                    'expires_at' => $lockedIntent->expires_at,
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

    public function reconcile(StoreBalanceTopUp $topUp): KlikQrisTopUpLifecycleResult
    {
        $result = $this->nativeQris->reconcileTopUp(
            $topUp,
            function (StoreBalanceTopUp $current, KlikQrisTransaction $transaction): void {
                $this->settleAuthoritativeTransaction(
                    $current,
                    $transaction,
                    fn (StoreBalanceTopUp $lockedTopUp): StoreBalanceTopUpEvent => $this->events->logReconciliation($transaction, $lockedTopUp),
                );
            },
        );

        return $result ?? KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::Creating);
    }

    public function applyAuthoritativeTransaction(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
        StoreBalanceTopUpEvent $event,
    ): StoreBalanceTopUpEvent {
        try {
            $transaction->assertMatchesTopUp($topUp);
        } catch (Throwable) {
            return $event->fresh();
        }

        if (! $this->hasTrustedSettlementSignature($topUp, $transaction)) {
            return $event->fresh();
        }

        return $this->settleAuthoritativeTransaction(
            $topUp,
            $transaction,
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
                $this->materializePaidReceipt($lockedTopUp);
            }

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
        return DB::transaction(function () use ($topUp, $transaction, $eventFactory): StoreBalanceTopUpEvent {
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
                    $this->materializePaidReceipt($lockedTopUp);
                }

                return $lockedEvent->fresh();
            }

            try {
                $transaction->assertMatchesTopUp($lockedTopUp);
            } catch (Throwable) {
                return $lockedEvent->fresh();
            }

            if (! $this->hasTrustedSettlementSignature($lockedTopUp, $transaction)) {
                return $lockedEvent->fresh();
            }

            if ($lockedTopUp->status === StoreBalanceTopUpStatus::Paid) {
                if (! $this->matchesSettledEvidence($lockedTopUp, $transaction)) {
                    return $lockedEvent->fresh();
                }

                $lockedEvent->forceFill(['processed_at' => now()])->save();
                $this->materializePaidReceipt($lockedTopUp);

                return $lockedEvent->fresh();
            }

            if (! $this->isEligibleForSettlement($lockedTopUp, $transaction)) {
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
                'provider_create_state' => 'provider_SUCCESS',
                'paid_at' => $transaction->paidAt() ?? now(),
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

            $this->materializePaidReceipt($lockedTopUp);

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

    private function hasTrustedSettlementSignature(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
    ): bool {
        $stored = $topUp->providerSignature();
        $received = $transaction->signature();

        return $stored !== null
            && $received !== null
            && hash_equals($stored, $received);
    }

    private function isEligibleForSettlement(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
    ): bool {
        return in_array($topUp->status, [StoreBalanceTopUpStatus::Pending, StoreBalanceTopUpStatus::Payable], true)
            && strtolower(trim((string) $topUp->provider)) === PaymentProvider::KLIKQRIS
            && strtoupper(trim((string) $topUp->currency)) === 'IDR'
            && strtolower(trim((string) $topUp->provider_payment_method)) === 'qris'
            && $topUp->expires_at?->isFuture() === true
            && $topUp->provider_expires_at?->isFuture() === true
            && $topUp->provider_fee !== null
            && $topUp->provider_total_payment !== null
            && (int) $topUp->provider_total_payment >= (int) $topUp->amount
            && $transaction->isSuccess()
            && $transaction->amount()->rupiah() === (int) $topUp->amount
            && $transaction->totalAmount() === (int) $topUp->provider_total_payment;
    }

    private function matchesSettledEvidence(
        StoreBalanceTopUp $topUp,
        KlikQrisTransaction $transaction,
    ): bool {
        $providerReference = $transaction->providerReference();

        return $transaction->isSuccess()
            && $transaction->amount()->rupiah() === (int) $topUp->amount
            && $topUp->provider_total_payment !== null
            && $transaction->totalAmount() === (int) $topUp->provider_total_payment
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

    private function materializePaidReceipt(StoreBalanceTopUp $topUp): void
    {
        $effects = $this->outboundEffects ?? app(TelegramOutboundEffectService::class);
        $effect = $effects->recordBalanceTopUpReceipt($topUp->fresh(['customer']));
        if ($effect !== null) {
            ($this->outboundDispatcher ?? app(TelegramOutboundEffectDispatcher::class))->dispatch($effect);
        }
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
