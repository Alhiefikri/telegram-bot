<?php

namespace App\Services\MoneyReceipts;

use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\BscPaymentTransferEvidence;
use App\Models\ExternalMoneyReceipt;
use App\Models\ExternalMoneyReceiptBackfillState;
use App\Models\ExternalMoneyReceiptParentClaim;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\StoreBalanceCompensation;
use App\Models\StoreBalanceTopUp;
use App\Support\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded, idempotent and resumable backfill of legacy money observations
 * into the external money receipt registry.
 *
 * The backfill is strictly additive: it never rewrites a historical amount,
 * provider or status. Settled legacy identities are claimed for their
 * historical target; ambiguous, unnormalizable or compensated evidence is
 * reserved on the denylist and blocks the cutover readiness gate instead of
 * being discarded. Dry runs only inspect and never write.
 */
final class ExternalMoneyReceiptBackfillService
{
    public const SOURCE_BINANCE_PAYMENTS = 'binance_payments';

    public const SOURCE_BSC_PAYMENTS = 'bsc_payments';

    public const SOURCE_QRIS_PAYMENTS = 'qris_payments';

    public const SOURCE_MANUAL_PAYMENTS = 'manual_payments';

    public const SOURCE_QRIS_TOP_UPS = 'qris_top_ups';

