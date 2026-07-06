<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockAdjustmentService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<string>  $stockItems
     */
    public function adjust(Product $product, int $delta, ?string $notes = null, ?User $user = null, ?string $ip = null, array $stockItems = []): Product
    {
        return $this->adjustWithAvailability($product, $delta, $notes, $user, $ip, $stockItems)['product'];
    }

    /**
     * @param  list<string>  $stockItems
     * @return array{product: Product, available_before: int, available_after: int}
     */
    public function adjustWithAvailability(Product $product, int $delta, ?string $notes = null, ?User $user = null, ?string $ip = null, array $stockItems = []): array
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('Stock adjustment quantity must not be zero.');
        }
        if ($stockItems !== [] && ($delta < 1 || count($stockItems) !== $delta)) {
            throw new InvalidArgumentException('Stock item count must match positive stock adjustment.');
        }

        return DB::transaction(function () use ($product, $delta, $notes, $user, $ip, $stockItems) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $old = ['stock' => $locked->stock, 'reserved_stock' => $locked->reserved_stock];
            if ($locked->isCredentialManaged() && $stockItems === []) {
                throw new InvalidArgumentException('Credential-managed products must be adjusted through matching stock item rows.');
            }
            $availableBefore = $locked->availableStock();
            $newStock = $locked->stock + $delta;

            if ($newStock < 0 || $newStock < $locked->reserved_stock) {
                throw new InvalidArgumentException('Resulting stock must be non-negative and at least reserved stock.');
            }

            $locked->forceFill(['stock' => $newStock])->save();

            foreach ($stockItems as $secret) {
                ProductStockItem::create([
                    'product_id' => $locked->id,
                    'secret' => $secret,
                ]);
            }

            InventoryMovement::create([
                'product_id' => $locked->id,
                'type' => InventoryMovementType::Adjustment,
                'quantity' => $delta,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $this->auditLogger->log('product.stock_adjusted', $locked, $old, ['stock' => $newStock, 'reserved_stock' => $locked->reserved_stock, 'delta' => $delta, 'notes' => $notes], $user, $ip);

            $adjusted = $locked->refresh();

            return [
                'product' => $adjusted,
                'available_before' => $availableBefore,
                'available_after' => $adjusted->availableStock(),
            ];
        });
    }
}
