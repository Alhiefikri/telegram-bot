<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductStockItemManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function updateAvailableSecret(Product $product, ProductStockItem $stockItem, string $secret, User $user, ?string $ip = null): ProductStockItem
    {
        return DB::transaction(function () use ($product, $stockItem, $secret, $user, $ip): ProductStockItem {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ProductStockItem $lockedItem */
            $lockedItem = ProductStockItem::query()
                ->whereKey($stockItem->getKey())
                ->where('product_id', $lockedProduct->id)
                ->whereNull('order_item_id')
                ->lockForUpdate()
                ->firstOrFail();

            $lockedItem->forceFill(['secret' => $secret])->save();

            $this->auditLogger->log(
                'product_stock_item.updated',
                $lockedItem,
                ['product_id' => $lockedProduct->id, 'stock_item_id' => $lockedItem->id, 'secret_present' => true],
                ['product_id' => $lockedProduct->id, 'stock_item_id' => $lockedItem->id, 'secret_changed' => true],
                $user,
                $ip,
            );

            return $lockedItem->refresh();
        });
    }

    public function deleteAvailable(Product $product, ProductStockItem $stockItem, User $user, ?string $ip = null): Product
    {
        return DB::transaction(function () use ($product, $stockItem, $user, $ip): Product {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ProductStockItem $lockedItem */
            $lockedItem = ProductStockItem::query()
                ->whereKey($stockItem->getKey())
                ->where('product_id', $lockedProduct->id)
                ->whereNull('order_item_id')
                ->lockForUpdate()
                ->firstOrFail();

            $old = ['stock' => $lockedProduct->stock, 'reserved_stock' => $lockedProduct->reserved_stock, 'stock_item_id' => $lockedItem->id];
            $newStock = $lockedProduct->stock - 1;

            if ($newStock < $lockedProduct->reserved_stock) {
                throw ValidationException::withMessages([
                    'stock_item' => 'Stok ini tidak bisa dihapus karena masih ada pesanan yang sedang dipesan.',
                ]);
            }

            $lockedItem->delete();
            $lockedProduct->forceFill(['stock' => $newStock])->save();

            InventoryMovement::create([
                'product_id' => $lockedProduct->id,
                'type' => InventoryMovementType::Adjustment,
                'quantity' => -1,
                'notes' => 'Hapus stok manual dashboard (1 item).',
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                'product_stock_item.deleted',
                $lockedProduct,
                $old,
                ['stock' => $newStock, 'reserved_stock' => $lockedProduct->reserved_stock, 'stock_item_id' => $lockedItem->id, 'delta' => -1],
                $user,
                $ip,
            );

            return $lockedProduct->refresh();
        });
    }
}
