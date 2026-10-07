<?php

namespace App\Services\Operations;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\BalanceRestoreGate;
use App\Models\TelegramOutboundEffect;
use App\Models\UsdtBalanceReconciliationRun;
use App\Services\AuditLogger;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Post-restore operational gate (#558).
 *
 * A PostgreSQL restore (PITR or logical dump) rolls the database back to a
 * point in time while external side effects — chain transfers, provider
 * settlements and Telegram deliveries — already happened and are not rolled
 * back. This service therefore:
 *
 * 1. `open()` records the restore window and blocks new USDT funding/spending
 *    through `CustomerUsdtFundingGate` (reconciliation, settlement, refund and
 *    reversal of money already in the system keep running).
 * 2. `replay()` re-runs authoritative external evidence for unsettled funding
 *    targets and quarantines non-terminal balance deliveries as `unknown`, so a
 *    message that may already have been sent is never blindly resent.
 * 3. `close()` clears the gate only after a replay completed after the gate
 *    opened **and** a full reconciliation run recorded after that replay shows
 *    zero unexplained drift.
 *
 * It never moves money, never deletes a ledger/receipt row and never refunds.
 */
final class BalanceRestoreGateService
{
    /** Settlement states that need no further external evidence replay. */
    private const TERMINAL_SETTLEMENT_STATES = ['settled', 'externally_refunded', 'resolved_by_compensation', 'rejected'];

    /**
     * Balance delivery families whose post-restore state is ambiguous: the
     * database cannot prove whether the message was delivered inside the lost
     * window, so they are moved to the manual-review state instead of resent.
     *
     * @var list<string>
     */
    private const AMBIGUOUS_DELIVERY_FAMILIES = [
        TelegramOutboundEffectType::UsdtTopUpReceipt->value,
        TelegramOutboundEffectType::UsdtRefundReceipt->value,
        TelegramOutboundEffectType::BalanceTopUpReceipt->value,
        TelegramOutboundEffectType::BalanceTopUpOwnerAlert->value,
        TelegramOutboundEffectType::OwnerCompensationNotification->value,
        TelegramOutboundEffectType::BalanceAdjustmentNotice->value,
        TelegramOutboundEffectType::BalanceOperationsAlert->value,
    ];

    public function __construct(
        private readonly BalanceRestoreGateReader $reader,
        private readonly UsdtTopUpSettlement $settlement,
        private readonly AuditLogger $audit,
    ) {}

    public function open(CarbonInterface $windowStart, CarbonInterface $windowEnd, mixed $reason, mixed $actor): BalanceRestoreGate
    {
        $reason = $this->boundedText('reason', $reason, 191);
        $actor = $this->boundedText('actor', $actor, 191);

        if ($windowEnd->lessThan($windowStart)) {
            throw new InvalidArgumentException('Restore window end must not precede the window start.');
        }

        $gate = BalanceRestoreGate::current();

        return DB::transaction(function () use ($gate, $windowStart, $windowEnd, $reason, $actor): BalanceRestoreGate {
            $locked = BalanceRestoreGate::query()->whereKey($gate->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isOpen()) {
                throw new InvalidArgumentException('A balance restore gate is already open; close it before opening another window.');
            }

            $locked->forceFill([
                'state' => 'open',
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'reason' => $reason,
                'opened_by' => $actor,
                'opened_at' => now(),
                'replay_completed_at' => null,
                'replay_by' => null,
                'replay_evidence' => null,
                'cleared_at' => null,
                'cleared_by' => null,
                'cleared_evidence' => null,
            ])->save();

            $this->audit->log('usdt_balance.restore_gate.opened', $locked, null, [
                'reason' => $reason,
                'actor' => $actor,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
            ]);

            return $locked;
        }, 3);
    }

    /**
     * @param  array{status: string, requests: int}|null  $scanEvidence
     */
    public function replay(
        int $limit = 25,
        mixed $actor = 'operator',
        ?array $scanEvidence = null,
        bool $dryRun = false,
    ): BalanceRestoreReplayResult {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Restore replay limit must be between 1 and 100.');
        }

        $actor = $this->boundedText('actor', $actor, 191);
        $gate = $this->reader->current();
        if ($gate === null || ! $gate->isOpen()) {
            throw new InvalidArgumentException('Restore replay requires an open balance restore gate.');
        }

        $settlement = $this->replaySettlement($limit);
        $quarantined = $dryRun ? $this->countAmbiguousDeliveries($limit) : $this->quarantineAmbiguousDeliveries($limit);

        $scanStatus = $scanEvidence['status'] ?? 'skipped';
        $scanRequests = (int) ($scanEvidence['requests'] ?? 0);
        if (! in_array($scanStatus, ['skipped', 'completed', 'failed'], true)) {
            $scanStatus = 'failed';
        }

        $evidence = [
            'window_start' => $gate->window_start?->toIso8601String(),
            'window_end' => $gate->window_end?->toIso8601String(),
            'settlement' => [
                'checked' => $settlement['checked'],
                'settled' => $settlement['settled'],
                'review' => $settlement['review'],
                'unchanged' => $settlement['unchanged'],
                'failed' => $settlement['failed'],
            ],
            'outbound' => [
                'quarantined' => $quarantined,
                'remaining' => $this->countAmbiguousDeliveries(),
            ],
            'scan' => ['status' => $scanStatus, 'requests' => $scanRequests],
            'dry_run' => $dryRun,
        ];

        $completedAt = null;
        if (! $dryRun) {
            DB::transaction(function () use ($gate, $actor, $evidence, &$completedAt): void {
                $locked = BalanceRestoreGate::query()->whereKey($gate->getKey())->lockForUpdate()->firstOrFail();

                // Re-assert against the locked row: a concurrent close/reopen
                // must never receive evidence recorded for another window.
                if (! $locked->isOpen() || $locked->opened_at?->toIso8601String() !== $gate->opened_at?->toIso8601String()) {
                    throw new InvalidArgumentException('The balance restore window changed while the replay was running; re-run the replay for the current window.');
                }

                $completedAt = now()->toIso8601String();
                $locked->forceFill([
                    'replay_completed_at' => now(),
                    'replay_by' => $actor,
                    'replay_evidence' => $evidence,
                ])->save();

                $this->audit->log('usdt_balance.restore_gate.replayed', $locked, null, [
                    'actor' => $actor,
                ] + $evidence);
            }, 3);
        }

        return new BalanceRestoreReplayResult(
            settlementChecked: $settlement['checked'],
            settlementSettled: $settlement['settled'],
            settlementReview: $settlement['review'],
            settlementUnchanged: $settlement['unchanged'],
            settlementFailed: $settlement['failed'],
            outboundQuarantined: $quarantined,
            dryRun: $dryRun,
            scanStatus: $scanStatus,
            scanRequests: $scanRequests,
            replayCompletedAt: $completedAt,
        );
    }

    public function close(mixed $actor = 'operator'): BalanceRestoreGate
    {
        $actor = $this->boundedText('actor', $actor, 191);

        // Every reopen condition is evaluated while holding the gate row lock,
        // so a concurrent close/reopen of another window can never be cleared
        // by evidence that belongs to the window that was validated.
        return DB::transaction(function () use ($actor): BalanceRestoreGate {
            $locked = BalanceRestoreGate::query()->whereKey(BalanceRestoreGate::SINGLETON_ID)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException('No balance restore gate is open.');
            }

            if ($locked->replay_completed_at === null) {
                throw new InvalidArgumentException('Restore cannot reopen before the external evidence replay completed.');
            }

            $evidence = $locked->replay_evidence ?? [];
            if ((int) ($evidence['settlement']['failed'] ?? 0) > 0) {
                throw new InvalidArgumentException('Restore cannot reopen while the evidence replay reported failed inspections.');
            }

            if (($evidence['scan']['status'] ?? 'skipped') === 'failed') {
                throw new InvalidArgumentException('Restore cannot reopen while the receiver evidence scan failed.');
            }

            // A bounded replay may leave ambiguous deliveries behind; every
            // non-terminal balance delivery must be quarantined first, or the
            // minute sweeps would deliver messages that may already have gone
            // out inside the lost window.
            $remaining = $this->countAmbiguousDeliveries();
            if ($remaining > 0) {
                throw new InvalidArgumentException(sprintf(
                    'Restore cannot reopen: %d balance delivery effect(s) are still unquarantined; re-run usdt-balance:restore-replay until none remain.',
                    $remaining,
                ));
            }

            $run = UsdtBalanceReconciliationRun::query()
                ->where('scope', 'full')
                ->where('checked_at', '>=', $locked->replay_completed_at)
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->first();

            if ($run === null) {
                throw new InvalidArgumentException('Restore cannot reopen: run usdt-balance:reconcile after the replay.');
            }

            // "full" identifies the Customer scope, not pagination coverage.
            // Only a scan from the beginning that exhausted all three facets
            // can prove the restored population was checked. Missing legacy
            // pagination evidence is not proof of completion.
            $coverage = $run->findings ?? [];
            if ((int) $run->cursor_after !== 0
                || (int) $run->receipt_cursor_after !== 0
                || (int) $run->credit_cursor_after !== 0
                || ($coverage['accounts']['has_more'] ?? null) !== false
                || ($coverage['receipts']['receipts_has_more'] ?? null) !== false
                || ($coverage['receipts']['credits_have_more'] ?? null) !== false) {
                throw new InvalidArgumentException('Restore cannot reopen: reconciliation coverage is incomplete; run usdt-balance:reconcile --complete after the replay.');
            }

            if ($run->verdict->value !== 'consistent'
                || (int) $run->account_finding_count !== 0
                || (int) $run->receipt_finding_count !== 0
                || $this->unexplainedMicro($run) !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'Restore cannot reopen: reconciliation run %d still reports unexplained drift.',
                    (int) $run->id,
                ));
            }

            $locked->forceFill([
                'state' => 'cleared',
                'cleared_at' => now(),
                'cleared_by' => $actor,
                'cleared_evidence' => [
                    'replay_completed_at' => $locked->replay_completed_at?->toIso8601String(),
                    'reconciliation_run_id' => (int) $run->id,
                    'reconciliation_checked_at' => $run->checked_at?->toIso8601String(),
                    'reconciliation_verdict' => $run->verdict->value,
                    'account_finding_count' => (int) $run->account_finding_count,
                    'receipt_finding_count' => (int) $run->receipt_finding_count,
                    'unexplained_micro' => $this->unexplainedMicro($run),
                    'unquarantined_deliveries' => 0,
                ],
            ])->save();

            $this->audit->log('usdt_balance.restore_gate.cleared', $locked, null, [
                'actor' => $actor,
                'reconciliation_run_id' => (int) $run->id,
            ]);

            return $locked;
        }, 3);
    }

    /**
     * @return array{checked: int, settled: int, review: int, unchanged: int, failed: int}
     */
    private function replaySettlement(int $limit): array
    {
        $ids = DB::table('customer_usdt_top_up_settlement_work')
            ->whereNotIn('state', self::TERMINAL_SETTLEMENT_STATES)
            ->orderBy('next_check_at')
            ->orderBy('top_up_id')
            ->limit($limit)
            ->pluck('top_up_id');

        $counts = ['checked' => 0, 'settled' => 0, 'review' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $counts['checked']++;
            try {
                $this->settlement->check((string) $id);
            } catch (Throwable) {
                // A failed inspection is unresolved evidence: keep the target's
                // durable obligation and refuse to reopen until it is replayed.
                $counts['failed']++;

                continue;
            }

            $state = DB::table('customer_usdt_top_up_settlement_work')->where('top_up_id', $id)->value('state');
            if ($state === 'settled' || $state === 'externally_refunded' || $state === 'resolved_by_compensation') {
                $counts['settled']++;
            } elseif ($state === 'manual_review') {
                $counts['review']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    private function quarantineAmbiguousDeliveries(int $limit): int
    {
        $ids = TelegramOutboundEffect::query()
            ->whereIn('effect_type', self::AMBIGUOUS_DELIVERY_FAMILIES)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Sending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            TelegramOutboundEffect::query()->whereKey($id)->update([
                'state' => TelegramOutboundEffectState::Unknown->value,
                'claim_token' => null,
                'lease_until' => null,
                'next_attempt_at' => null,
                'unknown_at' => now(),
                'last_error_code' => 'restore_ambiguous_delivery',
                'last_error' => null,
                'updated_at' => now(),
            ]);
        }

        return $ids->count();
    }

    /**
     * Non-terminal balance deliveries that the restored database cannot prove
     * delivered. Counted without a bound: the reopen gate must see every one.
     */
    private function countAmbiguousDeliveries(?int $limit = null): int
    {
        $query = TelegramOutboundEffect::query()
            ->whereIn('effect_type', self::AMBIGUOUS_DELIVERY_FAMILIES)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Sending->value,
                TelegramOutboundEffectState::Failed->value,
            ]);

        if ($limit !== null) {
            $query->orderBy('id')->limit($limit);
        }

        return $query->count();
    }

    private function unexplainedMicro(UsdtBalanceReconciliationRun $run): int
    {
        $findings = $run->findings;
        $unexplained = is_array($findings)
            ? ($findings['treasury']['unexplained_micro'] ?? null)
            : null;

        if ($unexplained === null || $unexplained === '') {
            return 0;
        }

        return is_numeric($unexplained) ? (int) $unexplained : 1;
    }

    private function boundedText(string $field, mixed $value, int $limit): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $limit || preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new InvalidArgumentException("The restore gate {$field} must be non-empty printable ASCII of at most {$limit} characters.");
        }

        return $value;
    }
}
