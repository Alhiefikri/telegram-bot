<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockAdjustmentService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function adjust(Product $product, int $delta, ?string $notes = null, ?User $user = null, ?string $ip = null): Product
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('Stock adjustment quantity must not be zero.');
        }

        return DB::transaction(function () use ($product, $delta, $notes, $user, $ip) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $old = ['stock' => $locked->stock, 'reserved_stock' => $locked->reserved_stock];
            $newStock = $locked->stock + $delta;

            if ($newStock < 0 || $newStock < $locked->reserved_stock) {
                throw new InvalidArgumentException('Resulting stock must be non-negative and at least reserved stock.');
            }

            $locked->forceFill(['stock' => $newStock])->save();

            InventoryMovement::create([
                'product_id' => $locked->id,
                'type' => InventoryMovementType::Adjustment,
                'quantity' => $delta,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $this->auditLogger->log('product.stock_adjusted', $locked, $old, ['stock' => $newStock, 'reserved_stock' => $locked->reserved_stock, 'delta' => $delta, 'notes' => $notes], $user, $ip);

            return $locked->refresh();
        });
    }
}
