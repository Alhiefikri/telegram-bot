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

    public function reconcile(int $fulfillmentId, User $actor, string $timezone, ?string $ip = null, ?int $expectedPurchaseId = null): SupplierOrderHistoryReconciliation
    {
        return $this->scan($fulfillmentId, $actor, $timezone, $ip, $expectedPurchaseId);
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

    private function scan(int $fulfillmentId, ?User $actor, string $timezone, ?string $ip = null, ?int $expectedPurchaseId = null): SupplierOrderHistoryReconciliation
    {
        $request = DB::transaction(function () use ($fulfillmentId, $actor, $timezone, $expectedPurchaseId): array {
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
            if ($purchase === null || $purchase->committed_at !== null || $purchase->supplier_order_id !== null
                || $purchase->dispatched_at === null || $purchase->lease_expires_at?->isFuture()
                || ! in_array($purchase->state, ['ambiguous', 'review'], true)) {
                throw new InvalidOrderTransition('Purchase ini tidak tersedia untuk rekonsiliasi riwayat order.');
            }
            $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
            if ($supplier->provider !== 'toolorax') {
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
                'history_timezone' => $timezone,
            ]);

            return [$purchase->id, $purchase->dispatched_at, $supplier, $reconciliation];
        }, attempts: 5);

        [$purchaseId, $dispatchAt, $supplier, $reconciliation] = $request;
        try {
            $snapshot = $this->client->orderHistory($supplier, $timezone);
            $observedAt = CarbonImmutable::now();
            [$outcome, $errorCode, $coverageStart, $coverageEnd, $candidateIds] = $this->classify($snapshot, $dispatchAt, $reconciliation->requested_at, $observedAt, $purchaseId);
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
            ], $actor, $ip);

            return $locked->fresh();
        }, attempts: 5);
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
