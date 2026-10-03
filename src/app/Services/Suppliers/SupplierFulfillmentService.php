<?php

namespace App\Services\Suppliers;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SupplierProvider;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierFulfillment;
use App\Jobs\SendTelegramReceipt;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\WarrantyClaim;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\GuestOrderNotificationService;
use App\Services\InventoryLedger;
use App\Services\OrderLifecycle;
use App\Services\ProductCredentialAllocator;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Warranty\RefundAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SupplierFulfillmentService
{
    public function __construct(private readonly SupplierClient $client, private readonly SupplierPricing $pricing, private readonly SupplierAvailability $availability) {}

    /** Checkout already owns all Product locks. Lock all candidate resources once, globally ordered. */
    public function lockCheckoutResources(Collection $products): void
    {
        $sources = ProductSupplierSource::query()->whereIn('product_id', $products->modelKeys())->get();
        $this->lockResources($sources->pluck('supplier_catalog_item_id')->all());
    }

    public function reserve(OrderItem $item, Product $product, int $externalQuantity, int $retailSubtotal): SupplierFulfillment
    {
        if (DB::transactionLevel() === 0 || $externalQuantity <= 0 || $externalQuantity !== $item->supplier_quantity) {
            throw new InvalidOrderTransition('Invalid external reservation context.');
        }
        $existing = SupplierFulfillment::query()->where('order_item_id', $item->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->first();
        if ($policy === null) {
            throw new InsufficientStock('Ordered product is not available.');
        }
        $externalRetail = intdiv($retailSubtotal, $item->quantity) * $externalQuantity
            + intdiv(($retailSubtotal % $item->quantity) * $externalQuantity, $item->quantity);
        $sources = ProductSupplierSource::query()->where('product_id', $product->id)->where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        [$suppliers, $catalog] = $this->lockResources($sources->pluck('supplier_catalog_item_id')->all());
        $snapshot = [];
        foreach ($sources as $source) {
            $sku = $catalog->get($source->supplier_catalog_item_id);
            $supplier = $sku ? $suppliers->get($sku->supplier_id) : null;
            if ($supplier === null || ! $this->pricing->isEligible($product, $supplier, $sku, $policy, $externalQuantity, $externalRetail)) {
                continue;
            }
            $snapshot[] = ['source_id' => $source->id, 'supplier_id' => $supplier->id, 'catalog_item_id' => $sku->id, 'external_id' => $sku->external_id, 'currency' => $supplier->currency, 'exchange_rate_idr' => $supplier->exchange_rate_idr, 'unit_price_micros' => $sku->unit_price_micros, 'expected_cost_micros' => $this->pricing->costMicros($sku, $externalQuantity)];
        }
        if ($snapshot === []) {
            throw new InsufficientStock('Ordered product is not available.');
        }
        $first = $snapshot[0];
        $fulfillment = SupplierFulfillment::create(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'quantity' => $externalQuantity, 'state' => 'reserved', 'maximum_cost_idr' => $this->pricing->maximumCostIdr($policy, $externalRetail, $externalQuantity), 'sources_snapshot' => $snapshot]);
        $this->hold($fulfillment, $suppliers->get($first['supplier_id']), $catalog->get($first['catalog_item_id']), $first['expected_cost_micros']);
        $this->availability->refreshProducts([$product->id]);

        return $fulfillment;
    }

    public function hasPending(Order $order): bool
    {
        return SupplierFulfillment::query()->where('order_id', $order->id)->where('state', '!=', 'delivered')->exists();
    }

    public function paid(Order $order): void
    {
        SupplierFulfillment::query()->where('order_id', $order->id)->where('state', 'reserved')->update(['state' => 'pending', 'next_attempt_at' => now()]);
        foreach (SupplierFulfillment::query()->where('order_id', $order->id)->where('state', 'pending')->pluck('id') as $id) {
            DB::afterCommit(fn () => ProcessSupplierFulfillment::dispatch($id));
        }
    }

    public function lockForReview(Order $order): void
    {
        $this->lockedOrderResources($order);
    }

    /** Order and Payment locks must precede this operation, including before any refund credit. */
    public function assertRefundable(Order $order): void
    {
        $this->lockedOrderResources($order);
        if (SupplierAccountUnit::query()->where('order_id', $order->id)->whereIn('confirmation_state', ['requested', 'sending', 'unknown'])->exists()) {
            throw new InvalidOrderTransition('Account confirmation must be resolved before a full Order refund.');
        }
        if (WarrantyClaim::query()->where('order_id', $order->id)
            ->whereIn('settlement_state', ['replacement_pending', 'replacement_sending', 'replacement_unknown', 'replacement_review'])->exists()) {
            throw new InvalidOrderTransition('A warranty replacement must be resolved before a full Order refund.');
        }
        if (SupplierPurchase::query()->whereHas('fulfillment', fn ($q) => $q->where('order_id', $order->id))->whereIn('state', ['sending', 'awaiting_delivery', 'ambiguous', 'review'])->exists()) {
            throw new InvalidOrderTransition('Fulfillment is being reviewed. Contact support before refunding.');
        }
    }

    public function release(Order $order): void
    {
        [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
        foreach ($fulfillments as $fulfillment) {
            if (in_array($fulfillment->state, ['delivered', 'cancelled'], true)) {
                continue;
            }
            $purchase = $fulfillment->current_purchase_id ? SupplierPurchase::query()->lockForUpdate()->find($fulfillment->current_purchase_id) : null;
            if ($purchase && in_array($purchase->state, ['sending', 'awaiting_delivery', 'ambiguous', 'review'], true)) {
                throw new InvalidOrderTransition('Fulfillment is being reviewed. Contact support before refunding.');
            }
            $this->unhold($fulfillment, $suppliers, $catalog);
            $fulfillment->update(['state' => 'cancelled', 'next_attempt_at' => null]);
            $purchase?->update(['state' => 'cancelled']);
        }
        $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());
    }

    public function process(int $fulfillmentId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('Fulfillment transport cannot run inside a transaction.');
        }
        $request = DB::transaction(fn () => $this->claim($fulfillmentId), 5);
        if ($request === null) {
            return;
        }
        [$purchase, $supplier, $sku, $token, $read] = $request;
        try {
            if ($supplier->provider === 'buymail' && ! $read && $purchase->replay_count > 0) {
                $result = app(BuyMailClient::class)->recoverPurchase($supplier, $purchase->quantity, $purchase->reference, $purchase->dispatched_at,
                    allowReplay: $purchase->credential_fingerprint !== null && hash_equals($purchase->credential_fingerprint, hash('sha256', $supplier->api_key)));
            } else {
                $result = $read ? $this->client->order($supplier, $purchase->supplier_order_id, $sku->external_id, $purchase->reference) : $this->client->purchase($supplier, $sku, $purchase->quantity, $purchase->reference);
            }
        } catch (Throwable) {
            $result = new SupplierOrderResult('ambiguous', null, null, null, [], 'transport_unavailable');
        }
        DB::transaction(fn () => $this->finalize($fulfillmentId, $purchase->id, $token, $result, $read), 5);
    }

    public function recover(int $max = 100): array
    {
        $ids = SupplierFulfillment::query()->whereIn('state', ['pending', 'purchasing', 'awaiting_delivery'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min($max, 500)))->pluck('id');
        $started = microtime(true);
        $processed = 0;
        foreach ($ids as $id) {
            if (microtime(true) - $started >= 45) {
                break;
            }
            if (SupplierFulfillment::query()->whereKey($id)->whereHas('purchases.supplier', fn ($query) => $query->where('provider', 'buymail'))->exists()
                || SupplierFulfillment::query()->whereKey($id)->whereHas('reservedSupplier', fn ($query) => $query->where('provider', 'buymail'))->exists()) {
                ProcessSupplierFulfillment::dispatch($id);
            } else {
                $this->process($id);
            }
            $processed++;
        }

        return ['processed' => $processed];
    }

    private function claim(int $id): ?array
    {
        $initial = SupplierFulfillment::find($id);
        if ($initial === null) {
            return null;
        }
        $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
        $f = $fulfillments->get($id);
        if ($order->payment_status !== PaymentStatus::Paid || ! $payments->contains(fn (Payment $payment) => $payment->status === PaymentStatus::Paid && $payment->paid_at !== null)
            || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true) || $f === null || ! in_array($f->state, ['pending', 'purchasing', 'awaiting_delivery'], true)) {
            return null;
        }
        $purchase = $f->current_purchase_id ? SupplierPurchase::query()->lockForUpdate()->findOrFail($f->current_purchase_id) : null;
        if ($purchase?->lease_expires_at?->isFuture()) {
            return null;
        }
        if ($purchase?->state === 'sending' && $purchase->supplier_order_id === null) {
            $provider = SupplierProvider::tryFrom($suppliers->get($purchase->supplier_id)->provider);
            if ($provider === SupplierProvider::BuyMail && $purchase->dispatched_at !== null) {
                $purchase->update(['state' => 'reserved', 'replay_count' => $purchase->replay_count + 1]);
            } elseif ($suppliers->get($purchase->supplier_id)->is_active && $provider?->supportsIdempotency() && $purchase->replay_count === 0 && $purchase->dispatched_at?->gte(now()->subMinutes(10))) {
                $purchase->update(['state' => 'reserved', 'replay_count' => 1]);
            } else {
                $purchase->update(['state' => 'ambiguous', 'last_error' => 'dispatch_outcome_unknown', 'claim_token' => null, 'lease_expires_at' => null]);
                $this->review($f, $order, 'dispatch_outcome_unknown');

                return null;
            }
        }
        $read = $purchase?->supplier_order_id !== null;
        if ($purchase === null) {
            $supplier = $suppliers->get($f->reserved_supplier_id);
            $sku = $catalog->get($f->reserved_catalog_item_id);
            $candidate = collect($f->sources_snapshot)->first(fn ($c) => $c['supplier_id'] === $supplier?->id && $c['catalog_item_id'] === $sku?->id);
            if (! $candidate || ! $supplier->is_active || ! $sku->orderable || $sku->requires_input || $supplier->currency !== $candidate['currency'] || $sku->external_id !== $candidate['external_id']) {
                $this->review($f, $order, 'source_unavailable');

                return null;
            }
            // Never POST a request whose current documented price exceeds its immutable cap.
            $quantity = $f->reserved_quantity;
            $cost = $this->pricing->costMicros($sku, $quantity);
            if ($quantity < 1 || $cost === null || $cost > $f->reserved_cost_micros || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $this->remainingCostLimit($f)) {
                $this->review($f, $order, 'cost_changed');

                return null;
            }
            $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id, 'reference' => (string) Str::uuid(), 'quantity' => $quantity, 'expected_cost_micros' => $f->reserved_cost_micros, 'exchange_rate_idr' => $candidate['exchange_rate_idr'], 'state' => 'reserved']);
            if ($supplier->provider === 'buymail') {
                $purchase->forceFill(['credential_fingerprint' => hash('sha256', $supplier->api_key)])->save();
            }
            $f->update(['current_purchase_id' => $purchase->id]);
        }
        if (! $read && ! in_array($purchase->state, ['reserved'], true)) {
            return null;
        }
        $token = (string) Str::uuid();
        $lease = $suppliers->get($purchase->supplier_id)->provider === 'buymail' ? now()->addMinutes(5) : now()->addMinutes(2);
        $purchase->update(['state' => $read ? 'awaiting_delivery' : 'sending', 'claim_token' => $token, 'lease_expires_at' => $lease, 'dispatched_at' => $purchase->dispatched_at ?? now()]);
        $f->update(['state' => $read ? 'awaiting_delivery' : 'purchasing', 'next_attempt_at' => $lease]);

        return [$purchase, $suppliers->get($purchase->supplier_id), $catalog->get($purchase->supplier_catalog_item_id), $token, $read];
    }

    private function finalize(int $id, int $purchaseId, string $token, SupplierOrderResult $result, bool $read): void
    {
        $initial = SupplierFulfillment::findOrFail($id);
        $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
        Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
        $f = $fulfillments->get($id);
        $purchase = SupplierPurchase::query()->lockForUpdate()->findOrFail($purchaseId);
        if ($purchase->claim_token !== $token || $f->current_purchase_id !== $purchase->id) {
            return;
        }
        $purchase->forceFill(['claim_token' => null, 'lease_expires_at' => null, 'last_error' => $result->errorCode]);
        if ($suppliers->get($purchase->supplier_id)->provider === 'buymail' && ! $result->identityTrusted
            && in_array($result->errorCode, ['transport', 'transport_unavailable', 'invalid_payload', 'idempotency_in_progress', 'order_in_progress', 'reference_already_used'], true)
            && $purchase->replay_count < 5 && $purchase->dispatched_at?->gte(now()->subDays(7)->addMinutes(5))) {
            $purchase->forceFill(['state' => 'sending', 'next_attempt_at' => now()->addMinute()])->save();
            $f->update(['state' => 'purchasing', 'next_attempt_at' => now()->addMinute()]);

            return;
        }
        if ($suppliers->get($purchase->supplier_id)->provider === 'buymail' && $result->identityTrusted && $result->deliveryTrusted) {
            $this->finalizeBuyMail($f, $order, $purchase, $result, $suppliers, $catalog);

            return;
        }
        if ($result->identityTrusted && $result->orderId !== null) {
            $collision = SupplierPurchase::query()->where('supplier_id', $purchase->supplier_id)->where('supplier_order_id', $result->orderId)->where('id', '!=', $purchase->id)->exists();
            if ($collision || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $result->orderId)) {
                $purchase->update(['state' => 'review', 'last_error' => 'order_identity_mismatch']);
                $this->review($f, $order, 'order_identity_mismatch');

                return;
            }
            $purchase->supplier_order_id = $result->orderId;
        }
        $previous = $purchase->delivery_payload ?? [];
        $conflictingDelivery = $previous !== [] && $result->deliveries !== []
            && array_diff($result->deliveries, $previous) !== []
            && array_diff($previous, $result->deliveries) !== [];
        // A subset observation cannot erase acquired units; unrelated sets
        // require review rather than a heuristic union.
        if ($result->deliveries !== [] && ($previous === [] || (! $conflictingDelivery && count($result->deliveries) > count($previous)))) {
            $purchase->delivery_payload = $result->deliveries;
        }
        if ($conflictingDelivery) {
            $purchase->trusted_delivery_payload = null;
        } elseif ($result->identityTrusted && $result->deliveryTrusted && $result->deliveries !== []) {
            $trusted = $purchase->trusted_delivery_payload ?? [];
            if (count($result->deliveries) >= count($trusted)) {
                $purchase->trusted_delivery_payload = $result->deliveries;
            }
        }
        if ($result->identityTrusted && $result->costMicros !== null) {
            $purchase->actual_cost_micros = $result->costMicros;
        }
        if ($result->identityTrusted && $result->quantity !== null) {
            $purchase->observed_quantity = $result->quantity;
        } elseif ($result->identityTrusted && $result->deliveryTrusted && $purchase->observed_quantity === null && count($result->deliveries) === $purchase->quantity) {
            // Complete acquired-unit evidence establishes delivered quantity
            // when the provider omits its redundant numeric field.
            $purchase->observed_quantity = count($result->deliveries);
        }
        if ($result->identityTrusted && $result->deliveryTrusted && $result->accountUnits !== []) {
            $purchase->account_units = $result->accountUnits;
        }
        $deliveries = $purchase->trusted_delivery_payload ?? [];
        $quantityMatches = $purchase->observed_quantity === $purchase->quantity;
        $economicProof = $result->identityTrusted && $purchase->supplier_order_id !== null && $quantityMatches;
        if ($purchase->committed_at === null && $purchase->actual_cost_micros !== null && $economicProof) {
            $this->commitDebit($f, $purchase, $suppliers, $catalog);
        }
        if ($f->state === 'delivered' && $economicProof && $purchase->committed_at !== null && $purchase->actual_cost_micros !== null) {
            // Manual replacement already satisfied customer delivery. Recover
            // only the original acquisition's economics, never reopen delivery
            // or allocate a second set of credentials.
            $purchase->forceFill([
                'state' => count($deliveries) === $purchase->quantity ? 'delivered' : 'settled',
                'next_attempt_at' => null,
                'last_error' => SupplierMoney::costIdr($purchase->actual_cost_micros, $purchase->exchange_rate_idr) > $f->maximum_cost_idr ? 'cost_mismatch' : null,
            ])->save();
            $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());

            return;
        }
        // A GET cannot erase evidence of a POST or prove that an existing order was never debited.
        if ($result->outcome === 'rejected' && ! $read && $purchase->replay_count === 0 && $result->deliveries === [] && $result->orderId === null && ($result->costMicros === null || $result->costMicros === 0) && $purchase->committed_at === null) {
            if ($suppliers->get($purchase->supplier_id)->provider === 'buymail' && $result->errorCode === 'daily_limit_reached') {
                $limited = $suppliers->get($purchase->supplier_id);
                $limited->forceFill(['purchase_paused_until' => now('Asia/Jakarta')->addDay()->startOfDay()->timezone(config('app.timezone')), 'version' => $limited->version + 1])->save();
            }
            $purchase->state = 'rejected';
            $purchase->save();
            $this->unhold($f, $suppliers, $catalog);
            if ($this->fallback($f, $suppliers, $catalog)) {
                $f->update(['state' => 'pending', 'current_purchase_id' => null, 'next_attempt_at' => now()->addSeconds(10)]);
            } else {
                $this->review($f, $order, $result->errorCode ?? 'purchase_rejected');
            }
        } elseif ($result->identityTrusted && $result->deliveryTrusted && ! $conflictingDelivery && $quantityMatches && count($deliveries) === $purchase->quantity && $purchase->committed_at !== null && $order->payment_status === PaymentStatus::Paid && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            $cost = $purchase->actual_cost_micros;
            if ($cost < 0 || SupplierMoney::costIdr($cost, $purchase->exchange_rate_idr) > $f->maximum_cost_idr) {
                $purchase->state = 'review';
                $purchase->save();
                $this->review($f, $order, 'cost_mismatch');

                return;
            }
            $purchase->forceFill(['state' => 'delivered'])->save();
            if ($f->state !== 'delivered') {
                $this->materialize($f, $purchase, $deliveries);
                $this->finish($f, $order);
            }
        } elseif ($result->identityTrusted && $result->deliveryTrusted && ! $conflictingDelivery && $quantityMatches && count($deliveries) === $purchase->quantity && $purchase->actual_cost_micros === null && $purchase->supplier_order_id !== null) {
            // Retain complete delivery evidence, but recover actual debit
            // before releasing its financial hold or completing fulfillment.
            $purchase->forceFill(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()])->save();
            if ($f->state !== 'delivered') {
                $f->update(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()]);
            }
        } elseif (($purchase->delivery_payload ?? []) !== [] || ($purchase->observed_quantity !== null && ! $quantityMatches) || ! $result->identityTrusted) {
            $purchase->state = $purchase->supplier_order_id === null && ($purchase->delivery_payload ?? []) === [] ? 'ambiguous' : 'review';
            $purchase->save();
            $this->review($f, $order, $conflictingDelivery ? 'delivery_evidence_conflict' : ($result->errorCode ?? 'delivery_incomplete'));
        } elseif ($purchase->supplier_order_id !== null) {
            $purchase->forceFill(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()])->save();
            if ($f->state !== 'delivered') {
                $f->update(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()]);
            }
        } else {
            $purchase->state = 'ambiguous';
            $purchase->save();
            $this->review($f, $order, 'purchase_outcome_unknown');
        }
        $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());
    }

    private function fallback(SupplierFulfillment $f, Collection $suppliers, Collection $catalog): bool
    {
        $quantity = $this->outstandingQuantity($f);
        if ($quantity < 1) {
            return false;
        }
        $attempted = SupplierPurchase::query()->where('supplier_fulfillment_id', $f->id)->pluck('supplier_catalog_item_id')->all();
        foreach ($f->sources_snapshot as $candidate) {
            if (in_array($candidate['catalog_item_id'], $attempted, true)) {
                continue;
            }
            $sku = $catalog->get($candidate['catalog_item_id']);
            $supplier = $suppliers->get($candidate['supplier_id']);
            $source = ProductSupplierSource::find($candidate['source_id']);
            $cost = $sku ? $this->pricing->costMicros($sku, $quantity) : null;
            if (! $source?->is_active || ! $supplier?->is_active || ! $supplier->currency_confirmed || ! $sku?->orderable || $sku->requires_input || $sku->external_id !== $candidate['external_id'] || $supplier->currency !== $candidate['currency'] || ! $supplier->synced_at || $supplier->synced_at->lt(now()->subMinutes(5)) || ! $sku->synced_at || $sku->synced_at->lt(now()->subMinutes(5)) || $cost === null || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $this->remainingCostLimit($f) || ($sku->stock ?? 0) - $sku->reserved_quantity < $quantity || ($supplier->balance_micros ?? 0) - $supplier->reserved_balance_micros < $cost) {
                continue;
            }
            $this->hold($f, $supplier, $sku, $cost, $quantity);

            return true;
        }

        return false;
    }

    private function commitDebit(SupplierFulfillment $f, SupplierPurchase $purchase, Collection $suppliers, Collection $catalog): void
    {
        $supplier = $suppliers->get($purchase->supplier_id);
        $sku = $catalog->get($purchase->supplier_catalog_item_id);
        $this->unhold($f, $suppliers, $catalog);
        $supplier->forceFill(['balance_micros' => ($supplier->balance_micros ?? 0) - $purchase->actual_cost_micros, 'version' => $supplier->version + 1])->save();
        $sku->update(['stock' => $sku->stock === null ? null : max(0, $sku->stock - ($purchase->observed_quantity ?? $purchase->quantity))]);
        $purchase->committed_at = now();
    }

    private function hold(SupplierFulfillment $f, Supplier $supplier, SupplierCatalogItem $sku, int $cost, ?int $quantity = null): void
    {
        $quantity ??= $f->quantity;
        $supplier->increment('reserved_balance_micros', $cost);
        $supplier->increment('version');
        $sku->increment('reserved_quantity', $quantity);
        $this->refreshSharedWalletAfterCommit($supplier->id);
        $f->update(['reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id, 'reserved_cost_micros' => $cost, 'reserved_quantity' => $quantity]);
    }

    private function unhold(SupplierFulfillment $f, Collection $suppliers, Collection $catalog): void
    {
        if ($f->reserved_quantity === 0) {
            return;
        }
        $supplier = $suppliers->get($f->reserved_supplier_id);
        $sku = $catalog->get($f->reserved_catalog_item_id);
        if ($supplier === null || $sku === null || $supplier->reserved_balance_micros < $f->reserved_cost_micros || $sku->reserved_quantity < $f->reserved_quantity) {
            throw new InvalidOrderTransition('Fulfillment commitment invariant violated.');
        }
        $supplier->decrement('reserved_balance_micros', $f->reserved_cost_micros);
        $supplier->increment('version');
        $this->refreshSharedWalletAfterCommit($supplier->id);
        $sku->decrement('reserved_quantity', $f->reserved_quantity);
        $f->update(['reserved_cost_micros' => 0, 'reserved_quantity' => 0]);
    }

    private function refreshSharedWalletAfterCommit(int $supplierId): void
    {
        $ids = ProductSupplierSource::query()->whereHas('catalogItem', fn ($q) => $q->where('supplier_id', $supplierId))->pluck('product_id')->unique()->all();
        DB::afterCommit(fn () => $this->availability->refreshProducts($ids));
    }

    private function lockResources(array $catalogIds): array
    {
        $supplierIds = SupplierCatalogItem::query()->whereIn('id', $catalogIds)->pluck('supplier_id');
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $catalog = SupplierCatalogItem::query()->whereIn('id', $catalogIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$suppliers, $catalog];
    }

    private function lockedOrderResources(Order $order): array
    {
        $items = $order->orderItems()->orderBy('product_id')->lockForUpdate()->get();
        Product::withTrashed()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        $initial = SupplierFulfillment::query()->where('order_id', $order->id)->get();
        $catalogIds = [];
        foreach ($initial as $f) {
            foreach ($f->sources_snapshot as $candidate) {
                $catalogIds[] = $candidate['catalog_item_id'];
            }
        }
        // Include every current mapping because coherent availability refresh needs these resources too.
        $catalogIds = array_merge($catalogIds, ProductSupplierSource::query()->whereIn('product_id', $items->pluck('product_id'))->pluck('supplier_catalog_item_id')->all());
        [$suppliers, $catalog] = $this->lockResources($catalogIds);
        $fulfillments = SupplierFulfillment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        SupplierPurchase::query()->whereIn('supplier_fulfillment_id', $fulfillments->keys())->orderBy('id')->lockForUpdate()->get();

        return [$fulfillments, $suppliers, $catalog];
    }

    private function review(SupplierFulfillment $f, Order $order, string $error): void
    {
        $f->update(['state' => $f->state === 'delivered' ? 'delivered' : 'review', 'last_error' => $error, 'next_attempt_at' => null]);
        $payment = $order->payments()->where('status', PaymentStatus::Paid)->first();
        if ($payment !== null) {
            ManualFulfillmentReview::query()->firstOrCreate(['payment_id' => $payment->id], ['order_id' => $order->id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'reason' => 'supplier_fulfillment', 'state' => ManualFulfillmentReview::STATE_PENDING]);
        }
    }

    private function materialize(SupplierFulfillment $f, SupplierPurchase $purchase, array $deliveries, bool $complete = true): void
    {
        $item = OrderItem::findOrFail($f->order_item_id);
        foreach (array_values($deliveries) as $index => $secret) {
            $unit = ($purchase->account_units ?? [])[$index] ?? null;
            $structured = $unit !== null && $secret === $unit['email'].' | '.$unit['password'];
            $attributes = ['product_id' => $item->product_id, 'order_item_id' => $item->id];
            if ($structured) {
                $attributes += [
                    'credential_format' => CredentialFormat::StructuredAccount,
                    'credential_payload' => StructuredCredentialPayload::fromValues($unit['email'], $unit['password'], CredentialAccessMethod::None)->toArray(),
                ];
            } else {
                $attributes['secret'] = $secret;
            }
            $stockItem = ProductStockItem::firstOrCreate(['supplier_purchase_id' => $purchase->id, 'supplier_unit_index' => $index + 1], $attributes);
            if ($structured) {
                $number = SupplierAccountUnit::query()->where('order_item_id', $item->id)->whereNull('replaces_unit_id')->count() + $item->localQuantity() + 1;
                $subtotal = $item->order->currency === 'USDT' ? $item->usdt_subtotal : $item->subtotal;
                SupplierAccountUnit::firstOrCreate(['supplier_id' => $purchase->supplier_id, 'external_id' => $unit['id']], [
                    'public_id' => (string) Str::uuid(), 'supplier_purchase_id' => $purchase->id,
                    'product_stock_item_id' => $stockItem->id, 'order_id' => $item->order_id,
                    'order_item_id' => $item->id, 'provider_state' => $unit['status'],
                    'refund_deadline' => CarbonImmutable::parse($unit['refund_deadline'])->timezone(config('app.timezone')), 'cost_idr' => $unit['price'],
                    'retail_amount' => RefundAllocation::unit($subtotal, $item->quantity, $number),
                    'retail_currency' => $item->order->currency, 'unit_number' => $number,
                ]);
            }
        }
        if ($complete) {
            $f->update(['state' => 'delivered', 'delivered_at' => now(), 'next_attempt_at' => null]);
        }
    }

    private function remainingCostLimit(SupplierFulfillment $f): int
    {
        $spent = 0;
        foreach ($f->purchases()->whereNull('warranty_claim_id')->whereNotNull('committed_at')->get(['actual_cost_micros', 'exchange_rate_idr']) as $purchase) {
            $spent = SupplierMoney::checkedAdd($spent, SupplierMoney::costIdr($purchase->actual_cost_micros, $purchase->exchange_rate_idr));
        }

        return max(0, $f->maximum_cost_idr - $spent);
    }

    private function outstandingQuantity(SupplierFulfillment $f): int
    {
        $acquired = ProductStockItem::query()->where('order_item_id', $f->order_item_id)
            ->whereHas('supplierPurchase', fn ($q) => $q->where('supplier_fulfillment_id', $f->id)->whereNull('warranty_claim_id'))->count();

        return max(0, $f->quantity - $acquired - (int) $f->refunded_quantity);
    }

    public function completeMissingRefund(SupplierFulfillment $initial, OrderRefund $refund): void
    {
        if (DB::transactionLevel() === 0 || $refund->state !== 'confirmed' || (int) $refund->supplier_fulfillment_id !== (int) $initial->id) {
            throw new InvalidOrderTransition('Missing-unit completion requires confirmed refund evidence.');
        }
        $order = Order::query()->whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
        [$fulfillments] = $this->lockedOrderResources($order);
        $f = $fulfillments->get($initial->id);
        if ($f->state === 'delivered' && (int) $f->refunded_quantity === (int) $refund->missing_quantity) {
            return;
        }
        if ($f->state !== 'refund_pending' || $f->reserved_quantity !== 0 || $this->outstandingQuantity($f) !== (int) $refund->missing_quantity) {
            throw new InvalidOrderTransition('Missing-unit refund does not match the remaining fulfillment.');
        }
        $f->forceFill(['state' => 'delivered', 'refunded_quantity' => $refund->missing_quantity, 'next_attempt_at' => null, 'last_error' => 'missing_units_refunded'])->save();
        $payment = Payment::findOrFail($refund->payment_id);
        if ((int) OrderRefund::where('payment_id', $payment->id)->where('state', 'confirmed')->sum('amount') === $payment->amount) {
            app(OrderLifecycle::class)->applyPaymentRefunded($payment);

            return;
        }
        $this->finish($f, $order);
    }

    /** BuyMail terminal partial results prove both delivered quantity and actual debit. */
    private function finalizeBuyMail(SupplierFulfillment $f, Order $order, SupplierPurchase $purchase, SupplierOrderResult $result, Collection $suppliers, Collection $catalog): void
    {
        $supplier = $suppliers->get($purchase->supplier_id);
        $sku = $catalog->get($purchase->supplier_catalog_item_id);
        $collision = SupplierPurchase::query()->where('supplier_id', $purchase->supplier_id)->where('supplier_order_id', $result->orderId)->whereKeyNot($purchase->id)->exists();
        if ($result->orderId === null || $result->requestedQuantity !== $purchase->quantity
            || $result->quantity === null || $result->costMicros === null
            || $result->quantity < 0 || $result->quantity > $purchase->quantity
            || count($result->accountUnits) !== $result->quantity || count($result->deliveries) !== $result->quantity
            || $collision || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $result->orderId)
            || ($purchase->committed_at !== null && ($purchase->actual_cost_micros !== $result->costMicros || $purchase->observed_quantity !== $result->quantity))) {
            $purchase->update(['state' => 'review', 'last_error' => 'order_identity_mismatch']);
            $this->review($f, $order, 'order_identity_mismatch');

            return;
        }
        if (($purchase->account_units ?? []) !== [] && array_column($purchase->account_units, 'id') !== array_column($result->accountUnits, 'id')) {
            $purchase->update(['state' => 'review', 'last_error' => 'delivery_evidence_conflict']);
            $this->review($f, $order, 'delivery_evidence_conflict');

            return;
        }
        foreach ($purchase->account_units ?? [] as $index => $existingUnit) {
            $observedUnit = $result->accountUnits[$index] ?? null;
            if ($observedUnit === null || $existingUnit['email'] !== $observedUnit['email'] || $existingUnit['password'] !== $observedUnit['password'] || $existingUnit['price'] !== $observedUnit['price']) {
                $purchase->update(['state' => 'review', 'last_error' => 'delivery_evidence_conflict']);
                $this->review($f, $order, 'delivery_evidence_conflict');

                return;
            }
        }
        $withinLimit = $purchase->committed_at !== null || SupplierMoney::costIdr($result->costMicros, 1) <= $this->remainingCostLimit($f);
        $purchase->forceFill([
            'supplier_order_id' => $result->orderId, 'observed_quantity' => $result->quantity,
            'actual_cost_micros' => $result->costMicros, 'delivery_payload' => $result->deliveries,
            'trusted_delivery_payload' => $result->deliveries, 'account_units' => $result->accountUnits,
            'state' => $result->outcome === 'partial' ? 'settled' : 'delivered', 'next_attempt_at' => null,
        ]);
        if ($purchase->committed_at === null) {
            $this->commitDebit($f, $purchase, $suppliers, $catalog);
        }
        $purchase->save();
        $reusedUnit = SupplierAccountUnit::query()->where('supplier_id', $purchase->supplier_id)
            ->whereIn('external_id', array_column($result->accountUnits, 'id'))->where('supplier_purchase_id', '!=', $purchase->id)->exists();
        if ($reusedUnit) {
            $this->review($f, $order, 'duplicate_account_identity');

            return;
        }
        if (! $withinLimit) {
            $this->review($f, $order, 'cost_mismatch');

            return;
        }
        if ($f->state === 'delivered') {
            // Manual replacement already satisfied the customer; only settle original economics.
            $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());

            return;
        }
        $this->assertActive($order);
        $this->materialize($f, $purchase, $result->deliveries, false);
        $remaining = $this->outstandingQuantity($f);
        if ($remaining === 0) {
            $f->update(['state' => 'delivered', 'delivered_at' => now(), 'next_attempt_at' => null]);
            $this->finish($f, $order);
        } elseif ($remaining > 0) {
            $cost = $this->pricing->costMicros($sku, $remaining);
            if ($result->quantity > 0 && $f->purchases()->count() < 3 && $cost !== null
                && $supplier->is_active && $supplier->currency_confirmed && $supplier->sync_expires_at?->isFuture()
                && $sku->orderable && ! $sku->requires_input
                && $cost <= max(0, $supplier->balance_micros - $supplier->reserved_balance_micros)
                && $sku->stock - $sku->reserved_quantity >= $remaining
                && SupplierMoney::costIdr($cost, 1) <= $this->remainingCostLimit($f)) {
                $this->hold($f, $supplier, $sku, $cost, $remaining);
                $f->update(['state' => 'pending', 'current_purchase_id' => null, 'next_attempt_at' => now(), 'last_error' => null]);
                DB::afterCommit(fn () => ProcessSupplierFulfillment::dispatch($f->id));
            } else {
                $this->review($f, $order, 'partial_unfulfilled');
            }
        } else {
            throw new InvalidOrderTransition('Supplier quantity invariant violated.');
        }
        $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());
    }

    private function finish(SupplierFulfillment $f, Order $order): void
    {
        if ($this->hasPending($order)) {
            return;
        }
        ManualFulfillmentReview::query()->where('order_id', $order->id)->whereIn('reason', ['supplier_fulfillment', ManualFulfillmentReview::REASON_LATE_PAYMENT])->where('state', ManualFulfillmentReview::STATE_PENDING)->update(['state' => ManualFulfillmentReview::STATE_FULFILLED, 'resolved_at' => now()]);
        $order->update(['order_status' => OrderStatus::Completed]);
        app(GuestOrderNotificationService::class)->record($order, GuestOrderNotificationEvent::FulfillmentCompleted);
        if ((bool) StoreChannelSetting::current()->sales_proof_enabled) {
            $post = SalesProofPost::firstOrCreate(['order_id' => $order->id], ['status' => SalesProofPost::STATUS_PENDING]);
            $effect = app(TelegramOutboundEffectService::class)->recordSalesProofPost($order, $post);
            if ($effect !== null) {
                app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
            }
        }
        DB::afterCommit(fn () => SendTelegramReceipt::dispatch((string) $order->id)->onQueue(TelegramOutboundEffectService::RECEIPT_QUEUE));
    }

    public function retryAfterTopup(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $initial = SupplierFulfillment::findOrFail($id);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
            $f = $fulfillments->get($id);
            $this->assertActive($order);
            if ($f->state !== 'review') {
                throw new InvalidOrderTransition('This fulfillment is not eligible for a new attempt.');
            }
            $purchase = $f->current_purchase_id ? SupplierPurchase::findOrFail($f->current_purchase_id) : null;
            if ($purchase && $purchase->state !== 'rejected') {
                throw new InvalidOrderTransition('An unconfirmed purchase cannot be retried. Recover its matching order first.');
            }
            $this->unhold($f, $suppliers, $catalog);
            // A deliberate operator retry may reuse a proven-rejected source, but never a dispatched intent.
            $candidate = collect($f->sources_snapshot)->first(fn ($c) => $c['catalog_item_id'] === $f->reserved_catalog_item_id);
            if (! $candidate) {
                throw new InvalidOrderTransition('The original source is unavailable.');
            }
            $sku = $catalog->get($candidate['catalog_item_id']);
            $supplier = $suppliers->get($candidate['supplier_id']);
            $quantity = $this->outstandingQuantity($f);
            $cost = $this->pricing->costMicros($sku, $quantity);
            if ($quantity < 1 || ! $supplier->is_active || ! $supplier->currency_confirmed || ! $supplier->sync_expires_at?->isFuture() || ! $sku->orderable || $sku->requires_input || $supplier->currency !== $candidate['currency'] || $sku->external_id !== $candidate['external_id'] || $cost === null || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $this->remainingCostLimit($f) || ($supplier->balance_micros ?? 0) - $supplier->reserved_balance_micros < $cost || ($sku->stock ?? 0) - $sku->reserved_quantity < $quantity) {
                throw new InsufficientStock('The original order cannot currently be fulfilled within its purchase limit.');
            }
            $this->hold($f, $supplier, $sku, $cost, $quantity);
            $f->update(['state' => 'pending', 'current_purchase_id' => null, 'next_attempt_at' => now(), 'last_error' => null]);
            DB::afterCommit(fn () => ProcessSupplierFulfillment::dispatch($id));
        }, 5);
    }

    /** An operator supplies an exact order identity; quantity/cost evidence must match on GET. */
    public function recoverOrder(int $id, string $orderId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidOrderTransition('Recovery transport cannot run inside a transaction.');
        }
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $orderId) !== 1) {
            throw new InvalidOrderTransition('Invalid order identity.');
        }
        $request = DB::transaction(function () use ($id): array {
            $initial = SupplierFulfillment::findOrFail($id);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
            $f = $fulfillments->get($id);
            $this->assertActive($order);
            $purchase = SupplierPurchase::findOrFail($f->current_purchase_id);
            if ($purchase->lease_expires_at?->isFuture() || ! in_array($purchase->state, ['ambiguous', 'review', 'awaiting_delivery'], true)) {
                throw new InvalidOrderTransition('This purchase is not eligible for recovery.');
            }
            $token = (string) Str::uuid();
            $purchase->update(['claim_token' => $token, 'lease_expires_at' => now()->addMinutes(2)]);

            return [$purchase, $suppliers->get($purchase->supplier_id), $token];
        }, 5);
        [$purchase, $supplier, $token] = $request;
        try {
            $result = $this->client->order($supplier, $orderId, $purchase->catalogItem->external_id, $purchase->reference);
        } catch (Throwable) {
            $result = new SupplierOrderResult('ambiguous', null, null, null, [], 'transport_unavailable');
        }
        // Uncorrelated recovery must carry complete matching economic metadata, not name/time heuristics.
        $requestedQuantity = $supplier->provider === 'buymail' ? $result->requestedQuantity : $result->quantity;
        if (($purchase->supplier_order_id === null && ($result->orderId !== $orderId || $requestedQuantity !== $purchase->quantity || $result->costMicros === null)) || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $orderId)) {
            $result = new SupplierOrderResult('ambiguous', null, null, null, [], 'order_identity_mismatch');
        }
        DB::transaction(fn () => $this->finalize($id, $purchase->id, $token, $result, true), 5);
    }

    public function provideManualCredentials(int $id, array $credentials): void
    {
        DB::transaction(function () use ($id, $credentials): void {
            $initial = SupplierFulfillment::findOrFail($id);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
            $f = $fulfillments->get($id);
            $lateManual = $order->payment_status === PaymentStatus::Paid && $f->state === 'cancelled'
                && ManualFulfillmentReview::query()->where('order_id', $order->id)->where('reason', ManualFulfillmentReview::REASON_LATE_PAYMENT)->where('state', ManualFulfillmentReview::STATE_PENDING)->exists();
            if ($lateManual) {
                $ledger = app(InventoryLedger::class);
                [$items, $movements, $products] = $ledger->orderInventoryState($order);
                if (! $ledger->hasSaleProof($items, $movements)) {
                    $ledger->recordLatePaymentSales($order, $items, $products, $movements);
                    foreach ($items as $item) {
                        app(ProductCredentialAllocator::class)->allocate($item, $products->get($item->product_id), $item->localQuantity());
                    }
                }
                $order->update(['order_status' => OrderStatus::Processing]);
            } else {
                $this->assertActive($order);
            }
            $quantity = $this->outstandingQuantity($f);
            if ((! $lateManual && $f->state !== 'review') || $quantity < 1 || count($credentials) !== $quantity || count(array_unique($credentials)) !== $quantity) {
                throw new InvalidOrderTransition('Complete matching manual delivery is required.');
            }
            foreach ($credentials as $secret) {
                if (! is_string($secret) || trim($secret) === '' || strlen($secret) > 16384) {
                    throw new InvalidOrderTransition('Invalid manual delivery.');
                }
            }
            $purchase = $f->current_purchase_id ? SupplierPurchase::findOrFail($f->current_purchase_id) : null;
            if ($purchase?->lease_expires_at?->isFuture()) {
                throw new InvalidOrderTransition('A purchase is still in flight.');
            }
            if ($purchase === null || $purchase->state === 'rejected' || ($quantity < $f->quantity && $purchase->committed_at !== null)) {
                $candidate = collect($f->sources_snapshot)->first(fn ($c) => $c['catalog_item_id'] === $f->reserved_catalog_item_id);
                $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $candidate['supplier_id'], 'supplier_catalog_item_id' => $candidate['catalog_item_id'], 'reference' => (string) Str::uuid(), 'quantity' => $quantity, 'expected_cost_micros' => 0, 'exchange_rate_idr' => $candidate['exchange_rate_idr'], 'state' => 'delivered', 'delivery_payload' => $credentials, 'last_error' => 'manual_delivery']);
                $f->update(['current_purchase_id' => $purchase->id]);
                $this->unhold($f, $suppliers, $catalog);
            }
            if ($purchase->committed_at !== null && $purchase->supplier_order_id !== null
                && $purchase->observed_quantity === $purchase->quantity && $purchase->actual_cost_micros !== null) {
                $purchase->update(['state' => 'settled', 'next_attempt_at' => null]);
            }
            // Manual replacement satisfies customer delivery, not unknown supplier debit. Preserve its hold and refund guard.
            $this->materialize($f, $purchase, $credentials);
            $this->finish($f, $order);
            $this->availability->refreshProducts($order->orderItems()->pluck('product_id')->all());
        }, 5);
    }

    private function assertActive(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::Paid || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            throw new InvalidOrderTransition('Only active paid orders can be fulfilled.');
        }
    }
}
