<?php

namespace App\Services\MoneyReceipts;

use App\Enums\BalanceOperationMetric;
use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\ExternalMoneyReceiptStatus;
use App\Exceptions\ExternalMoneyReceiptClaimRejected;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptAlias;
use App\Models\ExternalMoneyReceiptParentClaim;
use App\Services\Operations\BalanceOperationEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Canonical claim seam for one externally observed money transfer. Money
 * owners call claim() inside their own short database transaction and without
 * any HTTP in scope: the immutable receipt identity, its verified aliases and
 * its BSC parent transaction are claimed atomically for one economic target.
 *
 * Replaying the same claims returns Replayed; a changed money payload or a
 * second target returns Conflict; a BSC transaction whose logs point at two
 * targets is held as Review, and every later claim or replay against that
 * transaction stays held instead of being consumed. SQL uniqueness races
 * are recovered through savepoints or a fresh transaction, never by
 * continuing an aborted PostgreSQL transaction.
 */
final class ExternalMoneyReceiptClaims
{
    public function __construct(private readonly BalanceOperationEventRecorder $events) {}

    public function claim(VerifiedMoneyReceipt $receipt): ExternalMoneyReceiptClaimOutcome
    {
        try {
            $outcome = DB::transaction(
                fn (): ExternalMoneyReceiptClaimOutcome => $this->claimWithinTransaction($receipt),
                1,
            );
        } catch (ExternalMoneyReceiptClaimRejected $rejected) {
            $outcome = $rejected->outcome;
        } catch (UniqueConstraintViolationException) {
            $outcome = $this->resolveAfterUniqueViolation($receipt);
        }

        $this->events->record(BalanceOperationMetric::ReceiptClaimOutcome, $outcome->value);

        return $outcome;
    }

    private function claimWithinTransaction(VerifiedMoneyReceipt $receipt): ExternalMoneyReceiptClaimOutcome
    {
        $record = ExternalMoneyReceipt::query()
            ->where('canonical_key', $receipt->canonicalKey())
            ->lockForUpdate()
            ->first();

        if ($record !== null && ! $this->fingerprintsMatch($record, $receipt)) {
            return ExternalMoneyReceiptClaimOutcome::Conflict;
        }

        if (! $this->aliasesAreCompatible($receipt, $record)) {
            throw new ExternalMoneyReceiptClaimRejected(
                ExternalMoneyReceiptClaimOutcome::Conflict,
                'A verified alias of this receipt is already attached to different funds.',
            );
        }

        $inserted = false;
        if ($record === null) {
            $inserted = $this->insertReceipt($receipt);
            $record = ExternalMoneyReceipt::query()
                ->where('canonical_key', $receipt->canonicalKey())
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new ExternalMoneyReceiptClaimRejected(
                    ExternalMoneyReceiptClaimOutcome::Conflict,
                    'The external money receipt could not be established.',
                );
            }
            if (! $inserted && ! $this->fingerprintsMatch($record, $receipt)) {
                return ExternalMoneyReceiptClaimOutcome::Conflict;
            }
        }

        if ($record->status->isReview()) {
            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        // Canonical and alternate identities share the same unique claim index.
        // Sorted acquisition also prevents reversed identity sets from deadlocking.
        $identities = [new MoneyReceiptAlias('canonical', $receipt->identity), ...$receipt->aliases];
        usort($identities, static fn (MoneyReceiptAlias $left, MoneyReceiptAlias $right): int => strcmp($left->identity->canonicalKey(), $right->identity->canonicalKey()));
        foreach ($identities as $alias) {
            $this->claimAlias($alias, $record);
        }

        return $this->claimParent($receipt, $record, $inserted);
    }

