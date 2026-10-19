<?php

namespace App\Services\Warranty;

use App\Exceptions\InvalidOrderTransition;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductStockItem;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\WarrantyClaim;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Support\Facades\DB;

/** Locks historical claim entitlements for financial refund authorization. */
final class WarrantyClaimService
{
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
}
