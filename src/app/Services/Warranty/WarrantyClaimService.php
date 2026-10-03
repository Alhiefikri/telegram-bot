<?php

namespace App\Services\Warranty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessWarrantyClaim;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\AuditLogger;
use App\Services\Suppliers\BuyMailClient;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Owns claims; supplier decisions alone never move Customer money. */
final class WarrantyClaimService
{
    public function __construct(private readonly BuyMailClient $client, private readonly AuditLogger $audit) {}

    public function submit(Customer $customer, string $unitPublicId, RefundProof $proof, string $note): WarrantyClaim
    {
        if (mb_strlen($note) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
            throw new InvalidOrderTransition('Invalid warranty claim.');
        }
        $initial = SupplierAccountUnit::query()->where('public_id', $unitPublicId)->firstOrFail();
        if ((int) $initial->order->customer_id !== (int) $customer->id) {
            throw new InvalidOrderTransition('Warranty account is unavailable.');
        }
        $path = 'warranty-proofs/'.Str::uuid().'.enc';
        if (! Storage::disk('local')->put($path, Crypt::encryptString($proof->bytes), ['visibility' => 'private'])) {
            throw new InvalidOrderTransition('Warranty proof could not be saved.');
        }
        $retained = false;
        try {
            $claim = DB::transaction(function () use ($customer, $initial, $path, $proof, $note, &$retained): WarrantyClaim {
                $retained = false;
                [$order, $unit, $stock, $supplier] = $this->lockUnit($initial);
                $existing = WarrantyClaim::query()->where('supplier_account_unit_id', $unit->id)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
                $paid = Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Paid)->whereNotNull('paid_at')->exists();
                if ((int) $order->customer_id !== (int) $customer->id || ! $paid || $order->payment_status !== PaymentStatus::Paid
                    || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                    || $order->hasOpenLatePaymentReview() || $stock->retired_at !== null
                    || $unit->provider_state !== 'active' || ! $unit->refund_deadline->isFuture()
                    || $unit->confirmation_state !== null
                    || $supplier->provider !== 'buymail') {
                    throw new InvalidOrderTransition('Warranty claim is unavailable or its deadline has passed.');
                }
                $claim = WarrantyClaim::create([
                    'public_id' => (string) Str::uuid(), 'supplier_account_unit_id' => $unit->id,
                    'customer_id' => $customer->id, 'order_id' => $order->id,
                    'proof_path' => $path, 'proof_digest' => hash('sha256', $proof->bytes), 'note' => $note,
                    'state' => 'received', 'refund_deadline' => $unit->refund_deadline,
                    'next_attempt_at' => now(), 'settlement_state' => 'awaiting_verification',
                ]);
                $this->audit->log('warranty.claim_received', $claim, new: ['unit_id' => $unit->id, 'order_id' => $order->id]);
                app(WarrantyNotificationPipeline::class)->record($claim);
                DB::afterCommit(fn () => ProcessWarrantyClaim::dispatch($claim->id));
                $retained = true;

                return $claim;
            }, 5);

            return $claim;
        } finally {
            if (! $retained) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function process(int $id, bool $readOnly = false): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('Warranty transport cannot run under financial locks.');
        }
        $request = DB::transaction(function () use ($id, $readOnly): ?array {
            $initial = WarrantyClaim::query()->find($id);
            if ($initial === null) {
                return null;
            }
            [, $unit, , $supplier] = $this->lockUnit($initial->unit);
            $claim = WarrantyClaim::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($claim->lease_expires_at?->isFuture() || (! $readOnly && $claim->next_attempt_at?->isFuture())
                || ! in_array($claim->state, $readOnly
                    ? ['received', 'submitting', 'submission_unknown', 'pending', 'approved', 'review', 'reversed', 'rejected', 'cancelled', 'expired']
                    : ['received', 'submitting', 'submission_unknown', 'pending', 'approved', 'review'], true)) {
                return null;
            }
            $submit = ! $readOnly && $claim->state === 'received' && $claim->dispatched_at === null;
            if ($submit && ! $claim->refund_deadline->isFuture()) {
                $claim->update(['state' => 'expired', 'next_attempt_at' => null, 'last_error' => 'refund_window_expired']);
                app(WarrantyNotificationPipeline::class)->record($claim);

                return null;
            }
            $token = (string) Str::uuid();
            $claim->update([
                'claim_token' => $token, 'lease_expires_at' => now()->addMinutes(3),
                'state' => $submit ? 'submitting' : $claim->state,
                'dispatched_at' => $submit ? now() : $claim->dispatched_at,
            ]);

            return [$claim, $unit, $supplier, $token, $submit];
        }, 5);
        if ($request === null) {
            return false;
        }
        [$claim, $unit, $supplier, $token, $submit] = $request;
        $account = null;
        $error = null;
        try {
            if ($submit) {
                $bytes = Crypt::decryptString(Storage::disk('local')->get($claim->proof_path));
                if (! hash_equals($claim->proof_digest, hash('sha256', $bytes))) {
                    throw new SupplierUnavailable('proof_integrity');
                }
                $account = $this->client->requestRefund($supplier, $unit->external_id, $unit->purchase->supplier_order_id, RefundProof::fromBytes($bytes), $claim->note);
                if ($account['refund_status'] !== 'pending') {
                    $account = $this->client->account($supplier, $unit->external_id, $unit->purchase->supplier_order_id);
                }
            } else {
                $account = $this->client->account($supplier, $unit->external_id, $unit->purchase->supplier_order_id);
            }
        } catch (SupplierUnavailable $exception) {
            $error = $exception->errorCode;
        } catch (Throwable) {
            $error = 'warranty_transport_unavailable';
        }

