<?php

namespace App\Services\Suppliers;

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
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Services\GuestOrderNotificationService;
use App\Services\InventoryLedger;
use App\Services\ProductCredentialAllocator;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
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
            $result = $read ? $this->client->order($supplier, $purchase->supplier_order_id) : $this->client->purchase($supplier, $sku, $purchase->quantity, $purchase->reference);
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
            $this->process($id);
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
        Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        [$fulfillments, $suppliers, $catalog] = $this->lockedOrderResources($order);
        $f = $fulfillments->get($id);
        if ($order->payment_status !== PaymentStatus::Paid || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true) || $f === null || ! in_array($f->state, ['pending', 'purchasing', 'awaiting_delivery'], true)) {
            return null;
        }
        $purchase = $f->current_purchase_id ? SupplierPurchase::query()->lockForUpdate()->findOrFail($f->current_purchase_id) : null;
        if ($purchase?->lease_expires_at?->isFuture()) {
            return null;
        }
        if ($purchase?->state === 'sending' && $purchase->supplier_order_id === null) {
            $provider = SupplierProvider::tryFrom($suppliers->get($purchase->supplier_id)->provider);
            if ($suppliers->get($purchase->supplier_id)->is_active && $provider?->supportsIdempotency() && $purchase->replay_count === 0 && $purchase->dispatched_at?->gte(now()->subMinutes(10))) {
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
            $cost = $this->pricing->costMicros($sku, $f->quantity);
            if ($cost === null || $cost > $f->reserved_cost_micros || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $f->maximum_cost_idr) {
                $this->review($f, $order, 'cost_changed');

                return null;
            }
            $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id, 'reference' => (string) Str::uuid(), 'quantity' => $f->quantity, 'expected_cost_micros' => $f->reserved_cost_micros, 'exchange_rate_idr' => $candidate['exchange_rate_idr'], 'state' => 'reserved']);
            $f->update(['current_purchase_id' => $purchase->id]);
        }
        if (! $read && ! in_array($purchase->state, ['reserved'], true)) {
            return null;
        }
        $token = (string) Str::uuid();
        $purchase->update(['state' => $read ? 'awaiting_delivery' : 'sending', 'claim_token' => $token, 'lease_expires_at' => now()->addMinutes(2), 'dispatched_at' => $purchase->dispatched_at ?? now()]);
        $f->update(['state' => $read ? 'awaiting_delivery' : 'purchasing', 'next_attempt_at' => now()->addMinutes(2)]);

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
        if ($result->orderId !== null) {
            $collision = SupplierPurchase::query()->where('supplier_id', $purchase->supplier_id)->where('supplier_order_id', $result->orderId)->where('id', '!=', $purchase->id)->exists();
            if ($collision || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $result->orderId)) {
                $purchase->update(['state' => 'review', 'last_error' => 'order_identity_mismatch']);
                $this->review($f, $order, 'order_identity_mismatch');

                return;
            }
            $purchase->supplier_order_id = $result->orderId;
        }
        if ($result->deliveries !== []) {
            $purchase->delivery_payload = $result->deliveries;
        }
        if ($result->costMicros !== null) {
            $purchase->actual_cost_micros = $result->costMicros;
        }
        if ($result->quantity !== null) {
            $purchase->observed_quantity = $result->quantity;
        } elseif ($purchase->observed_quantity === null && count($result->deliveries) === $purchase->quantity) {
            // Complete acquired-unit evidence establishes delivered quantity
            // when the provider omits its redundant numeric field.
            $purchase->observed_quantity = count($result->deliveries);
        }
        $deliveries = $purchase->delivery_payload ?? [];
        $quantityMatches = $purchase->observed_quantity === $purchase->quantity;
        if ($purchase->committed_at === null && $purchase->actual_cost_micros !== null
            && ($purchase->supplier_order_id !== null || $result->outcome === 'delivered')
            && $quantityMatches) {
            $this->commitDebit($f, $purchase, $suppliers, $catalog);
        }
        if ($f->state === 'delivered' && $quantityMatches && $purchase->committed_at !== null && $purchase->actual_cost_micros !== null) {
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
        if ($result->outcome === 'rejected' && ! $read && $result->deliveries === [] && $result->orderId === null && ($result->costMicros === null || $result->costMicros === 0) && $purchase->committed_at === null) {
            $purchase->state = 'rejected';
            $purchase->save();
            $this->unhold($f, $suppliers, $catalog);
            if ($this->fallback($f, $suppliers, $catalog)) {
                $f->update(['state' => 'pending', 'current_purchase_id' => null, 'next_attempt_at' => now()->addSeconds(10)]);
            } else {
                $this->review($f, $order, $result->errorCode ?? 'purchase_rejected');
            }
        } elseif ($result->outcome !== 'rejected' && $quantityMatches && count($deliveries) === $purchase->quantity && $purchase->actual_cost_micros !== null && $order->payment_status === PaymentStatus::Paid && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
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
        } elseif ($quantityMatches && count($deliveries) === $purchase->quantity && $purchase->actual_cost_micros === null && $purchase->supplier_order_id !== null) {
            // Retain complete delivery evidence, but recover actual debit
            // before releasing its financial hold or completing fulfillment.
            $purchase->forceFill(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()])->save();
            if ($f->state !== 'delivered') {
                $f->update(['state' => 'awaiting_delivery', 'next_attempt_at' => now()->addMinute()]);
            }
        } elseif ($deliveries !== [] || ($purchase->observed_quantity !== null && ! $quantityMatches)) {
            $purchase->state = 'review';
            $purchase->save();
            $this->review($f, $order, 'delivery_incomplete');
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
        $attempted = SupplierPurchase::query()->where('supplier_fulfillment_id', $f->id)->pluck('supplier_catalog_item_id')->all();
        foreach ($f->sources_snapshot as $candidate) {
            if (in_array($candidate['catalog_item_id'], $attempted, true)) {
                continue;
            }
            $sku = $catalog->get($candidate['catalog_item_id']);
            $supplier = $suppliers->get($candidate['supplier_id']);
            $source = ProductSupplierSource::find($candidate['source_id']);
            $cost = $sku ? $this->pricing->costMicros($sku, $f->quantity) : null;
            if (! $source?->is_active || ! $supplier?->is_active || ! $supplier->currency_confirmed || ! $sku?->orderable || $sku->requires_input || $sku->external_id !== $candidate['external_id'] || $supplier->currency !== $candidate['currency'] || ! $supplier->synced_at || $supplier->synced_at->lt(now()->subMinutes(5)) || ! $sku->synced_at || $sku->synced_at->lt(now()->subMinutes(5)) || $cost === null || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $f->maximum_cost_idr || ($sku->stock ?? 0) - $sku->reserved_quantity < $f->quantity || ($supplier->balance_micros ?? 0) - $supplier->reserved_balance_micros < $cost) {
                continue;
            }
            $this->hold($f, $supplier, $sku, $cost);

            return true;
        }

        return false;
    }

    private function commitDebit(SupplierFulfillment $f, SupplierPurchase $purchase, Collection $suppliers, Collection $catalog): void
    {
        $supplier = $suppliers->get($purchase->supplier_id);
        $sku = $catalog->get($purchase->supplier_catalog_item_id);
        $this->unhold($f, $suppliers, $catalog);
        $supplier->forceFill(['balance_micros' => max(0, ($supplier->balance_micros ?? 0) - $purchase->actual_cost_micros), 'version' => $supplier->version + 1])->save();
        $sku->update(['stock' => $sku->stock === null ? null : max(0, $sku->stock - $purchase->quantity)]);
        $purchase->committed_at = now();
    }

    private function hold(SupplierFulfillment $f, Supplier $supplier, SupplierCatalogItem $sku, int $cost): void
    {
        $supplier->increment('reserved_balance_micros', $cost);
        $supplier->increment('version');
        $sku->increment('reserved_quantity', $f->quantity);
        $this->refreshSharedWalletAfterCommit($supplier->id);
        $f->update(['reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id, 'reserved_cost_micros' => $cost, 'reserved_quantity' => $f->quantity]);
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

    private function materialize(SupplierFulfillment $f, SupplierPurchase $purchase, array $deliveries): void
    {
        $item = OrderItem::findOrFail($f->order_item_id);
        foreach (array_values($deliveries) as $index => $secret) {
            ProductStockItem::firstOrCreate(['supplier_purchase_id' => $purchase->id, 'supplier_unit_index' => $index + 1], ['product_id' => $item->product_id, 'order_item_id' => $item->id, 'secret' => $secret]);
        }
        $f->update(['state' => 'delivered', 'delivered_at' => now(), 'next_attempt_at' => null]);
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
            $cost = $this->pricing->costMicros($sku, $f->quantity);
            if (! $supplier->is_active || ! $supplier->currency_confirmed || ! $supplier->sync_expires_at?->isFuture() || ! $sku->orderable || $sku->requires_input || $supplier->currency !== $candidate['currency'] || $sku->external_id !== $candidate['external_id'] || $cost === null || SupplierMoney::costIdr($cost, $candidate['exchange_rate_idr']) > $f->maximum_cost_idr || ($supplier->balance_micros ?? 0) - $supplier->reserved_balance_micros < $cost || ($sku->stock ?? 0) - $sku->reserved_quantity < $f->quantity) {
                throw new InsufficientStock('The original order cannot currently be fulfilled within its purchase limit.');
            }
            $this->hold($f, $supplier, $sku, $cost);
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
            $result = $this->client->order($supplier, $orderId);
        } catch (Throwable) {
            $result = new SupplierOrderResult('ambiguous', null, null, null, [], 'transport_unavailable');
        }
        // Uncorrelated recovery must carry complete matching economic metadata, not name/time heuristics.
        if (($purchase->supplier_order_id === null && ($result->orderId !== $orderId || $result->quantity !== $purchase->quantity || $result->costMicros === null)) || ($purchase->supplier_order_id !== null && $purchase->supplier_order_id !== $orderId)) {
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
            if ((! $lateManual && $f->state !== 'review') || count($credentials) !== $f->quantity || count(array_unique($credentials)) !== $f->quantity) {
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
            if ($purchase === null || $purchase->state === 'rejected') {
                $candidate = collect($f->sources_snapshot)->first(fn ($c) => $c['catalog_item_id'] === $f->reserved_catalog_item_id);
                $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $candidate['supplier_id'], 'supplier_catalog_item_id' => $candidate['catalog_item_id'], 'reference' => (string) Str::uuid(), 'quantity' => $f->quantity, 'expected_cost_micros' => 0, 'exchange_rate_idr' => $candidate['exchange_rate_idr'], 'state' => 'delivered', 'delivery_payload' => $credentials, 'last_error' => 'manual_delivery']);
                $f->update(['current_purchase_id' => $purchase->id]);
                $this->unhold($f, $suppliers, $catalog);
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
