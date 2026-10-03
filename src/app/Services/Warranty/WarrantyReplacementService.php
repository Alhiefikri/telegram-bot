<?php

namespace App\Services\Warranty;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\AuditLogger;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Suppliers\BuyMailClient;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierOrderResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** A replacement spends operator funds; it never debits the Customer again. */
final class WarrantyReplacementService
{
    public function __construct(private readonly WarrantyClaimService $claims, private readonly BuyMailClient $client, private readonly SupplierAvailability $availability, private readonly AuditLogger $audit) {}

    public function recoverOrder(User $actor, int $claimId, string $orderId): void
    {
        if ($actor->role?->canManageStoreOperations() !== true || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $orderId) !== 1) {
            throw new InvalidOrderTransition('Replacement recovery requires an exact order identity.');
        }
        $this->process($claimId, $orderId);
    }

    public function process(int $claimId, ?string $recoveryOrderId = null): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('Replacement transport cannot run under financial locks.');
        }
        $request = DB::transaction(function () use ($claimId, $recoveryOrderId): ?array {
            $initial = WarrantyClaim::find($claimId);
            if ($initial === null) {
                return null;
            }
            [$claim, $order, $unit, , $supplier] = $this->claims->lockForSettlement($initial);
            if ($claim->settlement_choice !== 'replacement' || ! in_array($claim->settlement_state, ['replacement_pending', 'replacement_sending', 'replacement_unknown'], true)
                || ($recoveryOrderId === null && $claim->replacement_next_attempt_at?->isFuture()) || $order->payment_status !== PaymentStatus::Paid
                || ! Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Paid)->whereNotNull('paid_at')->exists()
                || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
                return null;
            }
            $sku = SupplierCatalogItem::query()->whereKey($unit->purchase->supplier_catalog_item_id)->lockForUpdate()->firstOrFail();
            $purchase = SupplierPurchase::query()->where('warranty_claim_id', $claim->id)->lockForUpdate()->first();
            if ($purchase?->lease_expires_at?->isFuture()) {
                return null;
            }
            $recover = $purchase?->dispatched_at !== null;
            if ($recoveryOrderId !== null && (! $recover || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $recoveryOrderId))) {
                throw new InvalidOrderTransition('Replacement recovery does not match its original acquisition.');
            }
            if ($purchase === null) {
                $cost = $sku->unit_price_micros;
                if ($claim->state !== 'approved' || ! $supplier->is_active || $supplier->purchase_paused_until?->isFuture() || ! $supplier->currency_confirmed
                    || ! $supplier->sync_expires_at?->isFuture() || ! $sku->orderable || $sku->requires_input
                    || $cost === null || SupplierMoney::costIdr($cost, 1) > $unit->cost_idr
                    || $supplier->balance_micros === null || $supplier->balance_micros - $supplier->reserved_balance_micros < $cost
                    || ($sku->stock ?? 0) - $sku->reserved_quantity < 1) {
                    $claim->update(['replacement_next_attempt_at' => now()->addMinute(), 'replacement_last_error' => 'replacement_source_unavailable']);

                    return null;
                }
                $purchase = SupplierPurchase::forceCreate([
                    'supplier_fulfillment_id' => $unit->purchase->supplier_fulfillment_id, 'supplier_id' => $supplier->id,
                    'supplier_catalog_item_id' => $sku->id, 'warranty_claim_id' => $claim->id,
                    'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => $cost,
                    'exchange_rate_idr' => 1, 'state' => 'reserved', 'warranty_hold_active' => true, 'warranty_reserved_cost_micros' => $cost,
                    'credential_fingerprint' => hash('sha256', $supplier->api_key),
                ]);
                $supplier->forceFill(['reserved_balance_micros' => $supplier->reserved_balance_micros + $cost, 'version' => $supplier->version + 1])->save();
                $sku->increment('reserved_quantity');
            }
            if ($recover) {
                $purchase->replay_count++;
            }
            $token = (string) Str::uuid();
            $purchase->forceFill(['state' => 'sending', 'dispatched_at' => $purchase->dispatched_at ?? now(), 'claim_token' => $token, 'lease_expires_at' => now()->addMinutes(5)])->save();
            $claim->update(['settlement_state' => 'replacement_sending', 'replacement_next_attempt_at' => now()->addMinutes(5)]);

            return [$purchase, $supplier, $unit, $token, $recover];
        }, 5);
        if ($request === null) {
            return;
        }
        [$purchase, $supplier, $unit, $token, $recover] = $request;
        try {
            $result = $recoveryOrderId !== null ? $this->client->order($supplier, $recoveryOrderId, $purchase->reference) : ($recover
                ? ($purchase->supplier_order_id !== null ? $this->client->order($supplier, $purchase->supplier_order_id, $purchase->reference) : $this->client->recoverPurchase($supplier, 1, $purchase->reference, $purchase->dispatched_at,
                    allowReplay: $purchase->credential_fingerprint !== null && hash_equals($purchase->credential_fingerprint, hash('sha256', $supplier->api_key))))
                : $this->client->purchase($supplier, 1, $purchase->reference));
        } catch (Throwable) {
            $result = new SupplierOrderResult('ambiguous', errorCode: 'replacement_transport_unavailable');
        }
        DB::transaction(function () use ($claimId, $purchase, $token, $result, $recover): void {
            [$claim, $order, $original, , $supplier] = $this->claims->lockForSettlement(WarrantyClaim::findOrFail($claimId));
            $sku = SupplierCatalogItem::query()->whereKey($purchase->supplier_catalog_item_id)->lockForUpdate()->firstOrFail();
            $attempt = SupplierPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($attempt->claim_token !== $token) {
                return;
            }
            $attempt->forceFill(['claim_token' => null, 'lease_expires_at' => null, 'last_error' => $result->errorCode]);
            $provenRejection = ! $recover && $result->outcome === 'rejected' && ! $result->identityTrusted;
            $provenAcquisition = $result->identityTrusted && $result->deliveryTrusted && $result->requestedQuantity === 1
                && $result->orderId !== null && $result->quantity !== null && $result->costMicros !== null
                && count($result->accountUnits) === $result->quantity;
            if (! $provenRejection && ! $provenAcquisition) {
                $attempt->forceFill(['state' => 'ambiguous', 'next_attempt_at' => now()->addMinute()])->save();
                $claim->update(['settlement_state' => 'replacement_unknown', 'replacement_next_attempt_at' => now()->addMinute(), 'replacement_last_error' => $result->errorCode ?? 'replacement_outcome_unknown']);

                return;
            }
            if ($provenAcquisition && SupplierPurchase::query()->where('supplier_id', $supplier->id)->where('supplier_order_id', $result->orderId)->whereKeyNot($attempt->id)->exists()) {
                $attempt->forceFill(['state' => 'review', 'last_error' => 'order_identity_mismatch'])->save();
                $claim->update(['settlement_state' => 'replacement_unknown', 'replacement_next_attempt_at' => null, 'replacement_last_error' => 'order_identity_mismatch']);

                return;
            }
            if ($attempt->warranty_hold_active) {
                if ($supplier->reserved_balance_micros < $attempt->warranty_reserved_cost_micros || $sku->reserved_quantity < 1) {
                    throw new InvalidOrderTransition('Replacement commitment invariant violated.');
                }
                $supplier->forceFill(['reserved_balance_micros' => $supplier->reserved_balance_micros - $attempt->warranty_reserved_cost_micros, 'version' => $supplier->version + 1])->save();
                $sku->decrement('reserved_quantity');
                $attempt->warranty_hold_active = false;
            }
            if ($provenRejection) {
                $attempt->state = 'rejected';
                $claim->update(['settlement_state' => 'replacement_failed', 'replacement_next_attempt_at' => null, 'replacement_last_error' => $result->errorCode]);
                app(WarrantyNotificationPipeline::class)->record($claim);
                $attempt->save();

                return;
            }
            $attempt->forceFill([
                'supplier_order_id' => $result->orderId, 'observed_quantity' => $result->quantity,
                'actual_cost_micros' => $result->costMicros, 'delivery_payload' => $result->deliveries,
                'trusted_delivery_payload' => $result->deliveries, 'account_units' => $result->accountUnits,
                'state' => $result->quantity === 1 ? 'delivered' : 'settled', 'next_attempt_at' => null,
            ]);
            if ($attempt->committed_at === null) {
                $supplier->forceFill(['balance_micros' => max(0, $supplier->balance_micros - $result->costMicros), 'version' => $supplier->version + 1])->save();
                $sku->update(['stock' => max(0, $sku->stock - $result->quantity)]);
                $attempt->committed_at = now();
            }
            $attempt->save();
            if ($result->quantity !== 1) {
                $claim->update(['settlement_state' => 'replacement_failed', 'replacement_next_attempt_at' => null, 'replacement_last_error' => 'replacement_not_delivered']);
                app(WarrantyNotificationPipeline::class)->record($claim);

                return;
            }
            $newUnit = $result->accountUnits[0];
            if (SupplierMoney::costIdr($result->costMicros, 1) > $original->cost_idr
                || SupplierAccountUnit::query()->where('supplier_id', $supplier->id)->where('external_id', $newUnit['id'])->exists()
                || ! in_array($newUnit['status'], ['active', 'completed'], true)) {
                $claim->update(['settlement_state' => 'replacement_review', 'replacement_next_attempt_at' => null, 'replacement_last_error' => 'replacement_evidence_requires_review']);

                return;
            }
            $this->deliverAcquired($claim, $original, $attempt, $newUnit);
        }, 5);
    }

    public function approveAcquired(User $actor, int $claimId, int $acknowledgedCostIdr): void
    {
        if ($actor->role?->canManageStoreOperations() !== true || $acknowledgedCostIdr < 1) {
            throw new InvalidOrderTransition('Replacement approval requires an operator and exact acquired cost.');
        }
        DB::transaction(function () use ($actor, $claimId, $acknowledgedCostIdr): void {
            [$claim, $order, $original] = $this->claims->lockForSettlement(WarrantyClaim::findOrFail($claimId));
            $attempt = SupplierPurchase::query()->where('warranty_claim_id', $claim->id)->lockForUpdate()->firstOrFail();
            $units = $attempt->account_units ?? [];
            $deliveries = $attempt->trusted_delivery_payload ?? [];
            if ($attempt->committed_at === null || $attempt->actual_cost_micros === null || $attempt->quantity !== 1
                || $attempt->observed_quantity !== 1 || $attempt->supplier_order_id === null || $attempt->warranty_hold_active
                || $attempt->actual_cost_micros !== SupplierMoney::micros($acknowledgedCostIdr)
                || count($units) !== 1 || count($deliveries) !== 1 || $units[0]['price'] !== $acknowledgedCostIdr
                || $deliveries[0] !== $units[0]['email'].' | '.$units[0]['password']
                || (int) $attempt->supplier_id !== (int) $original->supplier_id) {
                throw new InvalidOrderTransition('The replacement has no complete matching acquisition evidence.');
            }
            if ($claim->settlement_state === 'replaced') {
                return;
            }
            if ($claim->settlement_choice !== 'replacement' || $claim->settlement_state !== 'replacement_review'
                || $claim->verified_at === null || $order->payment_status !== PaymentStatus::Paid
                || ! Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Paid)->whereNotNull('paid_at')->exists()
                || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                || ! in_array($units[0]['status'], ['active', 'completed'], true)
                || SupplierAccountUnit::where('supplier_id', $attempt->supplier_id)->where('external_id', $units[0]['id'])->exists()) {
                throw new InvalidOrderTransition('This replacement cannot be approved for this paid Order.');
            }
            $this->audit->log('warranty.acquired_replacement_approved', $claim, new: ['purchase_id' => $attempt->id, 'actual_cost_idr' => $acknowledgedCostIdr], user: $actor);
            $this->deliverAcquired($claim, $original, $attempt, $units[0]);
        }, 5);
    }

    private function deliverAcquired(WarrantyClaim $claim, SupplierAccountUnit $original, SupplierPurchase $attempt, array $newUnit): void
    {
        $stock = ProductStockItem::create([
            'product_id' => $original->stockItem->product_id, 'order_item_id' => $original->order_item_id,
            'supplier_purchase_id' => $attempt->id, 'supplier_unit_index' => 1,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues($newUnit['email'], $newUnit['password'], CredentialAccessMethod::None)->toArray(),
        ]);
        SupplierAccountUnit::create([
            'public_id' => (string) Str::uuid(), 'supplier_id' => $attempt->supplier_id, 'supplier_purchase_id' => $attempt->id,
            'product_stock_item_id' => $stock->id, 'order_id' => $original->order_id, 'order_item_id' => $original->order_item_id,
            'external_id' => $newUnit['id'], 'provider_state' => $newUnit['status'], 'refund_deadline' => CarbonImmutable::parse($newUnit['refund_deadline'])->timezone(config('app.timezone')),
            'cost_idr' => $newUnit['price'], 'retail_amount' => $original->retail_amount,
            'retail_currency' => $original->retail_currency, 'unit_number' => $original->unit_number,
            'replaces_unit_id' => $original->id,
        ]);
        $claim->update(['settlement_state' => 'replaced', 'replacement_next_attempt_at' => null, 'replacement_last_error' => null]);
        app(WarrantyNotificationPipeline::class)->record($claim);
        $this->audit->log('warranty.replacement_delivered', $claim, new: ['purchase_id' => $attempt->id, 'unit_id' => $original->id]);
        DB::afterCommit(fn () => $this->availability->refreshProducts([$original->stockItem->product_id]));
    }
}