        return DB::transaction(function () use ($id, $unit, $token, $submit, $readOnly, $account, $error): bool {
            [, $lockedUnit, , $supplier] = $this->lockUnit($unit);
            $claim = WarrantyClaim::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($claim->claim_token !== $token) {
                return false;
            }
            $claim->forceFill(['claim_token' => null, 'lease_expires_at' => null, 'last_error' => $error]);
            if ($account === null || $account['price'] !== $lockedUnit->cost_idr) {
                if ($submit && in_array($error, ['rate_limited', 'invalid_key', 'currency_mismatch', 'financial_lock', 'unauthorized', 'ip_not_allowed'], true)) {
                    // Local pre-dispatch failures and documented authentication/
                    // rate rejections cannot accept a claim. Unknown transport
                    // failures retain their original dispatch and use GET only.
                    $claim->forceFill(['state' => 'received', 'dispatched_at' => null, 'next_attempt_at' => now()->addSeconds(15)])->save();

                    return false;
                }
                $claim->forceFill(['state' => $submit ? 'submission_unknown' : $claim->state, 'next_attempt_at' => now()->addMinute(), 'last_error' => $error ?? 'refund_cost_mismatch'])->save();

                return false;
            }
            $decision = $account['refund_status'];
            // A submitted upload without a claim in GET is not safely retryable:
            // the provider documents no idempotency contract for refund POST.
            $state = $decision ?? match (true) {
                $submit => 'submission_unknown',
                $claim->state === 'received' && $claim->dispatched_at === null => 'received',
                in_array($claim->state, ['approved', 'reversed', 'rejected', 'cancelled', 'expired'], true) => $claim->state,
                default => 'review',
            };
            if ($claim->state === 'approved' && in_array($state, ['pending', 'review', 'submission_unknown'], true)) {
                $state = 'approved';
            }
            $history = $claim->decision_history ?? [];
            if ($decision !== null && $decision !== $claim->provider_decision) {
                $history[] = ['decision' => $decision, 'observed_at' => now()->toIso8601String()];
                $this->audit->log('warranty.supplier_decision', $claim, new: ['unit_id' => $lockedUnit->id, 'decision' => $decision]);
                if (in_array($decision, ['approved', 'reversed'], true)) {
                    $supplier->forceFill(['sync_expires_at' => null, 'version' => $supplier->version + 1])->save();
                }
            }
            $claim->forceFill([
                'state' => $state, 'provider_decision' => $decision ?? $claim->provider_decision,
                'provider_chat_url' => $account['chat_url'], 'decision_history' => array_slice($history, -100),
                'submitted_at' => $decision !== null ? ($claim->submitted_at ?? now()) : $claim->submitted_at,
                'verified_at' => $state === 'approved' ? ($claim->verified_at ?? now()) : $claim->verified_at,
                'reversed_at' => $state === 'reversed' ? ($claim->reversed_at ?? now()) : $claim->reversed_at,
                'settlement_state' => $state === 'approved' && $claim->settlement_state === 'awaiting_verification' ? 'awaiting_choice' : $claim->settlement_state,
                'next_attempt_at' => in_array($state, ['received', 'pending', 'submission_unknown', 'review', 'approved'], true) ? now()->addMinutes($state === 'approved' ? 30 : 1) : null,
            ])->save();
            $lockedUnit->update(['provider_state' => $account['status']]);
            if ($readOnly) {
                $this->audit->log('warranty.supplier_refreshed', $claim, new: [
                    'unit_id' => $lockedUnit->id, 'decision' => $decision, 'state' => $state,
                ]);
            }
            app(WarrantyNotificationPipeline::class)->record($claim);
            // Balance is recovered through profile synchronization, never by
            // adding a webhook credit to an observation that may contain it.

            return true;
        }, 5);
    }

    /** Caller follows Order -> Payment -> supplier/inventory -> unit -> claim. */
    private function lockUnit(SupplierAccountUnit $initial): array
    {
        $order = Order::query()->whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
        Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        app(SupplierFulfillmentService::class)->lockForReview($order);
        $supplier = Supplier::query()->whereKey($initial->supplier_id)->lockForUpdate()->firstOrFail();
        $unit = SupplierAccountUnit::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
        $stock = ProductStockItem::query()->whereKey($unit->product_stock_item_id)->lockForUpdate()->firstOrFail();

        return [$order, $unit, $stock, $supplier];
    }

    public function lockForSettlement(WarrantyClaim $initial): array
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidOrderTransition('Warranty settlement requires a transaction.');
        }
        [$order, $unit, $stock, $supplier] = $this->lockUnit($initial->unit);
        $claim = WarrantyClaim::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();

        return [$claim, $order, $unit, $stock, $supplier];
    }

    public function lockAccountUnit(SupplierAccountUnit $initial): array
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidOrderTransition('Account mutation requires a transaction.');
        }

        return $this->lockUnit($initial);
    }

    public function refreshNow(int $id): bool
    {
        // One leased GET, including terminal observations. Never rewrite state
        // before transport or report success for a skipped/lost lease.
        return $this->process($id, readOnly: true);
    }

    /**
     * An operator may re-dispatch the stored original proof only after an
     * authoritative read proves the supplier holds no refund object for the
     * same account. A refund object observed by GET is never resubmitted.
     */
    public function resubmit(User $actor, int $id): void
    {
        if ($actor->role?->canManageStoreOperations() !== true) {
            throw new InvalidOrderTransition('Warranty resubmission requires an operator.');
        }
        $initial = WarrantyClaim::query()->findOrFail($id);
        if (! in_array($initial->state, ['submission_unknown', 'review'], true) || ! $initial->refund_deadline->isFuture()) {
            throw new InvalidOrderTransition('This claim cannot be resubmitted.');
        }
        $unit = $initial->unit;
        try {
            $account = $this->client->account($unit->supplier, $unit->external_id, $unit->purchase->supplier_order_id);
        } catch (Throwable) {
            throw new InvalidOrderTransition('The supplier account could not be read authoritatively.');
        }
        if ($account['price'] !== $unit->cost_idr || $account['refund_status'] !== null) {
            throw new InvalidOrderTransition('The supplier account already holds a refund record for this claim.');
        }
        DB::transaction(function () use ($actor, $initial, $account): void {
            [$claim, , $lockedUnit] = $this->lockForSettlement($initial);
            if (! in_array($claim->state, ['submission_unknown', 'review'], true) || ! $claim->refund_deadline->isFuture()
                || $claim->lease_expires_at?->isFuture() || $lockedUnit->cost_idr !== $account['price']) {
                throw new InvalidOrderTransition('This claim cannot be resubmitted.');
            }
            $this->audit->log('warranty.claim_resubmitted', $claim, old: [
                'state' => $claim->state, 'dispatched_at' => $claim->dispatched_at?->toIso8601String(), 'last_error' => $claim->last_error,
            ], new: ['state' => 'received', 'dispatched_at' => null, 'authoritative_refund_object' => false], user: $actor);
            $claim->forceFill([
                'state' => 'received', 'claim_token' => null, 'lease_expires_at' => null,
                'dispatched_at' => null, 'last_error' => null, 'next_attempt_at' => now(),
            ])->save();
            DB::afterCommit(fn () => ProcessWarrantyClaim::dispatch($claim->id));
        }, 5);
    }
}