    private function insertReceipt(VerifiedMoneyReceipt $receipt): bool
    {
        $now = now();

        return DB::transaction(function () use ($receipt, $now): bool {
            return ExternalMoneyReceipt::query()->insertOrIgnore([
                'canonical_key' => $receipt->canonicalKey(),
                'provider' => $receipt->identity->provider(),
                'identity_kind' => $receipt->identity->kind(),
                'account_namespace' => $receipt->identity->accountNamespace(),
                'reference' => $receipt->identity->reference(),
                'chain_id' => $receipt->identity->chainId(),
                'tx_hash' => $receipt->identity->txHash(),
                'log_index' => $receipt->identity->logIndex(),
                'beneficiary' => $receipt->beneficiary,
                'currency' => $receipt->currency,
                'amount_units' => $receipt->amountUnits,
                'decimals' => $receipt->decimals,
                'status' => ExternalMoneyReceiptStatus::Claimed->value,
                'target_type' => $receipt->target->type->value,
                'target_id' => $receipt->target->targetId,
                'fingerprint' => $receipt->fingerprint(),
                'review_reason' => null,
                'evidence_payload' => json_encode($receipt->evidence, JSON_THROW_ON_ERROR),
                'claimed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
        }, 1);
    }

    private function aliasesAreCompatible(VerifiedMoneyReceipt $receipt, ?ExternalMoneyReceipt $record): bool
    {
        foreach ([$receipt->identity, ...array_map(static fn (MoneyReceiptAlias $alias): MoneyReceiptIdentity => $alias->identity, $receipt->aliases)] as $identity) {
            $key = $identity->canonicalKey();
            $canonical = ExternalMoneyReceipt::query()->where('canonical_key', $key)->first();
            if ($canonical !== null && ($record === null || (int) $canonical->id !== (int) $record->id)) {
                return false;
            }
            $attached = ExternalMoneyReceiptAlias::query()
                ->where('alias_key', $key)
                ->first();

            if ($attached !== null && ($record === null || ! $this->aliasBelongsTo($attached, $record))) {
                return false;
            }
        }

        return true;
    }

    private function claimAlias(MoneyReceiptAlias $alias, ExternalMoneyReceipt $record): void
    {
        $aliasKey = $alias->identity->canonicalKey();
        $attached = ExternalMoneyReceiptAlias::query()
            ->where('alias_key', $aliasKey)
            ->lockForUpdate()
            ->first();

        if ($attached !== null) {
            if (! $this->aliasBelongsTo($attached, $record)) {
                throw new ExternalMoneyReceiptClaimRejected(
                    ExternalMoneyReceiptClaimOutcome::Conflict,
                    'A verified alias of this receipt is already attached to different funds.',
                );
            }

            return;
        }

        try {
            DB::transaction(function () use ($alias, $aliasKey, $record): void {
                ExternalMoneyReceiptAlias::query()->insert([
                    'external_money_receipt_id' => $record->id,
                    'alias_key' => $aliasKey,
                    'alias_type' => $alias->type,
                    'created_at' => now(),
                ]);
            }, 1);
        } catch (UniqueConstraintViolationException) {
            // The savepoint rolled back; re-read the committed alias in the
            // caller's still-valid transaction before deciding.
            $attached = ExternalMoneyReceiptAlias::query()->where('alias_key', $aliasKey)->first();

            if ($attached === null || ! $this->aliasBelongsTo($attached, $record)) {
                throw new ExternalMoneyReceiptClaimRejected(
                    ExternalMoneyReceiptClaimOutcome::Conflict,
                    'A verified alias of this receipt is already attached to different funds.',
                );
            }
        }
    }

    private function claimParent(
        VerifiedMoneyReceipt $receipt,
        ExternalMoneyReceipt $record,
        bool $inserted,
    ): ExternalMoneyReceiptClaimOutcome {
        $parent = $receipt->parentClaim;
        if ($parent === null) {
            return $inserted ? ExternalMoneyReceiptClaimOutcome::Claimed : ExternalMoneyReceiptClaimOutcome::Replayed;
        }

        $row = $this->parentClaimRow($parent);

        if ($row === null) {
            try {
                DB::transaction(function () use ($parent, $receipt, $record): void {
                    $now = now();
                    ExternalMoneyReceiptParentClaim::query()->insert([
                        'chain_id' => $parent->chainId,
                        'tx_hash' => $parent->txHash,
                        'external_money_receipt_id' => $record->id,
                        'status' => ExternalMoneyReceiptStatus::Claimed->value,
                        'target_type' => $receipt->target->type->value,
                        'target_id' => $receipt->target->targetId,
                        'review_reason' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }, 1);

                return $inserted ? ExternalMoneyReceiptClaimOutcome::Claimed : ExternalMoneyReceiptClaimOutcome::Replayed;
            } catch (UniqueConstraintViolationException) {
                // Another transaction claimed the parent first; recover on the
                // still-valid transaction and reconcile below.
                $row = $this->parentClaimRow($parent);

                if ($row === null) {
                    throw new ExternalMoneyReceiptClaimRejected(
                        ExternalMoneyReceiptClaimOutcome::Conflict,
                        'The BSC parent transaction claim could not be established.',
                    );
                }
            }
        }

        if ($row->status->isReview()) {
            $this->holdReceiptForReview($record);

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        if ($row->target_type === $receipt->target->type && $row->target_id === $receipt->target->targetId) {
            return $inserted ? ExternalMoneyReceiptClaimOutcome::Claimed : ExternalMoneyReceiptClaimOutcome::Replayed;
        }

        // One BSC transaction may contain several legitimate transfer logs, but
        // in this release they must all fund one target. Keep the original
        // owner, record the ambiguity on the parent and hold every later claim
        // against the transaction - including replays - as review so no
        // ambiguous log can be consumed.
        $this->holdParentForReview($row);
        $this->holdReceiptForReview($record);

        return ExternalMoneyReceiptClaimOutcome::Review;
    }

    private function holdParentForReview(ExternalMoneyReceiptParentClaim $parent): void
    {
        if ($parent->status->isReview()) {
            return;
        }

        $parent->forceFill([
            'status' => ExternalMoneyReceiptStatus::Review,
            'review_reason' => ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET,
        ])->save();
    }

    private function holdReceiptForReview(ExternalMoneyReceipt $record): void
    {
        if ($record->status->isReview()) {
            return;
        }

        $record->forceFill([
            'status' => ExternalMoneyReceiptStatus::Review,
            'review_reason' => ExternalMoneyReceipt::REVIEW_REASON_BSC_PARENT_CROSS_TARGET,
            'claimed_at' => null,
        ])->save();
    }

    /**
     * A unique error escaped the claim savepoint (for example a receipt insert
     * raced a concurrent committed claim). Re-run the canonical claim logic in
     * a fresh transaction so replay, conflict and review state are all
     * resolved from what actually committed; fail closed on a repeated error.
     */
    private function resolveAfterUniqueViolation(VerifiedMoneyReceipt $receipt): ExternalMoneyReceiptClaimOutcome
    {
        try {
            return DB::transaction(
                fn (): ExternalMoneyReceiptClaimOutcome => $this->claimWithinTransaction($receipt),
                1,
            );
        } catch (ExternalMoneyReceiptClaimRejected $rejected) {
            return $rejected->outcome;
        } catch (UniqueConstraintViolationException) {
            return ExternalMoneyReceiptClaimOutcome::Conflict;
        }
    }

    private function fingerprintsMatch(ExternalMoneyReceipt $record, VerifiedMoneyReceipt $receipt): bool
    {
        return hash_equals($record->fingerprint, $receipt->fingerprint());
    }

    private function aliasBelongsTo(ExternalMoneyReceiptAlias $attached, ExternalMoneyReceipt $record): bool
    {
        return (int) $attached->external_money_receipt_id === (int) $record->id;
    }

    private function parentClaimRow(MoneyReceiptParentClaim $parent): ?ExternalMoneyReceiptParentClaim
    {
        return ExternalMoneyReceiptParentClaim::query()
            ->where('chain_id', $parent->chainId)
            ->where('tx_hash', $parent->txHash)
            ->lockForUpdate()
            ->first();
    }
}
