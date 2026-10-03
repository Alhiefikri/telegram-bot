<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierWebhookEvent;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyClaimService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Signed events wake authoritative reads; webhook payloads never authorize refunds. */
final class BuyMailWebhookService
{
    public function process(int $id): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new SupplierUnavailable('financial_lock');
        }
        $event = DB::transaction(function () use ($id): ?SupplierWebhookEvent {
            $event = SupplierWebhookEvent::query()->whereKey($id)->lockForUpdate()->first();
            if ($event === null || $event->state === 'processed' || $event->lease_expires_at?->isFuture() || $event->next_attempt_at?->isFuture()) {
                return null;
            }
            $event->update(['state' => 'processing', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(3), 'attempts' => $event->attempts + 1]);

            return $event;
        }, 5);
        if ($event === null) {
            return;
        }
        $success = false;
        $error = null;
        try {
            if ($event->event_type === 'ping') {
                $success = true;
            } elseif (str_starts_with($event->event_type, 'refund.')) {
                $unit = SupplierAccountUnit::query()->where('supplier_id', $event->supplier_id)->where('external_id', $event->resource_id)->first();
                $claim = $unit === null ? null : WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->first();
                if ($claim !== null) {
                    $success = app(WarrantyClaimService::class)->refreshNow($claim->id);
                } else {
                    $error = 'claim_not_found';
                }
            } elseif ($event->event_type === 'deposit.paid') {
                app(SupplierDepositService::class)->reconcileKnownDeposit(Supplier::findOrFail($event->supplier_id), $event->resource_id);
                $success = true;
            }
        } catch (Throwable) {
            $error = 'authoritative_read_unavailable';
        }
        DB::transaction(function () use ($id, $event, $success, $error): void {
            $locked = SupplierWebhookEvent::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($locked->claim_token !== $event->claim_token) {
                return;
            }
            $locked->update([
                'state' => $success ? 'processed' : 'waiting', 'claim_token' => null, 'lease_expires_at' => null,
                'processed_at' => $success ? now() : null, 'last_error' => $success ? null : ($error ?? 'reconciliation_pending'),
                'next_attempt_at' => $success ? null : now()->addMinutes(min(30, max(1, $locked->attempts))),
            ]);
        }, 5);
    }
}
