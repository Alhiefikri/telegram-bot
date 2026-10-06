<?php

namespace App\Services\UsdtBalance;

use App\Enums\ExternalMoneyReceiptStatus;
use App\Enums\ExternalMoneyReceiptTargetType;
use App\Enums\UsdtBalanceEntryKind;
use App\Enums\UsdtReceiptFindingKind;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded, read-only reconciliation of claimed external USDT funding receipts
 * against the USDT subledger. It reports, exactly and without rounding:
 *
 * - receipts claimed or held for a `customer_usdt_top_up` target that have no
 *   funding entry (money observed but not settled);
 * - funding credits with no claimed receipt (credited without external
 *   evidence);
 * - receipts whose exact micro amount differs from the credited amount
 *   (unmatched fee or overcredit stays visible instead of being absorbed).
 *
 * A receipt and its settled entry share one canonical identity: the receipt
 * target id is the top-up id, which is the entry's `customer_usdt_top_up`
 * source id. Receipts of other targets and currencies never enter this
 * ledger partition.
 */
final class UsdtReceiptReconciliation
{
    public const MAX_LIMIT = 1000;

    public const FUNDING_SOURCE_TYPE = 'customer_usdt_top_up';

    public function inspect(
        ?int $customerId,
        ?int $afterReceiptId,
        ?int $afterCreditId,
        int $limit,
    ): UsdtReceiptReconciliationReport {
        $this->assertInputs($customerId, $afterReceiptId, $afterCreditId, $limit);

        $unresolved = BigInteger::zero();
        $overcredited = BigInteger::zero();
        $findings = [];

        $receipts = $this->receiptQuery($customerId, $afterReceiptId, $limit)->get();
        $receiptsHasMore = $receipts->count() > $limit;
        if ($receiptsHasMore) {
            $receipts = $receipts->slice(0, $limit)->values();
        }

        foreach ($receipts as $receipt) {
            $assessment = $this->assessReceipt($receipt);
            if ($assessment['finding'] !== null) {
                $findings[] = $assessment['finding'];
            }
            $unresolved = $unresolved->plus($assessment['unresolved']);
            $overcredited = $overcredited->plus($assessment['overcredited']);
        }

        $credits = $this->unreceiptedCreditQuery($customerId, $afterCreditId, $limit)->get();
        $creditsHaveMore = $credits->count() > $limit;
        if ($creditsHaveMore) {
            $credits = $credits->slice(0, $limit)->values();
        }

        foreach ($credits as $credit) {
            $findings[] = new UsdtReceiptFinding(
                kind: UsdtReceiptFindingKind::CreditWithoutReceipt,
                receiptId: null,
                entryId: (int) $credit->entry_id,
                customerId: (int) $credit->customer_id,
                provider: null,
                currency: UsdtMicroAmount::CURRENCY,
                targetId: (string) $credit->target_id,
                receiptMicro: null,
                creditedMicro: (string) (int) $credit->credited_micro,
                differenceMicro: null,
            );
        }

        return new UsdtReceiptReconciliationReport(
            receiptsInspected: $receipts->count(),
            unreceiptedCreditsInspected: $credits->count(),
            findings: $findings,
            nextReceiptCursor: $receiptsHasMore ? (int) $receipts->last()->receipt_id : null,
            nextCreditCursor: $creditsHaveMore ? (int) $credits->last()->entry_id : null,
            receiptsHasMore: $receiptsHasMore,
            creditsHaveMore: $creditsHaveMore,
            unresolvedDepositMicro: $unresolved,
            overcreditedMicro: $overcredited,
        );
    }

