<?php

namespace App\Services;

/**
 * Result metadata is intentionally credential-free and safe for JSON, flash
 * status, timing, and logs.
 */
final readonly class ProductStockIntakeResult
{
    public function __construct(
        public int $productId,
        public int $movementId,
        public int $itemCount,
        public int $stockBefore,
        public int $stockAfter,
        public int $availableBefore,
        public int $availableAfter,
        public bool $idempotentReplay = false,
    ) {}

    /**
     * @return array{product_id:int, movement_id:int, item_count:int, stock_before:int, stock_after:int, available_before:int, available_after:int, idempotent_replay:bool}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'movement_id' => $this->movementId,
            'item_count' => $this->itemCount,
            'stock_before' => $this->stockBefore,
            'stock_after' => $this->stockAfter,
            'available_before' => $this->availableBefore,
            'available_after' => $this->availableAfter,
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }
}