    public const SOURCE_COMPENSATIONS = 'compensations';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_BINANCE_PAYMENTS,
        self::SOURCE_BSC_PAYMENTS,
        self::SOURCE_QRIS_PAYMENTS,
        self::SOURCE_MANUAL_PAYMENTS,
        self::SOURCE_QRIS_TOP_UPS,
        self::SOURCE_COMPENSATIONS,
    ];

    public const MAX_CHUNK = 1000;

    public function __construct(
        private readonly ExternalMoneyReceiptWriter $writer,
        private readonly ExternalMoneyReceiptReservations $reservations,
    ) {}

    public function run(string $source, int $chunk, ?string $cursor, int $limit, bool $dryRun): ExternalMoneyReceiptBackfillResult
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown external money receipt backfill source [{$source}].");
        }
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new InvalidArgumentException('External money receipt backfill chunk must be between 1 and '.self::MAX_CHUNK.'.');
        }
        if ($limit < 0) {
            throw new InvalidArgumentException('External money receipt backfill limit must not be negative.');
        }

        $state = null;
        if (! $dryRun) {
            $state = $this->begin($source, $cursor);
            $cursor = $state->cursor;
        }

        $counts = $state === null ? self::emptyCounts() : $this->stateCounts($state);
        $remaining = $limit > 0 ? $limit : PHP_INT_MAX;
        $scanComplete = false;
        $seen = [];
        $seenParents = [];

        while ($remaining > 0) {
            $batchSize = (int) min($chunk, $remaining);
            [$rows, $nextCursor, $hasMore] = $this->fetchBatch($source, $cursor, $batchSize);

            if ($rows->isEmpty()) {
                $scanComplete = true;

                break;
            }

            foreach ($rows as $row) {
                $counts['scanned']++;
                $this->processRow($source, $row, $counts, $dryRun, $seen, $seenParents);
            }

            $remaining -= $rows->count();
            $cursor = $nextCursor;

            if ($state !== null) {
                $this->recordProgress($state, $cursor, $counts);
            }

            if (! $hasMore) {
                $scanComplete = true;

                break;
            }
        }

        $ready = false;
        if ($state !== null) {
            $ready = $this->finish($state, $scanComplete, $cursor, $counts);
        } else {
            $ready = $scanComplete
                && $counts['reserved'] === 0
                && $this->reservations->openCount() === 0;
        }

        return new ExternalMoneyReceiptBackfillResult(
            source: $source,
            dryRun: $dryRun,
            complete: $scanComplete,
            ready: $ready,
            scanned: $counts['scanned'],
            claimed: $counts['claimed'],
            replayed: $counts['replayed'],
            reserved: $counts['reserved'],
            conflicts: $counts['conflicts'],
            reviews: $counts['reviews'],
            skipped: $counts['skipped'],
            cursor: $cursor,
        );
    }

    private function begin(string $source, ?string $explicitCursor): ExternalMoneyReceiptBackfillState
    {
        return DB::transaction(function () use ($source, $explicitCursor): ExternalMoneyReceiptBackfillState {
            $state = $this->lockedState($source);
            $explicit = $explicitCursor === null ? '' : trim($explicitCursor);

            if ($explicit !== '') {
                $state->forceFill([
                    'status' => ExternalMoneyReceiptBackfillState::STATUS_PENDING,
                    'cursor' => $explicit,
                    'completed_at' => null,
                    'ready_at' => null,
                ])->save();

                return $state;
            }

            if ($state->status === ExternalMoneyReceiptBackfillState::STATUS_PENDING && $state->cursor !== null) {
                return $state;
            }

            $state->forceFill(self::emptyStateCounts() + [
                'status' => ExternalMoneyReceiptBackfillState::STATUS_PENDING,
                'cursor' => null,
                'started_at' => now(),
                'completed_at' => null,
                'ready_at' => null,
            ])->save();

            return $state->refresh();
        }, 5);
    }

    private function recordProgress(ExternalMoneyReceiptBackfillState $state, ?string $cursor, array $counts): void
    {
        DB::transaction(function () use ($state, $cursor, $counts): void {
            $locked = ExternalMoneyReceiptBackfillState::query()->whereKey($state->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status === ExternalMoneyReceiptBackfillState::STATUS_READY) {
                return;
            }

            $locked->forceFill($this->countAttributes($counts) + [
                'status' => ExternalMoneyReceiptBackfillState::STATUS_PENDING,
                'cursor' => $cursor,
            ])->save();
        }, 5);
    }

    private function finish(
        ExternalMoneyReceiptBackfillState $state,
        bool $complete,
        ?string $cursor,
        array $counts,
    ): bool {
        return DB::transaction(function () use ($state, $complete, $cursor, $counts): bool {
            $locked = ExternalMoneyReceiptBackfillState::query()->whereKey($state->getKey())->lockForUpdate()->firstOrFail();

            // Readiness is global: any open reservation, including runtime
            // conflict/review holds and compensations, blocks the gate even
            // when this source's own scan is complete.
            $ready = $complete && $this->reservations->openCount() === 0;
            $status = ! $complete
                ? ExternalMoneyReceiptBackfillState::STATUS_PENDING
                : ($ready ? ExternalMoneyReceiptBackfillState::STATUS_READY : ExternalMoneyReceiptBackfillState::STATUS_FAILED);

            $locked->forceFill($this->countAttributes($counts) + [
                'status' => $status,
                'cursor' => $cursor,
                'completed_at' => $complete ? now() : null,
                'ready_at' => $ready ? now() : null,
            ])->save();

            return $ready;
        }, 5);
    }

    private function processRow(
        string $source,
        mixed $row,
        array &$counts,
        bool $dryRun,
        array &$seen,
        array &$seenParents,
    ): void {
        $receipt = null;
        $legacyType = ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT;
        $legacyId = '';
        $provider = null;

        switch ($source) {
            case self::SOURCE_BINANCE_PAYMENTS:
                /** @var Payment $row */
                $legacyId = (string) $row->getKey();
                $provider = 'binance';

                // The backfill only trusts the persisted checkout snapshot as
                // the receiving account namespace; a legacy row without one
                // cannot prove which account received the transfer, so it is
                // reserved for review instead of guessing from live config.
                if (! in_array($row->binance_receiver_type, ['pay_id', 'uid'], true)
                    || trim((string) $row->binance_receiver_value) === '') {
                    if (! $dryRun) {
                        $this->reservations->reserve(
                            source: $source,
                            legacyType: $legacyType,
                            legacyId: $legacyId,
                            provider: $provider,
                            reason: ExternalMoneyReceiptReservation::REASON_MISSING_RECEIVER_NAMESPACE,
                        );
                    }
                    $counts['reserved']++;

                    return;
                }

                try {
                    $receipt = $this->writer->receiptForBinancePayment(
                        $row,
                        (string) $row->provider_reference,
                        (string) $row->currency,
                        (int) $row->amount,
                    );
                } catch (InvalidArgumentException $exception) {
                    $this->reserveUnnormalizable($source, $legacyType, $legacyId, $provider, $exception, $dryRun, $counts);

                    return;
                }

                if ($receipt === null) {
                    if (! $dryRun) {
                        $this->reservations->reserve(
                            source: $source,
                            legacyType: $legacyType,
                            legacyId: $legacyId,
                            provider: $provider,
                            reason: ExternalMoneyReceiptReservation::REASON_MISSING_RECEIVER_NAMESPACE,
                        );
                    }
                    $counts['reserved']++;

                    return;
                }
                break;

            case self::SOURCE_BSC_PAYMENTS:
                /** @var BscPaymentTransferEvidence $row */
                $payment = $row->payment;
                $legacyId = (string) $row->payment_id;
                $provider = 'bsc';

                if ($payment === null || $payment->status !== PaymentStatus::Paid) {
                    $counts['skipped']++;

                    return;
                }
                if ($row->log_index === null) {
                    if (! $dryRun) {
                        $this->reservations->reserve(
                            source: $source,
                            legacyType: $legacyType,
                            legacyId: $legacyId,
                            provider: $provider,
                            reason: ExternalMoneyReceiptReservation::REASON_MISSING_CHAIN_IDENTITY,
                            details: ['tx_hash' => (string) $row->tx_hash],
                        );
                    }
                    $counts['reserved']++;

                    return;
                }

                $detail = PaymentBscDetail::query()->whereKey($payment->getKey())->first();

                try {
                    $receipt = $this->writer->receiptForBscPayment(
                        $payment,
                        (int) ($detail?->chain_id ?: config('services.bsc.chain_id', 56)),
                        (string) $row->tx_hash,
                        (int) $row->log_index,
                        (string) $row->receiver_address,
                        (string) $row->amount_units,
                        $detail?->token_decimals === null ? null : (int) $detail->token_decimals,
                    );
                } catch (InvalidArgumentException $exception) {
                    $this->reserveUnnormalizable($source, $legacyType, $legacyId, $provider, $exception, $dryRun, $counts);

                    return;
                }
                break;

            case self::SOURCE_QRIS_PAYMENTS:
                /** @var Payment $row */
                $legacyId = (string) $row->getKey();
                $provider = strtolower(trim((string) $row->provider));

                try {
                    $receipt = $this->writer->receiptForProviderPayment($row);
                } catch (InvalidArgumentException $exception) {
                    $this->reserveUnnormalizable($source, $legacyType, $legacyId, $provider, $exception, $dryRun, $counts);

                    return;
                }
                break;

            case self::SOURCE_MANUAL_PAYMENTS:
                /** @var Payment $row */
                $legacyId = (string) $row->getKey();
                $provider = 'manual';

                try {
                    $receipt = $this->writer->receiptForOwnerAttestation($row);
                } catch (InvalidArgumentException $exception) {
                    $this->reserveUnnormalizable($source, $legacyType, $legacyId, $provider, $exception, $dryRun, $counts);

                    return;
                }
                break;

            case self::SOURCE_QRIS_TOP_UPS:
                /** @var StoreBalanceTopUp $row */
                $legacyId = (string) $row->getKey();
                $legacyType = ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP;
                $provider = strtolower(trim((string) $row->provider));

                try {
                    $receipt = $this->writer->receiptForStoreBalanceTopUp($row);
                } catch (InvalidArgumentException $exception) {
                    $this->reserveUnnormalizable($source, $legacyType, $legacyId, $provider, $exception, $dryRun, $counts);

                    return;
                }
                break;

            case self::SOURCE_COMPENSATIONS:
                /** @var StoreBalanceCompensation $row */
                $legacyId = (string) $row->store_balance_top_up_id;
                $legacyType = ExternalMoneyReceiptReservation::LEGACY_TYPE_STORE_BALANCE_TOP_UP;
                $provider = strtolower(trim((string) ($row->topUp?->provider ?? '')));

                if (! $dryRun) {
                    $this->reservations->reserve(
                        source: $source,
                        legacyType: $legacyType,
                        legacyId: $legacyId,
                        provider: $provider === '' ? null : $provider,
                        reason: ExternalMoneyReceiptReservation::REASON_COMPENSATED_UNRESOLVED_MONEY,
                        details: [
                            'amount' => (int) $row->amount,
                            'provider_reference_hash' => filled($row->provider_reference)
                                ? hash('sha256', (string) $row->provider_reference)
                                : null,
                        ],
                    );
                }
                $counts['reserved']++;

                return;
        }

        if ($receipt === null) {
            return;
        }

        if ($dryRun) {
            $this->dryRunClassify($receipt, $seen, $seenParents, $counts);

            return;
        }

        $outcome = $this->writer->claim($receipt, $source, $legacyType, $legacyId);

        if ($outcome->allowsMoneyEffect()) {
            $this->reservations->resolve($source, $legacyType, $legacyId);
            $outcome === ExternalMoneyReceiptClaimOutcome::Claimed
                ? $counts['claimed']++
                : $counts['replayed']++;

            return;
        }

        $counts['reserved']++;
        $outcome === ExternalMoneyReceiptClaimOutcome::Conflict
            ? $counts['conflicts']++
            : $counts['reviews']++;
    }

    private function dryRunClassify(
        VerifiedMoneyReceipt $receipt,
        array &$seen,
        array &$seenParents,
        array &$counts,
    ): void {
        $canonical = $receipt->canonicalKey();
        $target = $receipt->target->type->value.':'.$receipt->target->targetId;

        if ($receipt->parentClaim !== null) {
            $parentKey = $receipt->parentClaim->chainId.':'.$receipt->parentClaim->txHash;
            $knownTarget = $seenParents[$parentKey] ?? null;
            if ($knownTarget !== null && $knownTarget !== $target) {
                $counts['reviews']++;
                $counts['reserved']++;

                return;
            }
            $seenParents[$parentKey] = $target;

            $parentRow = ExternalMoneyReceiptParentClaim::query()
                ->where('chain_id', $receipt->parentClaim->chainId)
                ->where('tx_hash', $receipt->parentClaim->txHash)
                ->first();
            $parentTarget = $parentRow === null
                ? null
                : $parentRow->target_type->value.':'.$parentRow->target_id;
            if ($parentRow !== null && ($parentRow->status->isReview() || ($parentTarget !== null && $parentTarget !== $target))) {
                $counts['reviews']++;
                $counts['reserved']++;

                return;
            }
        }

        if (isset($seen[$canonical])) {
            if ($seen[$canonical] === $target) {
                $counts['replayed']++;
            } else {
                $counts['conflicts']++;
                $counts['reserved']++;
            }

            return;
        }
        $seen[$canonical] = $target;

        $existing = ExternalMoneyReceipt::query()->where('canonical_key', $canonical)->first();
        if ($existing === null) {
            $counts['claimed']++;

            return;
        }

        $existingTarget = $existing->target_type->value.':'.$existing->target_id;
        if ($existingTarget === $target && hash_equals($existing->fingerprint, $receipt->fingerprint())) {
            $counts['replayed']++;

            return;
        }

        $counts['conflicts']++;
        $counts['reserved']++;
    }

    private function reserveUnnormalizable(
        string $source,
        string $legacyType,
        string $legacyId,
        ?string $provider,
        InvalidArgumentException $exception,
        bool $dryRun,
        array &$counts,
    ): void {
        if (! $dryRun) {
            $this->reservations->reserve(
                source: $source,
                legacyType: $legacyType,
                legacyId: $legacyId,
                provider: $provider,
                reason: ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY,
                details: ['identity_error' => $exception->getMessage()],
            );
        }

        $counts['reserved']++;
    }

    /**
     * @return array{0: Collection<int, mixed>, 1: ?string, 2: bool}
     */
    private function fetchBatch(string $source, ?string $cursor, int $limit): array
    {
        $query = match ($source) {
            self::SOURCE_BINANCE_PAYMENTS => Payment::query()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Paid->value)
                ->whereNotNull('provider_reference')
                ->where('provider_reference', '!=', ''),
            self::SOURCE_QRIS_PAYMENTS => Payment::query()
                ->whereIn('provider', [PaymentProvider::KLIKQRIS, PaymentProvider::SAKURUPIAH, PaymentProvider::PAKASIR])
                ->where('status', PaymentStatus::Paid->value),
            self::SOURCE_MANUAL_PAYMENTS => Payment::query()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('status', PaymentStatus::Paid->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('provider_reference')->orWhere('provider_reference', '');
                }),
            default => null,
        };

        if ($query !== null) {
            $this->applyPaymentCursor($query, $cursor);

            return $this->batch($query->orderBy('created_at')->orderBy('id'), $limit, fn (Payment $payment): string => $this->paymentCursor($payment));
        }

        if ($source === self::SOURCE_BSC_PAYMENTS) {
            $after = $this->numericCursor($cursor);

            return $this->batch(
                BscPaymentTransferEvidence::query()->with('payment')->where('id', '>', $after)->orderBy('id'),
                $limit,
                fn (BscPaymentTransferEvidence $evidence): string => (string) $evidence->getKey(),
            );
        }

        if ($source === self::SOURCE_QRIS_TOP_UPS) {
            $after = $this->numericCursor($cursor);

            return $this->batch(
                StoreBalanceTopUp::query()
                    ->where('status', StoreBalanceTopUpStatus::Paid->value)
                    ->where('id', '>', $after)
                    ->orderBy('id'),
                $limit,
                fn (StoreBalanceTopUp $topUp): string => (string) $topUp->getKey(),
            );
        }

        $after = $this->numericCursor($cursor);

        return $this->batch(
            StoreBalanceCompensation::query()->with('topUp')->where('id', '>', $after)->orderBy('id'),
            $limit,
            fn (StoreBalanceCompensation $compensation): string => (string) $compensation->getKey(),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): string  $cursorFor
     * @return array{0: Collection<int, TModel>, 1: ?string, 2: bool}
     */
    private function batch(Builder $query, int $limit, callable $cursorFor): array
    {
        /** @var Collection<int, TModel> $rows */
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        return [$rows, $rows->isEmpty() ? null : $cursorFor($rows->last()), $hasMore];
    }

    private function applyPaymentCursor(Builder $query, ?string $cursor): void
    {
        if ($cursor === null || trim($cursor) === '') {
            return;
        }

        [$seconds, $id] = array_pad(explode(':', $cursor, 2), 2, '');
        if (! ctype_digit($seconds) || $id === '') {
            throw new InvalidArgumentException('External money receipt backfill payment cursor is malformed.');
        }

        $at = Carbon::createFromTimestamp((int) $seconds)->setTimezone((string) config('app.timezone'));

        $query->where(function (Builder $query) use ($at, $id): void {
            $query->where('created_at', '>', $at)
                ->orWhere(function (Builder $query) use ($at, $id): void {
                    $query->where('created_at', $at)->where('id', '>', $id);
                });
        });
    }

    private function paymentCursor(Payment $payment): string
    {
        return ((int) $payment->created_at?->getTimestamp()).':'.(string) $payment->getKey();
    }

    private function numericCursor(?string $cursor): int
    {
        if ($cursor === null || trim($cursor) === '') {
            return 0;
        }
        if (! ctype_digit(trim($cursor))) {
            throw new InvalidArgumentException('External money receipt backfill cursor is malformed.');
        }

        return (int) trim($cursor);
    }

    private function lockedState(string $source): ExternalMoneyReceiptBackfillState
    {
        $state = ExternalMoneyReceiptBackfillState::query()
            ->where('source', $source)
            ->lockForUpdate()
            ->first();

        if ($state !== null) {
            return $state;
        }

        return ExternalMoneyReceiptBackfillState::query()->create([
            'source' => $source,
            'status' => ExternalMoneyReceiptBackfillState::STATUS_PENDING,
            'cursor' => null,
        ] + self::emptyStateCounts());
    }

    /** @return array<string, int> */
    private static function emptyCounts(): array
    {
        return [
            'scanned' => 0,
            'claimed' => 0,
            'replayed' => 0,
            'reserved' => 0,
            'conflicts' => 0,
            'reviews' => 0,
            'skipped' => 0,
        ];
    }

    /** @return array<string, int> */
    private static function emptyStateCounts(): array
    {
        return [
            'scanned_count' => 0,
            'claimed_count' => 0,
            'replayed_count' => 0,
            'reserved_count' => 0,
            'conflict_count' => 0,
            'review_count' => 0,
            'skipped_count' => 0,
        ];
    }

    /** @return array<string, int> */
    private function stateCounts(ExternalMoneyReceiptBackfillState $state): array
    {
        return [
            'scanned' => (int) $state->scanned_count,
            'claimed' => (int) $state->claimed_count,
            'replayed' => (int) $state->replayed_count,
            'reserved' => (int) $state->reserved_count,
            'conflicts' => (int) $state->conflict_count,
            'reviews' => (int) $state->review_count,
            'skipped' => (int) $state->skipped_count,
        ];
    }

    /** @return array<string, int> */
    private function countAttributes(array $counts): array
    {
        return [
            'scanned_count' => (int) ($counts['scanned'] ?? 0),
            'claimed_count' => (int) ($counts['claimed'] ?? 0),
            'replayed_count' => (int) ($counts['replayed'] ?? 0),
            'reserved_count' => (int) ($counts['reserved'] ?? 0),
            'conflict_count' => (int) ($counts['conflicts'] ?? 0),
            'review_count' => (int) ($counts['reviews'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ];
    }
}