    private function receiptQuery(?int $customerId, ?int $afterReceiptId, int $limit)
    {
        return DB::table('external_money_receipts as receipts')
            ->leftJoin('customer_usdt_top_ups as top_ups', DB::raw('CAST(top_ups.id AS TEXT)'), '=', 'receipts.target_id')
            ->leftJoin('customer_usdt_top_up_resolutions as resolutions', DB::raw('CAST(resolutions.top_up_id AS TEXT)'), '=', 'receipts.target_id')
            ->leftJoin('external_money_receipts as returned', 'returned.id', '=', 'resolutions.refund_receipt_id')
            ->leftJoin('external_money_receipts as deposited', 'deposited.id', '=', 'resolutions.receipt_id')
            ->leftJoin('customer_usdt_balance_entries as entries', function ($join): void {
                $join->on('entries.source_id', '=', 'receipts.target_id')
                    ->where('entries.source_type', '=', self::FUNDING_SOURCE_TYPE)
                    ->where('entries.kind', '=', UsdtBalanceEntryKind::FundingCredit->value)
                    ->where(fn ($receipt) => $receipt->whereNull('resolutions.top_up_id')
                        ->orWhereColumn('resolutions.receipt_id', 'receipts.id'));
            })
            ->leftJoin('customer_usdt_balance_accounts as accounts', 'accounts.id', '=', 'entries.customer_usdt_balance_account_id')
            ->leftJoin('customer_usdt_top_up_fee_events as fees', 'fees.receipt_id', '=', 'receipts.id')
            ->where('receipts.target_type', ExternalMoneyReceiptTargetType::CustomerUsdtTopUp->value)
            ->when($afterReceiptId !== null, fn ($query) => $query->where('receipts.id', '>', $afterReceiptId))
            ->when($customerId !== null, fn ($query) => $query->where(fn ($scoped) => $scoped
                ->where('accounts.customer_id', $customerId)
                ->orWhere('top_ups.customer_id', $customerId)
                ->orWhere(fn ($unattributed) => $unattributed->whereNull('entries.id')->whereNull('top_ups.id'))))
            ->orderBy('receipts.id')
            ->limit($limit + 1)
            ->select([
                'receipts.id as receipt_id',
                'receipts.provider',
                'receipts.currency',
                'receipts.status',
                'receipts.amount_units',
                'receipts.decimals',
                'receipts.target_id',
                'entries.id as entry_id',
                'entries.amount_micro as credited_micro',
                DB::raw('COALESCE(accounts.customer_id, top_ups.customer_id) AS customer_id'),
                'fees.amount_micro as accounted_fee_micro',
                'resolutions.kind as resolution_kind',
                'resolutions.receipt_id as resolution_receipt_id',
                'resolutions.refund_receipt_id',
                'resolutions.resolved_amount_micro',
                'returned.status as return_status',
                'deposited.status as deposit_status',
            ]);
    }

    private function unreceiptedCreditQuery(?int $customerId, ?int $afterCreditId, int $limit)
    {
        return DB::table('customer_usdt_balance_entries as entries')
            ->join('customer_usdt_balance_accounts as accounts', 'accounts.id', '=', 'entries.customer_usdt_balance_account_id')
            ->leftJoin('external_money_receipts as receipts', function ($join): void {
                $join->on('receipts.target_id', '=', 'entries.source_id')
                    ->where('receipts.target_type', '=', ExternalMoneyReceiptTargetType::CustomerUsdtTopUp->value);
            })
            ->where('entries.kind', UsdtBalanceEntryKind::FundingCredit->value)
            ->where('entries.source_type', self::FUNDING_SOURCE_TYPE)
            ->whereNull('receipts.id')
            ->when($customerId !== null, fn ($query) => $query->where('accounts.customer_id', $customerId))
            ->when($afterCreditId !== null, fn ($query) => $query->where('entries.id', '>', $afterCreditId))
            ->orderBy('entries.id')
            ->limit($limit + 1)
            ->select([
                'entries.id as entry_id',
                'entries.source_id as target_id',
                'entries.amount_micro as credited_micro',
                'accounts.customer_id',
            ]);
    }

