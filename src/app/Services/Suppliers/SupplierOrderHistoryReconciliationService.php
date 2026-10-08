<?php

namespace App\Services\Suppliers;

use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierFulfillment;
use App\Models\SupplierOrderHistoryReconciliation;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SupplierOrderHistoryReconciliationService
{
    public function __construct(
        private readonly SupplierClient $client,
        private readonly SupplierFulfillmentService $fulfillments,
        private readonly AuditLogger $audit,
    ) {}

    public function reconcile(int $fulfillmentId, User $actor, string $timezone, ?string $ip = null, ?int $expectedPurchaseId = null, ?string $expectedPurchaseVersion = null): SupplierOrderHistoryReconciliation
    {
        return $this->scan($fulfillmentId, $actor, $timezone, $ip, $expectedPurchaseId, $expectedPurchaseVersion);
    }

    /** At most three read-only scans; evidence never automatically releases a hold. */
    public function reconcileAutomatically(int $fulfillmentId): ?SupplierOrderHistoryReconciliation
    {
        if (! SupplierFulfillment::query()->whereKey($fulfillmentId)->where('state', 'review')->exists()) {
            return null;
        }
        try {
            return $this->scan($fulfillmentId, null, 'UTC');
        } catch (InvalidOrderTransition) {
            return null;
        }
    }

    private function scan(int $fulfillmentId, ?User $actor, string $timezone, ?string $ip = null, ?int $expectedPurchaseId = null, ?string $expectedPurchaseVersion = null): SupplierOrderHistoryReconciliation
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('History transport cannot run inside a transaction.');
        }
        $request = DB::transaction(function () use ($fulfillmentId, $actor, $timezone, $expectedPurchaseId, $expectedPurchaseVersion): array {
            $initial = SupplierFulfillment::query()->findOrFail($fulfillmentId);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $this->fulfillments->lockForReview($order);
            $fulfillment = SupplierFulfillment::query()->whereKey($fulfillmentId)->lockForUpdate()->firstOrFail();
            $purchase = $fulfillment->current_purchase_id === null
                ? null
                : SupplierPurchase::query()->whereKey($fulfillment->current_purchase_id)->lockForUpdate()->first();
            if ($expectedPurchaseId !== null && $purchase?->id !== $expectedPurchaseId) {
                throw new InvalidOrderTransition('Purchase berubah sejak form dibuka. Muat ulang bukti.');
            }
            if ($expectedPurchaseVersion !== null && $purchase?->updated_at?->format('Y-m-d H:i:s') !== $expectedPurchaseVersion) {
                throw new InvalidOrderTransition('Purchase version berubah. Muat ulang bukti.');
            }
            if ($purchase === null || $purchase->committed_at !== null || $purchase->supplier_order_id !== null
                || $purchase->dispatched_at === null || $purchase->lease_expires_at?->isFuture()
                || ! in_array($purchase->state, ['ambiguous', 'review'], true)) {
                throw new InvalidOrderTransition('Purchase ini tidak tersedia untuk rekonsiliasi riwayat order.');
            }
            $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
            if (! in_array($supplier->provider, ['toolorax', 'csxstore'], true) || ($actor === null && $supplier->provider !== 'toolorax')) {
                throw new InvalidOrderTransition('Riwayat order terstruktur belum didukung provider ini.');
            }
            $latest = $purchase->historyReconciliations()->latest('id')->first();
            if ($latest?->error_code === 'scan_in_progress' && $latest->requested_at->greaterThan(now()->subMinutes(3))) {
                throw new InvalidOrderTransition('An order-history scan already owns this purchase.');
            }
            if ($actor === null) {
                if ($fulfillment->state !== 'review' || $fulfillment->next_attempt_at?->isFuture()
                    || $purchase->historyReconciliations()->whereNull('actor_user_id')->count() >= 3) {
                    throw new InvalidOrderTransition('Automatic order-history budget is not due.');
                }
                $fulfillment->update(['next_attempt_at' => now()->addMinutes(5)]);
            }

            $requestedAt = now();
            $reconciliation = SupplierOrderHistoryReconciliation::query()->create([
                'supplier_purchase_id' => $purchase->id,
                'actor_user_id' => $actor?->id,
                'outcome' => SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE,
                'error_code' => 'scan_in_progress',
                'requested_limit' => SupplierClient::TOOL_ORAX_ORDER_HISTORY_LIMIT,
                'row_count' => 0,
                'dispatch_at' => $purchase->dispatched_at,
                'requested_at' => $requestedAt,
                'observed_at' => $requestedAt,
                'history_timezone' => $supplier->provider === 'csxstore' ? 'unknown' : $timezone,
                'purchase_version' => $purchase->updated_at->format('Y-m-d H:i:s'),
                'purchase_claim_token' => $purchase->claim_token,
                'coverage_kind' => $supplier->provider === 'csxstore' ? 'first_page' : 'qualified_window',
            ]);

            return [$purchase->id, $purchase->dispatched_at, $supplier, $reconciliation];
        }, attempts: 5);

        [$purchaseId, $dispatchAt, $supplier, $reconciliation] = $request;
        try {
            $snapshot = $this->client->orderHistory($supplier, $timezone);
            $observedAt = CarbonImmutable::now();
            $candidateFacts = [];
            if ($supplier->provider === 'csxstore') {
                $purchase = SupplierPurchase::query()->with('catalogItem')->findOrFail($purchaseId);
                foreach ($snapshot->orders as $row) {
                    if ((string) $row['product_id'] !== (string) $purchase->catalogItem->external_id) {
                        continue;
                    }
                    $row['match_reasons'] = ['sku_matches'];
                    $row['match_reasons'][] = $row['quantity'] === $purchase->quantity ? 'quantity_matches' : 'quantity_differs';
                    $row['match_reasons'][] = $row['total_micros'] === $purchase->expected_cost_micros ? 'expected_cost_matches' : 'expected_cost_differs';
                    $row['limitations'] = ['identity_not_proven', 'timezone_unknown', 'first_page_only', 'exact_get_required'];
                    unset($row['created_at']);
                    $candidateFacts[] = $row;
                }
                $candidateIds = array_column($candidateFacts, 'order_id');
                $outcome = $candidateIds === [] ? SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE : SupplierOrderHistoryReconciliation::OUTCOME_CANDIDATE_ORDERS;
                $errorCode = $snapshot->coverageKind === 'truncated' ? 'history_limit_reached' : 'history_coverage_unverified';
                $coverageStart = $coverageEnd = null;
            } else {
                [$outcome, $errorCode, $coverageStart, $coverageEnd, $candidateIds] = $this->classify($snapshot, $dispatchAt, $reconciliation->requested_at, $observedAt, $purchaseId);
            }
            if ($actor === null && $snapshot->orders !== []) {
                // ShopBot returns offset-free timestamps. UTC is a parsing placeholder,
                // not provider qualification; only an operator can attest its timezone.
                $outcome = SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE;
                $errorCode = 'history_timezone_unverified';
                $coverageStart = $coverageEnd = null;
            }
            $attributes = [
                'outcome' => $outcome,
                'error_code' => $errorCode,
                'requested_limit' => $snapshot->limit,
                'reported_count' => $snapshot->reportedCount,
                'row_count' => count($snapshot->orders),
                'coverage_start' => $coverageStart,
                'coverage_end' => $coverageEnd,
                'history_timezone' => $snapshot->timezone,
                'response_sha256' => $snapshot->responseSha256,
                'candidate_order_ids' => $candidateIds,
                'candidate_facts' => $candidateFacts,
                'coverage_kind' => $snapshot->coverageKind,
                'observed_at' => $observedAt,
            ];
        } catch (SupplierUnavailable $exception) {
            $attributes = [
                'outcome' => SupplierOrderHistoryReconciliation::OUTCOME_UNAVAILABLE,
                'error_code' => $exception->errorCode,
                'observed_at' => now(),
            ];
        }

        return DB::transaction(function () use ($fulfillmentId, $actor, $ip, $purchaseId, $reconciliation, $attributes): SupplierOrderHistoryReconciliation {
            $initial = SupplierFulfillment::query()->findOrFail($fulfillmentId);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $this->fulfillments->lockForReview($order);
            $purchase = SupplierPurchase::query()->whereKey($purchaseId)->lockForUpdate()->firstOrFail();
            $locked = SupplierOrderHistoryReconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            $fulfillment = SupplierFulfillment::query()->findOrFail($fulfillmentId);
            if ($fulfillment->current_purchase_id !== $purchaseId
                || $purchase->updated_at->format('Y-m-d H:i:s') !== $locked->purchase_version
                || $purchase->claim_token !== $locked->purchase_claim_token
                || $purchase->committed_at !== null || $purchase->supplier_order_id !== null
                || $purchase->lease_expires_at?->isFuture()
                || ! in_array($purchase->state, ['ambiguous', 'review'], true)
                || (int) $purchase->historyReconciliations()->max('id') !== (int) $locked->id) {
                $attributes['outcome'] = SupplierOrderHistoryReconciliation::OUTCOME_INVALID;
                $attributes['error_code'] = 'stale_purchase_scan';
                $attributes['candidate_facts'] = [];
                $attributes['candidate_order_ids'] = [];
            }
            $locked->forceFill($attributes)->save();
            $fulfillment = SupplierFulfillment::query()->findOrFail($fulfillmentId);
            if ($actor === null && $fulfillment->current_purchase_id === $purchaseId && $fulfillment->state === 'review') {
                $finished = $purchase->historyReconciliations()->whereNull('actor_user_id')->count() >= 3;
                $fulfillment->update(['next_attempt_at' => $finished ? null : now()->addMinutes(5)]);
            }
            $this->audit->log('supplier_purchase.order_history_reconciled', $purchase, null, [
                'reconciliation_id' => $locked->id,
                'outcome' => $locked->outcome,
                'error_code' => $locked->error_code,
                'row_count' => $locked->row_count,
                'reported_count' => $locked->reported_count,
                'coverage_start' => $locked->coverage_start?->toIso8601String(),
                'coverage_end' => $locked->coverage_end?->toIso8601String(),
                'dispatch_at' => $locked->dispatch_at->toIso8601String(),
                'history_timezone' => $locked->history_timezone,
                'candidate_count' => count($locked->candidate_order_ids ?? []),
                'coverage_kind' => $locked->coverage_kind,
            ], $actor, $ip);

            return $locked->fresh();
        }, attempts: 5);
    }

    /** Explicit operator selection supplies captured economics to the sole recovery writer. */
    public function confirmCandidate(int $fulfillmentId, User $actor, int $historyId, string $orderId, string $note, ?string $ip = null, ?int $expectedPurchaseId = null, ?string $expectedPurchaseVersion = null): SupplierPurchase
    {
        $scan = SupplierOrderHistoryReconciliation::query()->findOrFail($historyId);
        $candidate = collect($scan->candidate_facts ?? [])->firstWhere('order_id', $orderId);
        if ($candidate === null || $expectedPurchaseId === null || $expectedPurchaseVersion === null) {
            throw new InvalidOrderTransition('Kandidat atau identity purchase tidak valid.');
        }

        return $this->fulfillments->confirmDebit(
            $fulfillmentId, $actor, $orderId, $candidate['quantity'], $candidate['total_micros'],
            $note, $ip, $expectedPurchaseId, $historyId, $expectedPurchaseVersion,
        );
    }

    /** Called under existing recovery locks, before the network claim or committed replay. */
    public function assertCandidate(SupplierPurchase $purchase, int $historyId, string $orderId, int $quantity, int $costMicros, ?string $version, bool $replay = false): void
    {
        $scan = SupplierOrderHistoryReconciliation::query()->whereKey($historyId)->lockForUpdate()->first();
        $candidate = collect($scan?->candidate_facts ?? [])->firstWhere('order_id', $orderId);
        if ($scan === null || $scan->supplier_purchase_id !== $purchase->id
            || $purchase->supplier->provider !== 'csxstore'
            || (int) $purchase->historyReconciliations()->max('id') !== (int) $scan->id
            || $scan->outcome !== SupplierOrderHistoryReconciliation::OUTCOME_CANDIDATE_ORDERS
            || $scan->observed_at->lessThan(now()->subMinutes(15)) || $scan->observed_at->isFuture()
            || $version !== $scan->purchase_version
            || (! $replay && ($purchase->updated_at->format('Y-m-d H:i:s') !== $scan->purchase_version
                || $purchase->claim_token !== $scan->purchase_claim_token))
            || $candidate === null || $candidate['quantity'] !== $quantity || $candidate['total_micros'] !== $costMicros
            || (string) $candidate['product_id'] !== (string) $purchase->catalogItem->external_id
            || $candidate['currency'] !== $purchase->supplier->currency
            || SupplierPurchase::query()->where('supplier_id', $purchase->supplier_id)
                ->where('supplier_order_id', $orderId)->where('id', '!=', $purchase->id)->exists()) {
            throw new InvalidOrderTransition('Kandidat expired, stale, conflicting, atau tidak cocok. Muat ulang bukti.');
        }
    }

    /** @return array{string,?string,?CarbonImmutable,?CarbonImmutable,list<string>} */
    private function classify(SupplierOrderHistorySnapshot $snapshot, CarbonImmutable $dispatchAt, CarbonImmutable $requestedAt, CarbonImmutable $observedAt, int $purchaseId): array
    {
        $rowCount = count($snapshot->orders);
        $coverageStart = null;
        $coverageEnd = null;
        foreach ($snapshot->orders as $row) {
            $at = $row['created_at']->setTimezone((string) config('app.timezone'));
            $coverageStart = $coverageStart === null || $at->lessThan($coverageStart) ? $at : $coverageStart;
            $coverageEnd = $coverageEnd === null || $at->greaterThan($coverageEnd) ? $at : $coverageEnd;
        }

        if ($requestedAt->lessThan($dispatchAt)) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE, 'dispatch_outside_scan_window', $coverageStart, $coverageEnd, []];
        }
        if ($coverageEnd !== null && $coverageEnd->greaterThan($observedAt)) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE, 'provider_clock_inconsistent', $coverageStart, $coverageEnd, []];
        }
        if ($rowCount >= $snapshot->limit || $snapshot->reportedCount >= $snapshot->limit) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_LIMIT_REACHED, 'history_limit_reached', $coverageStart, $coverageEnd, []];
        }
        if ($snapshot->reportedCount !== $rowCount) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE, 'history_count_mismatch', $coverageStart, $coverageEnd, []];
        }
        $purchase = SupplierPurchase::query()->with('catalogItem')->findOrFail($purchaseId);
        $expectedProductId = (string) $purchase->catalogItem->external_id;
        $candidateIds = [];
        foreach ($snapshot->orders as $row) {
            if ((string) $row['product_id'] === $expectedProductId
                && $row['created_at']->setTimezone((string) config('app.timezone'))->greaterThanOrEqualTo($dispatchAt)) {
                $candidateIds[] = $row['order_id'];
            }
        }
        if ($candidateIds !== []) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_CANDIDATE_ORDERS, 'possible_purchase_after_dispatch', $coverageStart, $coverageEnd, array_values(array_unique($candidateIds))];
        }
        if ($coverageStart === null || $coverageStart->greaterThan($dispatchAt)) {
            return [SupplierOrderHistoryReconciliation::OUTCOME_INCONCLUSIVE, 'dispatch_outside_history_window', $coverageStart, $coverageEnd, []];
        }

        return [SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER, null, $coverageStart, $coverageEnd, []];
    }
}