    /**
     * @return array{finding: ?UsdtReceiptFinding, unresolved: BigInteger, overcredited: BigInteger}
     */
    private function assessReceipt(object $receipt): array
    {
        $zero = BigInteger::zero();
        $currency = (string) $receipt->currency;
        $receiptMicro = $this->exactMicro($receipt);
        // Only the exact two permanently linked receipts close an external return.
        if ($receipt->resolution_kind === 'externally_refunded' && $receipt->refund_receipt_id !== null
            && in_array((int) $receipt->receipt_id, [(int) $receipt->resolution_receipt_id, (int) $receipt->refund_receipt_id], true)
            && $receipt->status !== ExternalMoneyReceiptStatus::Review->value && $receiptMicro !== null
            && $currency === UsdtMicroAmount::CURRENCY
            && $receipt->return_status === ExternalMoneyReceiptStatus::Claimed->value
            && $receipt->deposit_status === ExternalMoneyReceiptStatus::Claimed->value
            && $receiptMicro === (string) $receipt->resolved_amount_micro) {
            return ['finding' => null, 'unresolved' => $zero, 'overcredited' => $zero];
        }
        $settled = $receipt->entry_id !== null;
        $creditedMicro = $settled ? (string) (int) $receipt->credited_micro : null;

        if (! $settled) {
            $kind = match (true) {
                $currency !== UsdtMicroAmount::CURRENCY => UsdtReceiptFindingKind::CurrencyMismatch,
                $receiptMicro === null => UsdtReceiptFindingKind::ReceiptAmountUnrepresentable,
                $receipt->status === ExternalMoneyReceiptStatus::Review->value => UsdtReceiptFindingKind::HeldReceipt,
                default => UsdtReceiptFindingKind::OrphanClaimedReceipt,
            };

            $unresolved = $kind === UsdtReceiptFindingKind::HeldReceipt
                || $kind === UsdtReceiptFindingKind::OrphanClaimedReceipt
                ? BigInteger::of((string) $receiptMicro)
                : $zero;

            return [
                'finding' => $this->finding($kind, $receipt, null, $receiptMicro, null, null),
                'unresolved' => $unresolved,
                'overcredited' => $zero,
            ];
        }

        if ($currency !== UsdtMicroAmount::CURRENCY || $receiptMicro === null) {
            $kind = $currency !== UsdtMicroAmount::CURRENCY
                ? UsdtReceiptFindingKind::CurrencyMismatch
                : UsdtReceiptFindingKind::ReceiptAmountUnrepresentable;

            return [
                'finding' => $this->finding($kind, $receipt, (int) $receipt->entry_id, $receiptMicro, $creditedMicro, null),
                'unresolved' => $zero,
                'overcredited' => $zero,
            ];
        }

        $difference = BigInteger::of($receiptMicro)->minus($creditedMicro)->minus((string) ($receipt->accounted_fee_micro ?? 0));
        $held = $receipt->status === ExternalMoneyReceiptStatus::Review->value;

        if ($difference->isZero() && ! $held) {
            return ['finding' => null, 'unresolved' => $zero, 'overcredited' => $zero];
        }

        if ($difference->isZero()) {
            return [
                'finding' => $this->finding(
                    UsdtReceiptFindingKind::HeldReceipt,
                    $receipt,
                    (int) $receipt->entry_id,
                    $receiptMicro,
                    $creditedMicro,
                    null,
                ),
                'unresolved' => $zero,
                'overcredited' => $zero,
            ];
        }

        if ($difference->isPositive()) {
            return [
                'finding' => $this->finding(
                    $held ? UsdtReceiptFindingKind::HeldReceipt : UsdtReceiptFindingKind::UnsettledDifference,
                    $receipt,
                    (int) $receipt->entry_id,
                    $receiptMicro,
                    $creditedMicro,
                    $difference->toString(),
                ),
                'unresolved' => $difference,
                'overcredited' => $zero,
            ];
        }

        return [
            'finding' => $this->finding(
                $held ? UsdtReceiptFindingKind::HeldReceipt : UsdtReceiptFindingKind::OvercreditedReceipt,
                $receipt,
                (int) $receipt->entry_id,
                $receiptMicro,
                $creditedMicro,
                $difference->abs()->toString(),
            ),
            'unresolved' => $zero,
            'overcredited' => $difference->abs(),
        ];
    }

    private function finding(
        UsdtReceiptFindingKind $kind,
        object $receipt,
        ?int $entryId,
        ?string $receiptMicro,
        ?string $creditedMicro,
        ?string $differenceMicro,
    ): UsdtReceiptFinding {
        return new UsdtReceiptFinding(
            kind: $kind,
            receiptId: (int) $receipt->receipt_id,
            entryId: $entryId,
            customerId: $receipt->customer_id === null ? null : (int) $receipt->customer_id,
            provider: (string) $receipt->provider,
            currency: (string) $receipt->currency,
            targetId: (string) $receipt->target_id,
            receiptMicro: $receiptMicro,
            creditedMicro: $creditedMicro,
            differenceMicro: $differenceMicro,
        );
    }

    private function exactMicro(object $receipt): ?string
    {
        if ($receipt->decimals === null) {
            return null;
        }

        try {
            return UsdtMicroAmount::fromRawTokenUnits((string) $receipt->amount_units, (int) $receipt->decimals)->integerUnits();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function assertInputs(?int $customerId, ?int $afterReceiptId, ?int $afterCreditId, int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('USDT receipt reconciliation limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        if ($customerId !== null && $customerId <= 0) {
            throw new InvalidArgumentException('USDT receipt reconciliation customer id must be a positive integer.');
        }

        foreach (['receipt cursor' => $afterReceiptId, 'credit cursor' => $afterCreditId] as $label => $cursor) {
            if ($cursor !== null && $cursor < 0) {
                throw new InvalidArgumentException("USDT receipt reconciliation {$label} must not be negative.");
            }
        }
    }
}
